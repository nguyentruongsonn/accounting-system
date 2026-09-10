<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Models\InventoryIssue;
use App\Services\Concerns\GuardsPostedDependentDocuments;
use App\Services\Concerns\RecordsSourceAudit;
use App\Support\DecimalMoney;
use App\Support\InventoryCostArithmetic;
use App\Support\InventoryTenantGuard;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class InventoryIssueService
{
    use GuardsPostedDependentDocuments, RecordsSourceAudit;

    protected JournalEntryService $journalEntryService;

    public function __construct(
        private readonly InventoryValuationService $valuationService,
        JournalEntryService $journalEntryService,
        private readonly AuditService $auditService,
        private readonly InventoryVoucherPeriodMutationGuard $periodMutationGuard,
        private readonly AccountingPolicyResolver $accountingPolicyResolver,
        private readonly InventoryVoucherAccountMappingPostingGate $accountMappingGate,
        private readonly CoreDocumentPostingAuthorizer $postingAuthorizer,
        private readonly InventoryAvailabilityService $availabilityService,
        private readonly InventoryValuationRunInvalidator $valuationRunInvalidator,
    ) {
        $this->journalEntryService = $journalEntryService;
    }

    public function getAll(array $filters = [])
    {
        $companyId = InventoryTenantGuard::companyId($filters);
        $query = InventoryIssue::with(['lines.item', 'lines.warehouse', 'employee', 'references'])
            ->where('company_id', $companyId)
            ->orderBy('voucher_date', 'desc')
            ->orderBy('id', 'desc');

        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }
        if (! empty($filters['from_date'])) {
            $query->whereDate('voucher_date', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate('voucher_date', '<=', $filters['to_date']);
        }
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('voucher_number', 'like', "%{$s}%")
                    ->orWhere('contact_name', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%");
            });
        }

        return $query->get();
    }

    public function getById(int $id, ?int $companyId = null): InventoryIssue
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        return InventoryIssue::with(['lines.item', 'lines.warehouse', 'employee', 'references', 'journalEntry.lines'])
            ->where('company_id', $companyId)
            ->findOrFail($id);
    }

    public function create(array $data): InventoryIssue
    {
        $companyId = InventoryTenantGuard::companyId($data);
        $data['contact_type'] = $data['contact_type'] ?? 'customer';
        InventoryTenantGuard::assertDocumentReferences($data, $companyId);
        $data = InventoryTenantGuard::normalizeDocumentReferences($data, $companyId);
        $this->assertProductionLineAccountEvidence($data['lines'] ?? null);
        $this->periodMutationGuard->assertCreate($companyId, $data, 'tạo phiếu xuất kho');

        return DB::transaction(function () use ($data, $companyId) {
            $voucherType = $data['voucher_type'] ?? '1. Xuất kho bán hàng';
            $useLegacyAccountDefaults = ! $this->isProductionEnvironment();
            $defaultDebit = null;
            $defaultCredit = null;

            // A label such as "xuất bán" is not an approved accounting
            // mapping. Historical fallback values are diagnostic-only.
            if ($useLegacyAccountDefaults) {
                $defaultDebit = '632';
                $defaultCredit = '1561';

                if (str_contains($voucherType, 'sản xuất') || str_contains($voucherType, '621')) {
                    $defaultDebit = '621';
                    $defaultCredit = '152';
                } elseif (str_contains($voucherType, 'CCDC') || str_contains($voucherType, '153')) {
                    $defaultDebit = '642';
                    $defaultCredit = '153';
                } elseif (str_contains($voucherType, 'khác') || str_contains($voucherType, '811')) {
                    $defaultDebit = '811';
                    $defaultCredit = '1561';
                }
            }

            $issue = InventoryIssue::create([
                'company_id' => $companyId,
                'voucher_type' => $voucherType,
                'contact_type' => $data['contact_type'] ?? 'customer',
                'contact_id' => $data['contact_id'] ?? null,
                'contact_name' => $data['contact_name'] ?? null,
                'receiver_name' => $data['receiver_name'] ?? null,
                'receiver_address' => $data['receiver_address'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'employee_name' => $data['employee_name'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'voucher_number' => $data['voucher_number'] ?? $this->generateNextCode($companyId),
                'voucher_date' => $data['voucher_date'] ?? now()->toDateString(),
                'posting_date' => $data['posting_date'] ?? $data['voucher_date'] ?? now()->toDateString(),
                'description' => $data['description'] ?? null,
                'attached_docs' => $data['attached_docs'] ?? null,
                'currency' => $data['currency'] ?? 'VND',
                'exchange_rate' => floatval($data['exchange_rate'] ?? 1),
                'total_amount' => DecimalMoney::ZERO,
                // Posting is a separate, gated lifecycle action. Never let a
                // create payload make an unposted source document look posted.
                'status' => 'draft',
                'is_posted' => false,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            $totalAmount = DecimalMoney::ZERO;
            $lines = $data['lines'] ?? [];

            foreach ($lines as $line) {
                $amounts = $this->lineAmounts(
                    $line,
                    $companyId,
                    (string) ($data['posting_date'] ?? $data['voucher_date'] ?? now()->toDateString()),
                );

                $totalAmount = DecimalMoney::add($totalAmount, $amounts['amount']);

                $issue->lines()->create([
                    'item_id' => $line['item_id'],
                    'unit' => $line['unit'] ?? null,
                    'warehouse_id' => $line['warehouse_id'] ?? $issue->warehouse_id,
                    'warehouse_code' => $line['warehouse_code'] ?? null,
                    'description' => $line['description'] ?? $issue->description,
                    'quantity' => $amounts['quantity'],
                    'unit_price' => $amounts['unit_price'],
                    'amount' => $amounts['amount'],
                    'debit_account' => $line['debit_account'] ?? $defaultDebit,
                    'credit_account' => $line['credit_account'] ?? $defaultCredit,
                ]);
            }

            $issue->update(['total_amount' => $totalAmount]);

            if (! empty($data['referenced_vouchers'])) {
                $issue->syncReferences($data['referenced_vouchers']);
            }

            $this->recordSourceAudit($issue, 'inventory_issue.created', [], $issue->fresh('lines')->toArray());

            return $issue->load(['lines.item', 'lines.warehouse', 'employee', 'references']);
        });
    }

    public function update(int $id, array $data, ?int $companyId = null): InventoryIssue
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId ?? ($data['company_id'] ?? null)]);
        if (isset($data['company_id']) && (int) $data['company_id'] !== $companyId) {
            throw ValidationException::withMessages(['company_id' => 'The requested company does not belong to the authenticated user.']);
        }

        return DB::transaction(function () use ($id, $data, $companyId) {
            $issue = InventoryIssue::with('lines')->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $this->periodMutationGuard->assertUpdate($issue, $data, 'sửa phiếu xuất kho');
            $before = $issue->toArray();
            $data['contact_type'] = $data['contact_type'] ?? $issue->contact_type ?? 'customer';
            InventoryTenantGuard::assertDocumentReferences($data, $issue->company_id);
            $data = InventoryTenantGuard::normalizeDocumentReferences($data, $issue->company_id);
            $this->assertProductionLineAccountEvidence($data['lines'] ?? null);

            if ($issue->is_posted) {
                throw new ConflictHttpException('Không thể sửa phiếu xuất kho đã ghi sổ. Vui lòng bỏ ghi sổ trước khi sửa.');
            }

            $voucherType = $data['voucher_type'] ?? $issue->voucher_type ?? '1. Xuất kho bán hàng';
            $useLegacyAccountDefaults = ! $this->isProductionEnvironment();
            $defaultDebit = null;
            $defaultCredit = null;

            if ($useLegacyAccountDefaults) {
                $defaultDebit = '632';
                $defaultCredit = '1561';

                if (str_contains($voucherType, 'sản xuất') || str_contains($voucherType, '621')) {
                    $defaultDebit = '621';
                    $defaultCredit = '152';
                } elseif (str_contains($voucherType, 'CCDC') || str_contains($voucherType, '153')) {
                    $defaultDebit = '642';
                    $defaultCredit = '153';
                } elseif (str_contains($voucherType, 'khác') || str_contains($voucherType, '811')) {
                    $defaultDebit = '811';
                    $defaultCredit = '1561';
                }
            }

            $totalAmount = DecimalMoney::ZERO;
            $lines = $data['lines'] ?? null;

            $issue->update([
                'voucher_type' => $voucherType,
                'contact_type' => $data['contact_type'] ?? $issue->contact_type,
                'contact_id' => $data['contact_id'] ?? $issue->contact_id,
                'contact_name' => $data['contact_name'] ?? $issue->contact_name,
                'receiver_name' => $data['receiver_name'] ?? $issue->receiver_name,
                'receiver_address' => $data['receiver_address'] ?? $issue->receiver_address,
                'employee_id' => $data['employee_id'] ?? $issue->employee_id,
                'employee_name' => $data['employee_name'] ?? $issue->employee_name,
                'warehouse_id' => $data['warehouse_id'] ?? $issue->warehouse_id,
                'voucher_number' => $data['voucher_number'] ?? $issue->voucher_number,
                'voucher_date' => $data['voucher_date'] ?? $issue->voucher_date,
                'posting_date' => $data['posting_date'] ?? $issue->posting_date,
                'description' => $data['description'] ?? $issue->description,
                'attached_docs' => $data['attached_docs'] ?? $issue->attached_docs,
                'currency' => $data['currency'] ?? $issue->currency,
                'exchange_rate' => floatval($data['exchange_rate'] ?? $issue->exchange_rate ?? 1),
                // An unposted voucher cannot carry a posted status. The
                // dedicated post() operation is the only status transition.
                'status' => ($data['status'] ?? null) === 'posted'
                    ? 'draft'
                    : ($data['status'] ?? $issue->status),
                'referenced_vouchers' => $data['referenced_vouchers'] ?? $issue->referenced_vouchers,
                'updated_by' => $data['updated_by'] ?? auth()->id(),
            ]);

            if ($lines !== null) {
                $issue->lines()->delete();
                foreach ($lines as $line) {
                    $amounts = $this->lineAmounts(
                        $line,
                        (int) $issue->company_id,
                        (string) ($data['posting_date'] ?? $issue->posting_date?->toDateString() ?? $issue->voucher_date?->toDateString() ?? now()->toDateString()),
                    );

                    $totalAmount = DecimalMoney::add($totalAmount, $amounts['amount']);

                    $issue->lines()->create([
                        'item_id' => $line['item_id'],
                        'unit' => $line['unit'] ?? null,
                        'warehouse_id' => $line['warehouse_id'] ?? $issue->warehouse_id,
                        'warehouse_code' => $line['warehouse_code'] ?? null,
                        'description' => $line['description'] ?? $issue->description,
                        'quantity' => $amounts['quantity'],
                        'unit_price' => $amounts['unit_price'],
                        'amount' => $amounts['amount'],
                        'debit_account' => $line['debit_account'] ?? $defaultDebit,
                        'credit_account' => $line['credit_account'] ?? $defaultCredit,
                    ]);
                }
                $issue->update(['total_amount' => $totalAmount]);
            }

            if (isset($data['referenced_vouchers'])) {
                $issue->syncReferences($data['referenced_vouchers']);
            }

            $this->recordSourceAudit($issue, 'inventory_issue.updated', $before, $issue->fresh('lines')->toArray());

            return $issue->load(['lines.item', 'lines.warehouse', 'employee', 'references']);
        });
    }

    public function delete(int $id, ?int $companyId = null): bool
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        return DB::transaction(function () use ($id, $companyId) {
            $issue = InventoryIssue::where('company_id', $companyId)->findOrFail($id);
            $this->periodMutationGuard->assertExisting($issue, 'xóa phiếu xuất kho');
            $before = $issue->load('lines')->toArray();
            if ($issue->is_posted) {
                throw new ConflictHttpException('Không thể xóa phiếu xuất kho đã ghi sổ. Hãy bỏ ghi sổ hoặc hủy riêng trước khi xóa.');
            }
            $issue->lines()->delete();
            $issue->references()->delete();
            $issue->delete();
            $this->recordSourceAudit($issue, 'inventory_issue.deleted', $before, ['deleted' => true]);

            return true;
        });
    }

    public function post($id, ?int $companyId = null)
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        return DB::transaction(function () use ($id, $companyId) {
            $issue = InventoryIssue::with('lines')->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $this->periodMutationGuard->assertExisting($issue, 'ghi sổ phiếu xuất kho');
            $before = $issue->toArray();
            if ($issue->is_posted) {
                throw new \Exception('Voucher is already posted');
            }
            if (config('accounting.enforce_inventory_stock_availability', true)) {
                $this->assertSufficientStock($issue);
            }
            $authorization = $this->postingAuthorizer->authorize('inventory.issues.post', (int) $issue->company_id);

            $policy = config('accounting.enforce_inventory_posting_account_mappings', true)
                ? $this->accountingPolicyResolver->requireForVoucher(
                    (int) $issue->company_id,
                    ($issue->posting_date ?? $issue->voucher_date)->toDateString(),
                    SystemVoucherType::INVENTORY_ISSUE,
                ) : null;
            $accountMappings = $policy !== null ? $this->accountMappingGate->requireSatisfied($issue, $policy) : null;

            $glLines = [];
            foreach ($issue->lines as $line) {
                if ($line->amount > 0) {
                    $debitAccount = $accountMappings !== null
                        ? InventoryVoucherAccountMappingPostingGate::accountFor($accountMappings, $issue, $line, 'debit', (string) $line->debit_account)
                        : $line->debit_account;
                    $creditAccount = $accountMappings !== null
                        ? InventoryVoucherAccountMappingPostingGate::accountFor($accountMappings, $issue, $line, 'credit', (string) $line->credit_account)
                        : $line->credit_account;
                    $glLines[] = [
                        'account_code' => $debitAccount,
                        'description' => $line->description ?? ($issue->description ?? 'Xuất kho hàng hóa'),
                        'debit_amount' => $line->amount,
                        'credit_amount' => 0,
                    ];
                    $glLines[] = [
                        'account_code' => $creditAccount,
                        'description' => $line->description ?? ($issue->description ?? 'Xuất kho hàng hóa'),
                        'debit_amount' => 0,
                        'credit_amount' => $line->amount,
                    ];
                }
            }

            // A source voucher is not posted unless its GL evidence was
            // actually created.  The old empty-line branch marked the source
            // posted with a null journal_entry_id when diagnostic mapping
            // mode was enabled, producing a false-success and an
            // unreconcilable source record.
            if ($glLines === []) {
                throw ValidationException::withMessages([
                    'lines' => 'Không thể ghi sổ phiếu xuất kho khi không có dòng hạch toán có số tiền dương.',
                ]);
            }

            $je = $this->journalEntryService->createPosted([
                'company_id' => $issue->company_id,
                'voucher_type' => 'inventory_issue',
                'voucher_number' => 'GL-II-'.$issue->voucher_number,
                'voucher_date' => $issue->voucher_date,
                'posting_date' => $issue->posting_date ? $issue->posting_date->toDateString() : now()->toDateString(),
                'description' => $issue->description ?? 'Phiếu xuất kho '.$issue->voucher_number,
                'total_amount' => 0,
                'status' => 'posted',
                'source_document_type' => InventoryIssue::class,
                'source_document_id' => $issue->id,
                'lines' => $glLines,
            ]);
            $issue->journal_entry_id = $je->id;

            $issue->is_posted = true;
            $issue->status = 'posted';
            $issue->save();
            $this->invalidateValuationRuns($issue, 'inventory_issue_posted');
            $this->auditService->record($issue, 'inventory_issue.posting_authorization_applied', [], [], null, [
                'journal_entry_id' => $je->id,
            ] + $authorization);
            if ($accountMappings !== null) {
                $this->auditService->record($issue, 'inventory_issue.account_mappings_applied', [], [], null, [
                    'journal_entry_id' => $je->id,
                    'account_mapping_gate' => 'enforced',
                    'accounting_policy' => $policy,
                    'account_mappings' => $accountMappings,
                ]);
            }
            $this->recordSourceAudit($issue, 'inventory_issue.posted', $before, $issue->fresh()->toArray());

            return $issue->load(['lines.item', 'lines.warehouse', 'employee', 'references', 'journalEntry.lines']);
        });
    }

    private function assertSufficientStock(InventoryIssue $issue): void
    {
        $requested = [];
        foreach ($issue->lines as $index => $line) {
            $warehouseId = (int) ($line->warehouse_id ?? $issue->warehouse_id ?? 0);
            if ($warehouseId < 1) {
                throw ValidationException::withMessages([
                    "lines.{$index}.warehouse_id" => 'Mỗi dòng xuất kho phải xác định kho.',
                ]);
            }
            $key = $line->item_id.'|'.$warehouseId;
            if (! isset($requested[$key])) {
                $requested[$key] = ['item_id' => (int) $line->item_id, 'warehouse_id' => $warehouseId, 'quantity' => BigDecimal::zero()->toScale(4), 'indexes' => []];
            }
            $requested[$key]['quantity'] = $requested[$key]['quantity']->plus(BigDecimal::of((string) $line->quantity));
            $requested[$key]['indexes'][] = $index;
        }

        $errors = [];
        $asOfDate = ($issue->posting_date ?? $issue->voucher_date)->toDateString();
        foreach ($requested as $group) {
            $available = BigDecimal::of($this->availabilityService->availableQuantity(
                (int) $issue->company_id,
                $group['item_id'],
                $group['warehouse_id'],
                $asOfDate,
            ));
            if ($group['quantity']->isGreaterThan($available)) {
                foreach ($group['indexes'] as $index) {
                    $errors["lines.{$index}.quantity"] = "Số lượng xuất vượt tồn khả dụng {$available->toScale(4)} tại kho đã chọn.";
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function void($id, ?int $companyId = null)
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        return DB::transaction(function () use ($id, $companyId) {
            $issue = InventoryIssue::with('lines')->where('company_id', $companyId)->findOrFail($id);
            $this->periodMutationGuard->assertExisting($issue, 'hủy phiếu xuất kho');
            $before = $issue->toArray();
            if (! $issue->is_posted) {
                throw new \Exception('Voucher is not posted yet');
            }
            $this->assertNoPostedDependentDocuments($companyId, InventoryIssue::class, (int) $issue->id);

            if ($issue->journal_entry_id) {
                $this->journalEntryService->void($issue->journal_entry_id, $companyId);
            }

            $issue->is_posted = false;
            $issue->status = 'draft';
            $issue->save();
            $this->invalidateValuationRuns($issue, 'inventory_issue_voided');
            $this->recordSourceAudit($issue, 'inventory_issue.voided', $before, $issue->fresh()->toArray(), ['journal_entry_id' => $before['journal_entry_id'] ?? null]);

            return $issue;
        });
    }

    public function unpost($id, ?int $companyId = null): InventoryIssue
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        return DB::transaction(function () use ($id, $companyId) {
            $issue = InventoryIssue::with('lines')->where('company_id', $companyId)->findOrFail($id);
            $this->periodMutationGuard->assertExisting($issue, 'bỏ ghi sổ phiếu xuất kho');
            $before = $issue->toArray();
            if (! $issue->is_posted) {
                throw new \Exception('Voucher is not posted yet');
            }
            $this->assertNoPostedDependentDocuments($companyId, InventoryIssue::class, (int) $issue->id);
            if ($issue->journal_entry_id) {
                $this->journalEntryService->void($issue->journal_entry_id, $companyId);
            }
            $issue->is_posted = false;
            $issue->status = 'draft';
            $issue->save();
            $this->invalidateValuationRuns($issue, 'inventory_issue_unposted');
            $this->recordSourceAudit($issue, 'inventory_issue.unposted', $before, $issue->fresh()->toArray(), ['journal_entry_id' => $before['journal_entry_id'] ?? null]);

            return $issue;
        });
    }

    private function invalidateValuationRuns(InventoryIssue $issue, string $reason): void
    {
        $movementDate = ($issue->posting_date ?? $issue->voucher_date)->toDateString();
        foreach ($issue->lines as $line) {
            $this->valuationRunInvalidator->invalidateForInventoryMovement(
                (int) $issue->company_id,
                $movementDate,
                $reason,
                $line->warehouse_id ?? $issue->warehouse_id,
                (int) $line->item_id,
            );
        }
    }

    /** @return array{quantity:string,unit_price:string,amount:string} */
    private function lineAmounts(array $line, int $companyId, string $valuationDate): array
    {
        $quantity = InventoryCostArithmetic::quantity((string) ($line['quantity'] ?? '1'));
        $rawUnitPrice = trim((string) ($line['unit_price'] ?? ''));
        $unitPrice = $rawUnitPrice !== '' && BigDecimal::of($rawUnitPrice)->isPositive()
            ? InventoryCostArithmetic::rate($rawUnitPrice)
            : $this->valuationService->getMovingAverageCostExact($line['item_id'], $companyId, $valuationDate);

        $rawAmount = trim((string) ($line['amount'] ?? ''));
        $amount = $rawAmount !== '' && BigDecimal::of($rawAmount)->isPositive()
            ? DecimalMoney::normalize($rawAmount)
            : InventoryCostArithmetic::amountForQuantityAtRate($quantity, $unitPrice);

        return ['quantity' => $quantity, 'unit_price' => $unitPrice, 'amount' => $amount];
    }

    public function duplicate($id, ?int $companyId = null): InventoryIssue
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        return DB::transaction(function () use ($id, $companyId) {
            $original = InventoryIssue::with('lines')->where('company_id', $companyId)->findOrFail($id);
            $this->periodMutationGuard->assertDuplicate($original, 'nhân bản phiếu xuất kho');
            $newNumber = $this->generateNextCode($original->company_id);

            $newIssue = $original->replicate();
            $newIssue->voucher_number = $newNumber;
            $newIssue->voucher_date = now()->toDateString();
            $newIssue->posting_date = now()->toDateString();
            $newIssue->is_posted = false;
            $newIssue->journal_entry_id = null;
            $newIssue->status = 'draft';
            $newIssue->save();

            foreach ($original->lines as $line) {
                $newLine = $line->replicate();
                $newLine->inventory_issue_id = $newIssue->id;
                $newLine->save();
            }

            if ($original->referenced_vouchers) {
                $newIssue->syncReferences($original->referenced_vouchers);
            }

            $this->recordSourceAudit($newIssue, 'inventory_issue.duplicated', [], $newIssue->fresh('lines')->toArray(), ['original_source_id' => $original->id]);

            return $newIssue->load(['lines.item', 'lines.warehouse', 'employee', 'references']);
        });
    }

    /**
     * Production vouchers must preserve the accounts selected by the
     * operator/policy boundary. The legacy default routes are not accounting
     * evidence and remain available only outside production diagnostics.
     *
     * @param  array<int, array<string, mixed>>|null  $lines
     */
    private function assertProductionLineAccountEvidence(?array $lines): void
    {
        if (! $this->isProductionEnvironment() || $lines === null) {
            return;
        }

        $errors = [];
        foreach ($lines as $index => $line) {
            foreach (['debit_account', 'credit_account'] as $field) {
                $value = $line[$field] ?? null;
                if ((! is_int($value) && ! is_string($value)) || trim((string) $value) === '') {
                    $errors["lines.{$index}.{$field}"] = 'Tài khoản phải được cung cấp tường minh trong production; hệ thống không tự gán tài khoản.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function isProductionEnvironment(): bool
    {
        return in_array(strtolower((string) config('app.env')), ['production', 'prod'], true);
    }

    public function generateNextCode(int $companyId, ?string $prefix = 'PXK'): string
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);
        $year = now()->format('Y');
        $codePrefix = ($prefix ?: 'PXK').'-'.$year.'-';
        $latest = InventoryIssue::where('company_id', $companyId)
            ->where('voucher_number', 'like', $codePrefix.'%')
            ->orderBy('id', 'desc')
            ->value('voucher_number');

        if ($latest && preg_match('/'.preg_quote($codePrefix, '/').'(\d+)/', $latest, $m)) {
            $nextSeq = str_pad((int) $m[1] + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $count = InventoryIssue::where('company_id', $companyId)->count() + 1;
            $nextSeq = str_pad($count, 4, '0', STR_PAD_LEFT);
        }

        return $codePrefix.$nextSeq;
    }
}
