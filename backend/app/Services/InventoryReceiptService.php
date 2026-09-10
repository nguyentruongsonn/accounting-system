<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Models\InventoryReceipt;
use App\Models\Item;
use App\Services\Concerns\GuardsPostedDependentDocuments;
use App\Services\Concerns\RecordsSourceAudit;
use App\Support\DecimalMoney;
use App\Support\InventoryCostArithmetic;
use App\Support\InventoryTenantGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class InventoryReceiptService
{
    use GuardsPostedDependentDocuments, RecordsSourceAudit;

    protected JournalEntryService $journalEntryService;

    public function __construct(
        JournalEntryService $journalEntryService,
        private readonly AuditService $auditService,
        private readonly InventoryVoucherPeriodMutationGuard $periodMutationGuard,
        private readonly AccountingPolicyResolver $accountingPolicyResolver,
        private readonly InventoryVoucherAccountMappingPostingGate $accountMappingGate,
        private readonly CoreDocumentPostingAuthorizer $postingAuthorizer,
        private readonly InventoryValuationRunInvalidator $valuationRunInvalidator,
    ) {
        $this->journalEntryService = $journalEntryService;
    }

    public function getAll(array $filters = [])
    {
        $companyId = InventoryTenantGuard::companyId($filters);
        $query = InventoryReceipt::with(['lines.item', 'lines.warehouse', 'employee', 'references'])
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

    public function getById(int $id, ?int $companyId = null): InventoryReceipt
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        return InventoryReceipt::with(['lines.item', 'lines.warehouse', 'employee', 'references', 'journalEntry.lines'])
            ->where('company_id', $companyId)
            ->findOrFail($id);
    }

    public function create(array $data): InventoryReceipt
    {
        $companyId = InventoryTenantGuard::companyId($data);
        $data['contact_type'] = $data['contact_type'] ?? 'supplier';
        InventoryTenantGuard::assertDocumentReferences($data, $companyId);
        $data = InventoryTenantGuard::normalizeDocumentReferences($data, $companyId);
        $this->assertProductionLineAccountEvidence($data['lines'] ?? null);
        $this->periodMutationGuard->assertCreate($companyId, $data, 'tạo phiếu nhập kho');

        return DB::transaction(function () use ($data, $companyId) {
            $lines = $data['lines'] ?? [];
            $preparedLines = [];
            $totalAmount = DecimalMoney::ZERO;

            foreach ($lines as $line) {
                $amounts = $this->lineAmounts($line);
                $preparedLines[] = array_merge($line, $amounts);
                $totalAmount = DecimalMoney::add($totalAmount, $amounts['amount']);
            }

            $voucherType = $data['voucher_type'] ?? '1. Nhập kho mua hàng';
            $useLegacyAccountDefaults = ! $this->isProductionEnvironment();
            $defaultDebit = null;
            $defaultCredit = null;

            // Historical defaults remain available only to the diagnostic
            // harness. Production must receive explicit account evidence and
            // must not infer a posting route from a label or item master data.
            if ($useLegacyAccountDefaults) {
                $defaultDebit = '1561';
                $defaultCredit = '331';

                if (str_contains($voucherType, 'sản xuất') || str_contains($voucherType, '154')) {
                    $defaultDebit = '155';
                    $defaultCredit = '154';
                } elseif (str_contains($voucherType, 'trả lại') || str_contains($voucherType, '632')) {
                    $defaultDebit = '1561';
                    $defaultCredit = '632';
                } elseif (str_contains($voucherType, 'khác') || str_contains($voucherType, '711')) {
                    $defaultDebit = '1561';
                    $defaultCredit = '711';
                }
            }

            $receipt = InventoryReceipt::create([
                'company_id' => $companyId,
                'voucher_type' => $voucherType,
                'contact_type' => $data['contact_type'] ?? 'supplier',
                'contact_id' => $data['contact_id'] ?? null,
                'contact_name' => $data['contact_name'] ?? null,
                'deliverer_name' => $data['deliverer_name'] ?? null,
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
                'total_amount' => $totalAmount,
                // Posting is a separate, gated lifecycle action. Never let a
                // create payload make an unposted source document look posted.
                'status' => 'draft',
                'is_posted' => false,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            foreach ($preparedLines as $line) {
                $debitAccount = $line['debit_account'] ?? null;
                $creditAccount = $line['credit_account'] ?? null;

                if ($useLegacyAccountDefaults) {
                    $itemInventoryAccount = Item::where('company_id', $companyId)
                        ->whereKey($line['item_id'])
                        ->value('inventory_account');
                    $debitAccount ??= $itemInventoryAccount ?? $defaultDebit;
                    $creditAccount ??= $defaultCredit;
                }

                $receipt->lines()->create([
                    'item_id' => $line['item_id'],
                    'unit' => $line['unit'] ?? null,
                    'warehouse_id' => $line['warehouse_id'] ?? $receipt->warehouse_id,
                    'warehouse_code' => $line['warehouse_code'] ?? null,
                    'description' => $line['description'] ?? $receipt->description,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'amount' => $line['amount'],
                    'debit_account' => $debitAccount,
                    'credit_account' => $creditAccount,
                ]);
            }

            if (! empty($data['referenced_vouchers'])) {
                $receipt->syncReferences($data['referenced_vouchers']);
            }

            $this->recordSourceAudit($receipt, 'inventory_receipt.created', [], $receipt->fresh('lines')->toArray());

            return $receipt->load(['lines.item', 'lines.warehouse', 'employee', 'references']);
        });
    }

    public function update(int $id, array $data, ?int $companyId = null): InventoryReceipt
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId ?? ($data['company_id'] ?? null)]);
        if (isset($data['company_id']) && (int) $data['company_id'] !== $companyId) {
            throw ValidationException::withMessages(['company_id' => 'The requested company does not belong to the authenticated user.']);
        }

        return DB::transaction(function () use ($id, $data, $companyId) {
            $receipt = InventoryReceipt::with('lines')->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $this->periodMutationGuard->assertUpdate($receipt, $data, 'sửa phiếu nhập kho');
            $before = $receipt->toArray();
            $data['contact_type'] = $data['contact_type'] ?? $receipt->contact_type ?? 'supplier';
            InventoryTenantGuard::assertDocumentReferences($data, $receipt->company_id);
            $data = InventoryTenantGuard::normalizeDocumentReferences($data, $receipt->company_id);
            $this->assertProductionLineAccountEvidence($data['lines'] ?? null);

            if ($receipt->is_posted) {
                throw new ConflictHttpException('Không thể sửa phiếu nhập kho đã ghi sổ. Vui lòng bỏ ghi sổ trước khi sửa.');
            }

            $lines = $data['lines'] ?? null;
            $preparedLines = null;

            if ($lines !== null) {
                $preparedLines = [];
                $totalAmount = DecimalMoney::ZERO;
                foreach ($lines as $line) {
                    $amounts = $this->lineAmounts($line);
                    $preparedLines[] = array_merge($line, $amounts);
                    $totalAmount = DecimalMoney::add($totalAmount, $amounts['amount']);
                }
            } else {
                $totalAmount = DecimalMoney::normalize($receipt->total_amount);
            }

            $voucherType = $data['voucher_type'] ?? $receipt->voucher_type ?? '1. Nhập kho mua hàng';
            $useLegacyAccountDefaults = ! $this->isProductionEnvironment();
            $defaultDebit = null;
            $defaultCredit = null;

            if ($useLegacyAccountDefaults) {
                $defaultDebit = '1561';
                $defaultCredit = '331';

                if (str_contains($voucherType, 'sản xuất') || str_contains($voucherType, '154')) {
                    $defaultDebit = '155';
                    $defaultCredit = '154';
                } elseif (str_contains($voucherType, 'trả lại') || str_contains($voucherType, '632')) {
                    $defaultDebit = '1561';
                    $defaultCredit = '632';
                } elseif (str_contains($voucherType, 'khác') || str_contains($voucherType, '711')) {
                    $defaultDebit = '1561';
                    $defaultCredit = '711';
                }
            }

            $receipt->update([
                'voucher_type' => $voucherType,
                'contact_type' => $data['contact_type'] ?? $receipt->contact_type,
                'contact_id' => $data['contact_id'] ?? $receipt->contact_id,
                'contact_name' => $data['contact_name'] ?? $receipt->contact_name,
                'deliverer_name' => $data['deliverer_name'] ?? $receipt->deliverer_name,
                'receiver_address' => $data['receiver_address'] ?? $receipt->receiver_address,
                'employee_id' => $data['employee_id'] ?? $receipt->employee_id,
                'employee_name' => $data['employee_name'] ?? $receipt->employee_name,
                'warehouse_id' => $data['warehouse_id'] ?? $receipt->warehouse_id,
                'voucher_number' => $data['voucher_number'] ?? $receipt->voucher_number,
                'voucher_date' => $data['voucher_date'] ?? $receipt->voucher_date,
                'posting_date' => $data['posting_date'] ?? $receipt->posting_date,
                'description' => $data['description'] ?? $receipt->description,
                'attached_docs' => $data['attached_docs'] ?? $receipt->attached_docs,
                'currency' => $data['currency'] ?? $receipt->currency,
                'exchange_rate' => floatval($data['exchange_rate'] ?? $receipt->exchange_rate ?? 1),
                'total_amount' => $totalAmount,
                // An unposted voucher cannot carry a posted status. The
                // dedicated post() operation is the only status transition.
                'status' => ($data['status'] ?? null) === 'posted'
                    ? 'draft'
                    : ($data['status'] ?? $receipt->status),
                'referenced_vouchers' => $data['referenced_vouchers'] ?? $receipt->referenced_vouchers,
                'updated_by' => $data['updated_by'] ?? auth()->id(),
            ]);

            if ($lines !== null) {
                $receipt->lines()->delete();
                foreach ($preparedLines ?? [] as $line) {
                    $debitAccount = $line['debit_account'] ?? null;
                    $creditAccount = $line['credit_account'] ?? null;

                    if ($useLegacyAccountDefaults) {
                        // Resolve the item-owned account per line in the
                        // legacy harness so a multi-item receipt is not
                        // accidentally assigned the preceding item's route.
                        $itemInventoryAccount = Item::where('company_id', $receipt->company_id)
                            ->whereKey($line['item_id'])
                            ->value('inventory_account');
                        $debitAccount ??= $itemInventoryAccount ?? $defaultDebit;
                        $creditAccount ??= $defaultCredit;
                    }

                    $receipt->lines()->create([
                        'item_id' => $line['item_id'],
                        'unit' => $line['unit'] ?? null,
                        'warehouse_id' => $line['warehouse_id'] ?? $receipt->warehouse_id,
                        'warehouse_code' => $line['warehouse_code'] ?? null,
                        'description' => $line['description'] ?? $receipt->description,
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'amount' => $line['amount'],
                        'debit_account' => $debitAccount,
                        'credit_account' => $creditAccount,
                    ]);
                }
            }

            if (isset($data['referenced_vouchers'])) {
                $receipt->syncReferences($data['referenced_vouchers']);
            }

            $this->recordSourceAudit($receipt, 'inventory_receipt.updated', $before, $receipt->fresh('lines')->toArray());

            return $receipt->load(['lines.item', 'lines.warehouse', 'employee', 'references']);
        });
    }

    public function delete(int $id, ?int $companyId = null): bool
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        return DB::transaction(function () use ($id, $companyId) {
            $receipt = InventoryReceipt::where('company_id', $companyId)->findOrFail($id);
            $this->periodMutationGuard->assertExisting($receipt, 'xóa phiếu nhập kho');
            $before = $receipt->load('lines')->toArray();
            if ($receipt->is_posted) {
                throw new ConflictHttpException('Không thể xóa phiếu nhập kho đã ghi sổ. Hãy bỏ ghi sổ hoặc hủy riêng trước khi xóa.');
            }
            $receipt->lines()->delete();
            $receipt->references()->delete();
            $receipt->delete();
            $this->recordSourceAudit($receipt, 'inventory_receipt.deleted', $before, ['deleted' => true]);

            return true;
        });
    }

    public function post($id, ?int $companyId = null)
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        return DB::transaction(function () use ($id, $companyId) {
            $receipt = InventoryReceipt::with('lines')->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $this->periodMutationGuard->assertExisting($receipt, 'ghi sổ phiếu nhập kho');
            $before = $receipt->toArray();
            if ($receipt->is_posted) {
                throw new \Exception('Voucher is already posted');
            }
            $authorization = $this->postingAuthorizer->authorize('inventory.receipts.post', (int) $receipt->company_id);

            $policy = config('accounting.enforce_inventory_posting_account_mappings', true)
                ? $this->accountingPolicyResolver->requireForVoucher(
                    (int) $receipt->company_id,
                    ($receipt->posting_date ?? $receipt->voucher_date)->toDateString(),
                    SystemVoucherType::INVENTORY_RECEIPT,
                ) : null;
            $accountMappings = $policy !== null ? $this->accountMappingGate->requireSatisfied($receipt, $policy) : null;

            $glLines = [];
            foreach ($receipt->lines as $line) {
                if ($line->amount > 0) {
                    $debitAccount = $accountMappings !== null
                        ? InventoryVoucherAccountMappingPostingGate::accountFor($accountMappings, $receipt, $line, 'debit', (string) $line->debit_account)
                        : $line->debit_account;
                    $creditAccount = $accountMappings !== null
                        ? InventoryVoucherAccountMappingPostingGate::accountFor($accountMappings, $receipt, $line, 'credit', (string) $line->credit_account)
                        : $line->credit_account;
                    $glLines[] = [
                        'account_code' => $debitAccount,
                        'description' => $line->description ?? ($receipt->description ?? 'Nhập kho hàng hóa'),
                        'debit_amount' => $line->amount,
                        'credit_amount' => 0,
                    ];
                    $glLines[] = [
                        'account_code' => $creditAccount,
                        'description' => $line->description ?? ($receipt->description ?? 'Nhập kho hàng hóa'),
                        'debit_amount' => 0,
                        'credit_amount' => $line->amount,
                    ];
                }
            }

            $je = $this->journalEntryService->createPosted([
                'company_id' => $receipt->company_id,
                'voucher_type' => 'inventory_receipt',
                'voucher_number' => 'GL-IR-'.$receipt->voucher_number,
                'voucher_date' => $receipt->voucher_date,
                'posting_date' => $receipt->posting_date ? $receipt->posting_date->toDateString() : now()->toDateString(),
                'description' => $receipt->description ?? 'Phiếu nhập kho '.$receipt->voucher_number,
                'total_amount' => 0,
                'status' => 'posted',
                'source_document_type' => InventoryReceipt::class,
                'source_document_id' => $receipt->id,
                'lines' => $glLines,
            ]);

            $receipt->journal_entry_id = $je->id;
            $receipt->is_posted = true;
            $receipt->status = 'posted';
            $receipt->save();
            $this->invalidateValuationRuns($receipt, 'inventory_receipt_posted');
            $this->auditService->record($receipt, 'inventory_receipt.posting_authorization_applied', [], [], null, [
                'journal_entry_id' => $je->id,
            ] + $authorization);
            if ($accountMappings !== null) {
                $this->auditService->record($receipt, 'inventory_receipt.account_mappings_applied', [], [], null, [
                    'journal_entry_id' => $je->id,
                    'account_mapping_gate' => 'enforced',
                    'accounting_policy' => $policy,
                    'account_mappings' => $accountMappings,
                ]);
            }
            $this->recordSourceAudit($receipt, 'inventory_receipt.posted', $before, $receipt->fresh()->toArray());

            return $receipt->load(['lines.item', 'lines.warehouse', 'employee', 'references', 'journalEntry.lines']);
        });
    }

    public function void($id, ?int $companyId = null)
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        return DB::transaction(function () use ($id, $companyId) {
            $receipt = InventoryReceipt::with('lines')->where('company_id', $companyId)->findOrFail($id);
            $this->periodMutationGuard->assertExisting($receipt, 'hủy phiếu nhập kho');
            $before = $receipt->toArray();
            if (! $receipt->is_posted) {
                throw new \Exception('Voucher is not posted yet');
            }
            $this->assertNoPostedDependentDocuments($companyId, InventoryReceipt::class, (int) $receipt->id);

            if ($receipt->journal_entry_id) {
                $this->journalEntryService->void($receipt->journal_entry_id, $companyId);
            }

            $receipt->is_posted = false;
            $receipt->status = 'draft';
            $receipt->save();
            $this->invalidateValuationRuns($receipt, 'inventory_receipt_voided');
            $this->recordSourceAudit($receipt, 'inventory_receipt.voided', $before, $receipt->fresh()->toArray(), ['journal_entry_id' => $before['journal_entry_id'] ?? null]);

            return $receipt;
        });
    }

    public function unpost($id, ?int $companyId = null): InventoryReceipt
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        return DB::transaction(function () use ($id, $companyId) {
            $receipt = InventoryReceipt::with('lines')->where('company_id', $companyId)->findOrFail($id);
            $this->periodMutationGuard->assertExisting($receipt, 'bỏ ghi sổ phiếu nhập kho');
            $before = $receipt->toArray();
            if (! $receipt->is_posted) {
                throw new \Exception('Voucher is not posted yet');
            }
            $this->assertNoPostedDependentDocuments($companyId, InventoryReceipt::class, (int) $receipt->id);
            if ($receipt->journal_entry_id) {
                $this->journalEntryService->void($receipt->journal_entry_id, $companyId);
            }
            $receipt->is_posted = false;
            $receipt->status = 'draft';
            $receipt->save();
            $this->invalidateValuationRuns($receipt, 'inventory_receipt_unposted');
            $this->recordSourceAudit($receipt, 'inventory_receipt.unposted', $before, $receipt->fresh()->toArray(), ['journal_entry_id' => $before['journal_entry_id'] ?? null]);

            return $receipt;
        });
    }

    private function invalidateValuationRuns(InventoryReceipt $receipt, string $reason): void
    {
        $movementDate = ($receipt->posting_date ?? $receipt->voucher_date)->toDateString();
        foreach ($receipt->lines as $line) {
            $this->valuationRunInvalidator->invalidateForInventoryMovement(
                (int) $receipt->company_id,
                $movementDate,
                $reason,
                $line->warehouse_id ?? $receipt->warehouse_id,
                (int) $line->item_id,
            );
        }
    }

    public function duplicate($id, ?int $companyId = null): InventoryReceipt
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);

        return DB::transaction(function () use ($id, $companyId) {
            $original = InventoryReceipt::with('lines')->where('company_id', $companyId)->findOrFail($id);
            $this->periodMutationGuard->assertDuplicate($original, 'nhân bản phiếu nhập kho');
            $newNumber = $this->generateNextCode($original->company_id);

            $newReceipt = $original->replicate();
            $newReceipt->voucher_number = $newNumber;
            $newReceipt->voucher_date = now()->toDateString();
            $newReceipt->posting_date = now()->toDateString();
            $newReceipt->is_posted = false;
            $newReceipt->journal_entry_id = null;
            $newReceipt->status = 'draft';
            $newReceipt->save();

            foreach ($original->lines as $line) {
                $newLine = $line->replicate();
                $newLine->inventory_receipt_id = $newReceipt->id;
                $newLine->save();
            }

            if ($original->referenced_vouchers) {
                $newReceipt->syncReferences($original->referenced_vouchers);
            }

            $this->recordSourceAudit($newReceipt, 'inventory_receipt.duplicated', [], $newReceipt->fresh('lines')->toArray(), ['original_source_id' => $original->id]);

            return $newReceipt->load(['lines.item', 'lines.warehouse', 'employee', 'references']);
        });
    }

    /** @return array{quantity:string,unit_price:string,amount:string} */
    private function lineAmounts(array $line): array
    {
        $quantity = InventoryCostArithmetic::quantity((string) ($line['quantity'] ?? '1'));
        $unitPrice = InventoryCostArithmetic::rate((string) ($line['unit_price'] ?? '0'));
        $rawAmount = trim((string) ($line['amount'] ?? ''));
        $amount = $rawAmount !== ''
            ? DecimalMoney::normalize($rawAmount)
            : InventoryCostArithmetic::amountForQuantityAtRate($quantity, $unitPrice);

        return [
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $amount,
        ];
    }

    /**
     * The item and voucher defaults below are a legacy diagnostic path, not a
     * production accounting policy. An operator must supply account evidence
     * before a production voucher can be persisted for later posting.
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

    public function generateNextCode(int $companyId, ?string $prefix = 'PNK'): string
    {
        $companyId = InventoryTenantGuard::companyId(['company_id' => $companyId]);
        $year = now()->format('Y');
        $codePrefix = ($prefix ?: 'PNK').'-'.$year.'-';
        $latest = InventoryReceipt::where('company_id', $companyId)
            ->where('voucher_number', 'like', $codePrefix.'%')
            ->orderBy('id', 'desc')
            ->value('voucher_number');

        if ($latest && preg_match('/'.preg_quote($codePrefix, '/').'(\d+)/', $latest, $m)) {
            $nextSeq = str_pad((int) $m[1] + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $count = InventoryReceipt::where('company_id', $companyId)->count() + 1;
            $nextSeq = str_pad($count, 4, '0', STR_PAD_LEFT);
        }

        return $codePrefix.$nextSeq;
    }
}
