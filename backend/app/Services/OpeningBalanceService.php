<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\FixedAsset;
use App\Models\Item;
use App\Models\OpeningBalancePackage;
use App\Models\Period;
use App\Models\Supplier;
use App\Models\ToolEquipment;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\DecimalMoney;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class OpeningBalanceService
{
    public function __construct(
        private readonly AccountUsageGuard $accountUsageGuard,
        private readonly AuditService $auditService,
        private readonly AccountingPeriodGuard $periodGuard,
    ) {}

    public function list(int $companyId, ?string $effectiveDate = null): Collection
    {
        $query = OpeningBalancePackage::withoutGlobalScope('company')
            ->where('company_id', $companyId)->with($this->lineRelations());
        if ($effectiveDate !== null) {
            $query->whereDate('effective_date', $effectiveDate);
        }

        return $query
            ->orderByDesc('effective_date')->get()
            ->each(fn (OpeningBalancePackage $package) => $this->withReconciliation($package));
    }

    /** @param array<string,mixed> $data */
    public function create(int $companyId, int $actorId, array $data): OpeningBalancePackage
    {
        return DB::transaction(function () use ($companyId, $actorId, $data): OpeningBalancePackage {
            $this->periodGuard->assertOpen($companyId, $data['effective_date'], 'tạo số dư đầu kỳ');
            if (OpeningBalancePackage::withoutGlobalScope('company')->where('company_id', $companyId)->lockForUpdate()->exists()) {
                throw new ConflictHttpException('Doanh nghiệp đã có bộ số dư đầu kỳ. Hãy sửa bản nháp hiện tại thay vì tạo bộ thứ hai.');
            }
            $package = OpeningBalancePackage::withoutGlobalScope('company')->create([
                'company_id' => $companyId, 'effective_date' => $data['effective_date'],
                'status' => 'draft', 'created_by' => $actorId,
            ]);
            $this->replaceLines($package, $data);
            $this->auditService->record($package, 'opening_balance.created', [], $package->fresh()->toArray());
            return $this->withReconciliation($this->load($package));
        });
    }

    /** @param array<string,mixed> $data */
    public function update(int $companyId, int $id, array $data): OpeningBalancePackage
    {
        return DB::transaction(function () use ($companyId, $id, $data): OpeningBalancePackage {
            $package = $this->locked($companyId, $id);
            $this->periodGuard->assertOpen($companyId, $data['effective_date'] ?? $package->effective_date, 'sửa số dư đầu kỳ');
            $before = $this->load($package)->toArray();
            if (isset($data['effective_date'])) {
                $package->forceFill(['effective_date' => $data['effective_date']])->save();
            }
            $this->replaceLines($package, $data);
            $this->auditService->record($package, 'opening_balance.updated', $before, $this->load($package)->toArray());
            return $this->withReconciliation($this->load($package));
        });
    }

    public function confirm(int $companyId, int $id, int $actorId): OpeningBalancePackage
    {
        return DB::transaction(function () use ($companyId, $id, $actorId): OpeningBalancePackage {
            $package = $this->locked($companyId, $id);
            if ($package->status === 'confirmed') {
                return $this->withReconciliation($this->load($package));
            }
            $this->periodGuard->assertOpen($companyId, $package->effective_date, 'xác nhận số dư đầu kỳ');
            $package = $this->loadLockedLines($package);
            $reconciliation = $this->reconcile($package);
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

    public function reopen(int $companyId, int $id, int $actorId, string $reason): OpeningBalancePackage
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Lý do mở lại số dư đầu kỳ là bắt buộc.']);
        }

        $actor = User::query()->where('company_id', $companyId)->findOrFail($actorId);
        if (! $actor->hasRole('admin')) {
            throw new AuthorizationException('Chỉ admin được mở lại số dư đầu kỳ.');
        }

        return DB::transaction(function () use ($companyId, $id, $actorId, $reason): OpeningBalancePackage {
            $package = $this->locked($companyId, $id);
            if ($package->status !== 'confirmed') {
                throw new ConflictHttpException('Chỉ số dư đầu kỳ đã xác nhận mới được mở lại.');
            }

            $before = $this->load($package)->toArray();
            $package->forceFill([
                'status' => 'draft',
                'confirmed_by' => null,
                'confirmed_at' => null,
            ])->save();
            $this->auditService->record($package, 'opening_balance.reopened', $before, $this->load($package)->toArray(), $reason, [
                'reopened_by' => $actorId,
            ]);

            return $this->withReconciliation($this->load($package));
        });
    }

    /** @param array<string,mixed> $data */
    private function replaceLines(OpeningBalancePackage $package, array $data): void
    {
        $this->assertPositiveOpeningBalanceSides($data);
        $this->assertUniqueDetailReferences($data);
        $codes = collect($data['account_lines'])->pluck('account_code')
            ->merge(collect($data['party_lines'] ?? [])->pluck('account_code'))
            ->merge(collect($data['inventory_lines'] ?? [])->pluck('account_code'))
            ->merge(collect($data['tool_lines'] ?? [])->pluck('account_code'))
            ->merge(collect($data['fixed_asset_lines'] ?? [])->pluck('asset_account'))
            ->merge(collect($data['fixed_asset_lines'] ?? [])->pluck('depreciation_account'))
            ->merge(collect($data['prepaid_lines'] ?? [])->pluck('account_code'))
            ->merge(collect($data['wip_lines'] ?? [])->pluck('account_code'))->all();
        $this->accountUsageGuard->lockActiveLeafAccounts((int) $package->company_id, $codes);
        $this->assertOwnedReferences((int) $package->company_id, $data);

        $package->accountLines()->delete();
        $package->partyLines()->delete();
        $package->inventoryLines()->delete();
        $package->toolLines()->delete();
        $package->fixedAssetLines()->delete();
        $package->prepaidLines()->delete();
        $package->wipLines()->delete();
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
        foreach ($data['tool_lines'] ?? [] as $line) {
            $expected = BigDecimal::of((string) $line['original_cost'])
                ->minus(BigDecimal::of((string) $line['accumulated_allocation']))
                ->toScale(2, RoundingMode::HALF_UP);
            if ($expected->isNegative() || ! $expected->isEqualTo(BigDecimal::of((string) $line['remaining_value']))) {
                throw ValidationException::withMessages([
                    'tool_lines' => 'Giá trị CCDC còn lại phải bằng nguyên giá trừ lũy kế đã phân bổ.',
                ]);
            }
            $package->toolLines()->create($line);
        }
        foreach ($data['fixed_asset_lines'] ?? [] as $line) {
            if (BigDecimal::of((string) $line['original_cost'])->isNegative()
                || BigDecimal::of((string) $line['accumulated_depreciation'])->isNegative()
                || BigDecimal::of((string) $line['accumulated_depreciation'])->isGreaterThan(BigDecimal::of((string) $line['original_cost']))) {
                throw ValidationException::withMessages([
                    'fixed_asset_lines' => 'Hao mòn lũy kế không được lớn hơn nguyên giá tài sản.',
                ]);
            }
            $package->fixedAssetLines()->create($line);
        }
        foreach ($data['prepaid_lines'] ?? [] as $line) {
            $expected = BigDecimal::of((string) $line['original_amount'])
                ->minus(BigDecimal::of((string) $line['allocated_amount']))
                ->toScale(2, RoundingMode::HALF_UP);
            if ($expected->isNegative() || ! $expected->isEqualTo(BigDecimal::of((string) $line['remaining_amount']))) {
                throw ValidationException::withMessages([
                    'prepaid_lines' => 'Chi phí trả trước còn lại phải bằng nguyên giá trừ đã phân bổ.',
                ]);
            }
            $package->prepaidLines()->create($line);
        }
        foreach ($data['wip_lines'] ?? [] as $line) {
            $package->wipLines()->create($line);
        }
    }

    /** @param array<string,mixed> $data */
    private function assertPositiveOpeningBalanceSides(array $data): void
    {
        foreach ($data['account_lines'] ?? [] as $index => $line) {
            $this->assertExactlyOnePositiveSide($line, "account_lines.{$index}.debit_amount", "Tài khoản {$line['account_code']}" );
        }
        foreach ($data['party_lines'] ?? [] as $index => $line) {
            $this->assertExactlyOnePositiveSide($line, "party_lines.{$index}.debit_amount", "Chi tiết công nợ tài khoản {$line['account_code']}" );
        }
    }

    /** @param array<string,mixed> $data */
    private function assertUniqueDetailReferences(array $data): void
    {
        $this->assertUniqueKeys(
            $data['party_lines'] ?? [],
            static fn (array $line): string => implode('|', [
                (string) ($line['party_type'] ?? ''),
                (string) ($line['party_id'] ?? ''),
                (string) ($line['account_code'] ?? ''),
            ]),
            'party_lines',
            'Không được nhập trùng đối tượng trong cùng tài khoản ở số dư đầu kỳ.',
        );
        $this->assertUniqueKeys(
            $data['inventory_lines'] ?? [],
            static fn (array $line): string => implode('|', [(string) ($line['item_id'] ?? ''), (string) ($line['warehouse_id'] ?? '')]),
            'inventory_lines',
            'Không được nhập trùng hàng hóa trong cùng một kho ở số dư đầu kỳ.',
        );
        $this->assertUniqueKeys(
            array_values(array_filter($data['tool_lines'] ?? [], static fn (array $line): bool => ($line['tool_equipment_id'] ?? null) !== null)),
            static fn (array $line): string => (string) $line['tool_equipment_id'],
            'tool_lines',
            'Không được nhập trùng công cụ dụng cụ trong số dư đầu kỳ.',
        );
        $this->assertUniqueKeys(
            array_values(array_filter($data['fixed_asset_lines'] ?? [], static fn (array $line): bool => ($line['fixed_asset_id'] ?? null) !== null)),
            static fn (array $line): string => (string) $line['fixed_asset_id'],
            'fixed_asset_lines',
            'Không được nhập trùng tài sản cố định trong số dư đầu kỳ.',
        );
    }

    /** @param list<array<string,mixed>> $lines */
    private function assertUniqueKeys(array $lines, callable $keyOf, string $field, string $message): void
    {
        $keys = [];
        foreach ($lines as $line) {
            $key = $keyOf($line);
            if (isset($keys[$key])) {
                throw ValidationException::withMessages([$field => $message]);
            }
            $keys[$key] = true;
        }
    }

    /** @param array<string,mixed> $line */
    private function assertExactlyOnePositiveSide(array $line, string $field, string $label): void
    {
        $hasDebit = DecimalMoney::compare($line['debit_amount'], DecimalMoney::ZERO) > 0;
        $hasCredit = DecimalMoney::compare($line['credit_amount'], DecimalMoney::ZERO) > 0;
        if ($hasDebit === $hasCredit) {
            throw ValidationException::withMessages([
                $field => "{$label} phải có đúng một bên Nợ hoặc Có lớn hơn 0. Nếu không có số dư thực tế, hãy xóa dòng.",
            ]);
        }
    }

    /** @param array<string,mixed> $data */
    private function assertOwnedReferences(int $companyId, array $data): void
    {
        foreach ($data['party_lines'] ?? [] as $index => $line) {
            $model = match ($line['party_type']) {
                'customer' => Customer::class,
                'supplier' => Supplier::class,
                'employee' => Employee::class,
            };
            $query = $model::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($line['party_id']);
            $query = $line['party_type'] === 'employee' ? $query->where('status', 'active') : $query->where('is_active', true);
            if (! $query->exists()) {
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
        foreach ($data['tool_lines'] ?? [] as $index => $line) {
            if ($line['tool_equipment_id'] !== null
                && ! ToolEquipment::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($line['tool_equipment_id'])->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(["tool_lines.{$index}.tool_equipment_id" => 'CCDC không tồn tại, ngừng sử dụng hoặc không thuộc doanh nghiệp.']);
            }
        }
        foreach ($data['fixed_asset_lines'] ?? [] as $index => $line) {
            if ($line['fixed_asset_id'] !== null
                && ! FixedAsset::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($line['fixed_asset_id'])->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(["fixed_asset_lines.{$index}.fixed_asset_id" => 'Tài sản cố định không tồn tại, ngừng sử dụng hoặc không thuộc doanh nghiệp.']);
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
            $detailNets[$line->account_code] = DecimalMoney::add($detailNets[$line->account_code] ?? DecimalMoney::ZERO, $line->total_value);
        }
        foreach ($package->toolLines as $line) {
            $detailNets[$line->account_code] = DecimalMoney::add($detailNets[$line->account_code] ?? DecimalMoney::ZERO, $line->remaining_value);
        }
        foreach ($package->fixedAssetLines as $line) {
            $detailNets[$line->asset_account] = DecimalMoney::add($detailNets[$line->asset_account] ?? DecimalMoney::ZERO, $line->original_cost);
            $detailNets[$line->depreciation_account] = DecimalMoney::subtract($detailNets[$line->depreciation_account] ?? DecimalMoney::ZERO, $line->accumulated_depreciation);
        }
        foreach ($package->prepaidLines as $line) {
            $detailNets[$line->account_code] = DecimalMoney::add($detailNets[$line->account_code] ?? DecimalMoney::ZERO, $line->remaining_amount);
        }
        foreach ($package->wipLines as $line) {
            $detailNets[$line->account_code] = DecimalMoney::add($detailNets[$line->account_code] ?? DecimalMoney::ZERO, $line->amount);
        }
        foreach ($detailNets as $code => $net) {
            if (! isset($accountNets[$code]) || DecimalMoney::compare($accountNets[$code], $net) !== 0) {
                $errors[] = "Chi tiết tài khoản {$code} ({$net}) không khớp số dư tổng hợp (".($accountNets[$code] ?? 'không có').').';
            }
        }
        foreach ($accountNets as $code => $net) {
            $requiresPartyDetail = str_starts_with($code, '131') || str_starts_with($code, '331') || str_starts_with($code, '141') || str_starts_with($code, '334');
            $requiresInventoryDetail = collect(['152', '153', '155', '156'])->contains(
                fn (string $prefix): bool => str_starts_with($code, $prefix)
            );
            $requiresExtendedDetail = str_starts_with($code, '211') || str_starts_with($code, '214') || str_starts_with($code, '242') || str_starts_with($code, '154');
            if (($requiresPartyDetail || $requiresInventoryDetail || $requiresExtendedDetail) && ! array_key_exists($code, $detailNets)) {
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
        return $package->fresh($this->lineRelations());
    }

    private function loadLockedLines(OpeningBalancePackage $package): OpeningBalancePackage
    {
        $package->setRelation('accountLines', $package->accountLines()->lockForUpdate()->get());
        $package->setRelation('partyLines', $package->partyLines()->lockForUpdate()->get());
        $package->setRelation('inventoryLines', $package->inventoryLines()->lockForUpdate()->get());
        $package->setRelation('toolLines', $package->toolLines()->lockForUpdate()->get());
        $package->setRelation('fixedAssetLines', $package->fixedAssetLines()->lockForUpdate()->get());
        $package->setRelation('prepaidLines', $package->prepaidLines()->lockForUpdate()->get());
        $package->setRelation('wipLines', $package->wipLines()->lockForUpdate()->get());
        return $package;
    }

    /** @param array<string,mixed>|null $reconciliation */
    private function withReconciliation(OpeningBalancePackage $package, ?array $reconciliation = null): OpeningBalancePackage
    {
        $package->setAttribute('reconciliation', $reconciliation ?? $this->reconcile($package));
        $package->setAttribute('period_locked', $this->periodIsClosed((int) $package->company_id, $package->effective_date));
        return $package;
    }

    private function periodIsClosed(int $companyId, mixed $effectiveDate): bool
    {
        return Period::query()
            ->whereHas('fiscalYear', fn ($query) => $query->where('company_id', $companyId))
            ->whereDate('start_date', '<=', $effectiveDate)
            ->whereDate('end_date', '>=', $effectiveDate)
            ->where(fn ($query) => $query->where('is_closed', true)->orWhere('status', 'closed'))
            ->exists();
    }

    /** @return list<string> */
    private function lineRelations(): array
    {
        return ['accountLines', 'partyLines', 'inventoryLines', 'toolLines', 'fixedAssetLines', 'prepaidLines', 'wipLines'];
    }
}
