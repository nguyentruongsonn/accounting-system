<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Item;
use App\Models\OpeningBalancePackage;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\DecimalMoney;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class OpeningBalanceService
{
    public function __construct(
        private readonly AccountUsageGuard $accountUsageGuard,
        private readonly AuditService $auditService,
    ) {}

    public function list(int $companyId): Collection
    {
        return OpeningBalancePackage::withoutGlobalScope('company')
            ->where('company_id', $companyId)->with(['accountLines', 'partyLines', 'inventoryLines'])
            ->orderByDesc('effective_date')->get();
    }

    /** @param array<string,mixed> $data */
    public function create(int $companyId, int $actorId, array $data): OpeningBalancePackage
    {
        return DB::transaction(function () use ($companyId, $actorId, $data): OpeningBalancePackage {
            if (OpeningBalancePackage::withoutGlobalScope('company')->where('company_id', $companyId)->lockForUpdate()->exists()) {
                throw new ConflictHttpException('Doanh nghiệp đã có bộ số dư đầu kỳ. Hãy sửa bản nháp hiện tại thay vì tạo bộ thứ hai.');
            }
            $package = OpeningBalancePackage::withoutGlobalScope('company')->create([
                'company_id' => $companyId, 'effective_date' => $data['effective_date'],
                'status' => 'draft', 'created_by' => $actorId,
            ]);
            $this->replaceLines($package, $data);
            $this->auditService->record($package, 'opening_balance.created', [], $package->fresh()->toArray());
            return $this->load($package);
        });
    }

    /** @param array<string,mixed> $data */
    public function update(int $companyId, int $id, array $data): OpeningBalancePackage
    {
        return DB::transaction(function () use ($companyId, $id, $data): OpeningBalancePackage {
            $package = $this->locked($companyId, $id);
            if ($package->status !== 'draft') {
                throw new ConflictHttpException('Số dư đầu kỳ đã xác nhận không được sửa trực tiếp.');
            }
            $before = $this->load($package)->toArray();
            if (isset($data['effective_date'])) {
                $package->forceFill(['effective_date' => $data['effective_date']])->save();
            }
            $this->replaceLines($package, $data);
            $this->auditService->record($package, 'opening_balance.updated', $before, $this->load($package)->toArray());
            return $this->load($package);
        });
    }

    public function confirm(int $companyId, int $id, int $actorId): OpeningBalancePackage
    {
        return DB::transaction(function () use ($companyId, $id, $actorId): OpeningBalancePackage {
            $package = $this->locked($companyId, $id);
            if ($package->status === 'confirmed') {
                return $this->withReconciliation($this->load($package));
            }
            $package = $this->loadLockedLines($package);
            $reconciliation = $this->reconcile($package);
            if (! $reconciliation['balanced']) {
                throw ValidationException::withMessages([
                    'reconciliation' => implode(' ', $reconciliation['errors']),
                ]);
            }
            $before = $package->toArray();
            $package->forceFill([
                'status' => 'confirmed', 'confirmed_by' => $actorId, 'confirmed_at' => now(),
            ])->save();
            $this->auditService->record($package, 'opening_balance.confirmed', $before, $package->fresh()->toArray(), null, [
                'reconciliation' => $reconciliation,
            ]);
            return $this->withReconciliation($this->load($package), $reconciliation);
        });
    }

    /** @param array<string,mixed> $data */
    private function replaceLines(OpeningBalancePackage $package, array $data): void
    {
        $codes = collect($data['account_lines'])->pluck('account_code')
            ->merge(collect($data['party_lines'] ?? [])->pluck('account_code'))
            ->merge(collect($data['inventory_lines'] ?? [])->pluck('account_code'))->all();
        $this->accountUsageGuard->lockActiveLeafAccounts((int) $package->company_id, $codes);
        $this->assertOwnedReferences((int) $package->company_id, $data);

        $package->accountLines()->delete();
        $package->partyLines()->delete();
        $package->inventoryLines()->delete();
        foreach ($data['account_lines'] as $line) {
            $package->accountLines()->create($line);
        }
        foreach ($data['party_lines'] ?? [] as $line) {
            $package->partyLines()->create($line);
        }
        foreach ($data['inventory_lines'] ?? [] as $line) {
            $expected = BigDecimal::of((string) $line['quantity'])
                ->multipliedBy(BigDecimal::of((string) $line['unit_cost']))
                ->toScale(2, RoundingMode::HALF_UP);
            if (! $expected->isEqualTo(BigDecimal::of((string) $line['total_value']))) {
                throw ValidationException::withMessages([
                    'inventory_lines' => 'Giá trị tồn đầu phải bằng số lượng nhân đơn giá.',
                ]);
            }
            $package->inventoryLines()->create($line);
        }
    }

    /** @param array<string,mixed> $data */
    private function assertOwnedReferences(int $companyId, array $data): void
    {
        foreach ($data['party_lines'] ?? [] as $index => $line) {
            $model = $line['party_type'] === 'customer' ? Customer::class : Supplier::class;
            if (! $model::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($line['party_id'])->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(["party_lines.{$index}.party_id" => 'Đối tượng không tồn tại, ngừng sử dụng hoặc không thuộc doanh nghiệp.']);
            }
        }
        foreach ($data['inventory_lines'] ?? [] as $index => $line) {
            if (! Item::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($line['item_id'])->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(["inventory_lines.{$index}.item_id" => 'Hàng hóa không tồn tại, ngừng sử dụng hoặc không thuộc doanh nghiệp.']);
            }
            if (! Warehouse::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($line['warehouse_id'])->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(["inventory_lines.{$index}.warehouse_id" => 'Kho không tồn tại, ngừng sử dụng hoặc không thuộc doanh nghiệp.']);
            }
        }
    }

    /** @return array{balanced:bool,total_debit:string,total_credit:string,errors:list<string>} */
    private function reconcile(OpeningBalancePackage $package): array
    {
        $debit = DecimalMoney::sum($package->accountLines->pluck('debit_amount'));
        $credit = DecimalMoney::sum($package->accountLines->pluck('credit_amount'));
        $errors = [];
        if (DecimalMoney::compare($debit, $credit) !== 0) {
            $errors[] = "Tổng Nợ {$debit} không bằng tổng Có {$credit}.";
        }

        $accountNets = [];
        foreach ($package->accountLines as $line) {
            $hasDebit = DecimalMoney::compare($line->debit_amount, DecimalMoney::ZERO) > 0;
            $hasCredit = DecimalMoney::compare($line->credit_amount, DecimalMoney::ZERO) > 0;
            if ($hasDebit === $hasCredit) {
                $errors[] = "Tài khoản {$line->account_code} phải có đúng một bên Nợ hoặc Có lớn hơn 0.";
            }
            $accountNets[$line->account_code] = DecimalMoney::subtract($line->debit_amount, $line->credit_amount);
        }
        $detailNets = [];
        foreach ($package->partyLines as $line) {
            $hasDebit = DecimalMoney::compare($line->debit_amount, DecimalMoney::ZERO) > 0;
            $hasCredit = DecimalMoney::compare($line->credit_amount, DecimalMoney::ZERO) > 0;
            if ($hasDebit === $hasCredit) {
                $errors[] = "Chi tiết công nợ tài khoản {$line->account_code} phải có đúng một bên Nợ hoặc Có lớn hơn 0.";
            }
            $detailNets[$line->account_code] = DecimalMoney::add(
                $detailNets[$line->account_code] ?? DecimalMoney::ZERO,
                DecimalMoney::subtract($line->debit_amount, $line->credit_amount),
            );
        }
        foreach ($package->inventoryLines as $line) {
            $detailNets[$line->account_code] = DecimalMoney::add(
                $detailNets[$line->account_code] ?? DecimalMoney::ZERO,
                $line->total_value,
            );
        }
        foreach ($detailNets as $code => $net) {
            if (! isset($accountNets[$code]) || DecimalMoney::compare($accountNets[$code], $net) !== 0) {
                $errors[] = "Chi tiết tài khoản {$code} ({$net}) không khớp số dư tổng hợp (".($accountNets[$code] ?? 'không có').').';
            }
        }
        foreach ($accountNets as $code => $net) {
            $requiresPartyDetail = str_starts_with($code, '131') || str_starts_with($code, '331');
            $requiresInventoryDetail = collect(['152', '153', '155', '156'])->contains(
                fn (string $prefix): bool => str_starts_with($code, $prefix)
            );
            if (($requiresPartyDetail || $requiresInventoryDetail) && ! array_key_exists($code, $detailNets)) {
                $errors[] = "Tài khoản {$code} có số dư {$net} nhưng chưa có chi tiết công nợ hoặc tồn kho tương ứng.";
            }
        }

        return ['balanced' => $errors === [], 'total_debit' => $debit, 'total_credit' => $credit, 'errors' => $errors];
    }

    private function locked(int $companyId, int $id): OpeningBalancePackage
    {
        return OpeningBalancePackage::withoutGlobalScope('company')->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
    }

    private function load(OpeningBalancePackage $package): OpeningBalancePackage
    {
        return $package->fresh(['accountLines', 'partyLines', 'inventoryLines']);
    }

    private function loadLockedLines(OpeningBalancePackage $package): OpeningBalancePackage
    {
        $package->setRelation('accountLines', $package->accountLines()->lockForUpdate()->get());
        $package->setRelation('partyLines', $package->partyLines()->lockForUpdate()->get());
        $package->setRelation('inventoryLines', $package->inventoryLines()->lockForUpdate()->get());
        return $package;
    }

    /** @param array<string,mixed>|null $reconciliation */
    private function withReconciliation(OpeningBalancePackage $package, ?array $reconciliation = null): OpeningBalancePackage
    {
        $package->setAttribute('reconciliation', $reconciliation ?? $this->reconcile($package));
        return $package;
    }
}
