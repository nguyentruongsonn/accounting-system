<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Models\CashReceipt;
use App\Models\Employee;
use App\Models\SalesInvoice;
use App\Services\Concerns\GuardsCashBankTenantReferences;
use App\Services\Concerns\GuardsPostedDependentDocuments;
use App\Services\Concerns\GuardsPostedSettlementAllocations;
use App\Services\Concerns\RecordsSourceAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CashReceiptService
{
    use GuardsCashBankTenantReferences, GuardsPostedDependentDocuments, GuardsPostedSettlementAllocations, RecordsSourceAudit;

    protected JournalEntryService $journalEntryService;

    public function __construct(
        JournalEntryService $journalEntryService,
        private readonly AuditService $auditService,
        private readonly AccountingPolicyResolver $accountingPolicyResolver,
        private readonly CoreDocumentPostingAuthorizer $postingAuthorizer,
        private readonly CashBankVoucherAccountMappingPostingGate $accountMappingGate,
        private readonly CashBankVoucherPeriodMutationGuard $periodMutationGuard,
        private readonly ApArSettlementStatusPolicy $settlementStatusPolicy,
    )
    {
        $this->journalEntryService = $journalEntryService;
    }

    public function getAll(array $filters = [])
    {
        $query = CashReceipt::with(['lines', 'references', 'referencedBy']);
        $companyId = $this->requireCompanyId($filters);
        $query->where('company_id', $companyId);

        if (! empty($filters['date_from'])) {
            $query->where('voucher_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('voucher_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['contact_id'])) {
            $query->where('contact_id', $filters['contact_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('voucher_number', 'like', "%{$search}%")
                    ->orWhere('payer_name', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('voucher_date', 'desc')->get();
    }

    public function getById($id, ?int $companyId = null)
    {
        $companyId = $this->requireCompanyId(['company_id' => $companyId]);

        return CashReceipt::with(['lines', 'references', 'referencedBy'])
            ->where('company_id', $companyId)
            ->findOrFail($id);
    }

    public function create(array $data): CashReceipt
    {
        $data['company_id'] = $this->requireCompanyId($data);
        $data = $this->sanitizeReceiptData($data);
        $this->assertCashBankTenantReferences($data, (int) $data['company_id'], SalesInvoice::class);
        $data = $this->normalizeCashBankTenantReferences($data, (int) $data['company_id']);
        $this->assertProductionExplicitLineAccounts($data['lines'] ?? []);

        return DB::transaction(function () use ($data) {
            $this->periodMutationGuard->assertCreate((int) $data['company_id'], $data, 'lập phiếu thu tiền mặt');
            $totalAmount = collect($data['lines'] ?? [])->sum('amount');

            $voucherNumber = $data['voucher_number'] ?? null;
            if (empty($voucherNumber)) {
                $last = CashReceipt::where('company_id', $data['company_id'])->orderBy('id', 'desc')->first();
                $nextNum = $last ? ($last->id + 1) : 1;
                $voucherNumber = 'PT'.str_pad($nextNum, 5, '0', STR_PAD_LEFT);
            }

            $receipt = CashReceipt::create([
                'company_id' => $data['company_id'],
                'voucher_type' => $data['voucher_type'] ?? null,
                'contact_type' => $data['contact_type'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'contact_name' => $data['contact_name'] ?? null,
                'voucher_number' => $voucherNumber,
                'voucher_date' => $data['voucher_date'],
                'posting_date' => $data['posting_date'] ?? $data['voucher_date'],
                'payer_name' => $data['payer_name'] ?? $data['contact_name'] ?? null,
                'payer_address' => $data['payer_address'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'employee_name' => $data['employee_name'] ?? null,
                'reason' => $data['reason'] ?? $data['description'] ?? null,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? null,
                'attached_docs' => $data['attached_docs'] ?? null,
                'currency' => $data['currency'] ?? 'VND',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'total_amount' => $totalAmount,
                'is_posted' => false,
            ]);

            if (! empty($data['lines'])) {
                foreach ($data['lines'] as $line) {
                    $receipt->lines()->create([
                        'description' => $line['description'] ?? $receipt->reason ?? 'Thu tiền',
                        'debit_account' => $this->accountOrLegacyFallback($line['debit_account'] ?? null, 'debit_account', '1111'),
                        'credit_account' => $this->accountOrLegacyFallback($line['credit_account'] ?? null, 'credit_account', '131'),
                        'amount' => $line['amount'] ?? 0,
                        'operation' => $line['operation'] ?? null,
                        'loan_contract' => $line['loan_contract'] ?? null,
                        'line_contact_id' => $line['line_contact_id'] ?? null,
                        'line_contact_name' => $line['line_contact_name'] ?? null,
                        'invoice_id' => $line['invoice_id'] ?? null,
                    ]);
                }
            }

            // Đồng bộ bảng voucher_references đa hình
            if (isset($data['referenced_vouchers'])) {
                $receipt->syncReferences($data['referenced_vouchers']);
            }

            $shouldPost = ! empty($data['is_posted']) || ! empty($data['auto_post']);
            if ($shouldPost) {
                $this->post($receipt->id);
            }

            $this->recordSourceAudit($receipt, 'cash_receipt.created', [], $receipt->fresh('lines')->toArray());

            return $receipt->fresh(['lines', 'references', 'referencedBy']);
        });
    }

    public function update($id, array $data): CashReceipt
    {
        $data = $this->sanitizeReceiptData($data);
        if (array_key_exists('lines', $data)) {
            $this->assertProductionExplicitLineAccounts($data['lines']);
        }

        return DB::transaction(function () use ($id, $data) {
            $receipt = $this->scopeMutationToAuthenticatedCompany(CashReceipt::query())->findOrFail($id);
            $this->assertCashBankTenantReferences($data, (int) $receipt->company_id, SalesInvoice::class);
            $data = $this->normalizeCashBankTenantReferences($data, (int) $receipt->company_id);
            $this->periodMutationGuard->assertUpdate($receipt, $data, 'sửa phiếu thu tiền mặt');
            $this->assertMutableSource($receipt, 'sửa');
            $before = $receipt->load('lines')->toArray();

            $totalAmount = collect($data['lines'] ?? [])->sum('amount');

            $receipt->update([
                'voucher_type' => $data['voucher_type'] ?? $receipt->voucher_type,
                'contact_type' => $data['contact_type'] ?? $receipt->contact_type,
                'contact_id' => $data['contact_id'] ?? $receipt->contact_id,
                'contact_name' => $data['contact_name'] ?? $receipt->contact_name,
                'voucher_number' => $data['voucher_number'] ?? $receipt->voucher_number,
                'voucher_date' => $data['voucher_date'] ?? $receipt->voucher_date,
                'posting_date' => $data['posting_date'] ?? $receipt->posting_date,
                'payer_name' => $data['payer_name'] ?? $receipt->payer_name,
                'payer_address' => $data['payer_address'] ?? $receipt->payer_address,
                'employee_id' => $data['employee_id'] ?? $receipt->employee_id,
                'employee_name' => $data['employee_name'] ?? $receipt->employee_name,
                'reason' => $data['reason'] ?? $data['description'] ?? $receipt->reason,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? $receipt->referenced_vouchers,
                'attached_docs' => $data['attached_docs'] ?? $receipt->attached_docs,
                'currency' => $data['currency'] ?? $receipt->currency,
                'exchange_rate' => $data['exchange_rate'] ?? $receipt->exchange_rate,
                'total_amount' => $totalAmount ?: $receipt->total_amount,
            ]);

            if (isset($data['lines'])) {
                $receipt->lines()->delete();
                foreach ($data['lines'] as $line) {
                    $receipt->lines()->create([
                        'description' => $line['description'] ?? $receipt->reason,
                        'debit_account' => $this->accountOrLegacyFallback($line['debit_account'] ?? null, 'debit_account', '1111'),
                        'credit_account' => $this->accountOrLegacyFallback($line['credit_account'] ?? null, 'credit_account', '131'),
                        'amount' => $line['amount'] ?? 0,
                        'operation' => $line['operation'] ?? null,
                        'loan_contract' => $line['loan_contract'] ?? null,
                        'line_contact_id' => $line['line_contact_id'] ?? null,
                        'line_contact_name' => $line['line_contact_name'] ?? null,
                        'invoice_id' => $line['invoice_id'] ?? null,
                    ]);
                }
            }

            // Cập nhật lại voucher_references
            if (isset($data['referenced_vouchers'])) {
                $receipt->syncReferences($data['referenced_vouchers']);
            }

            $this->recordSourceAudit($receipt, 'cash_receipt.updated', $before, $receipt->fresh('lines')->toArray());

            return $receipt->fresh(['lines', 'references', 'referencedBy']);
        });
    }

    public function delete($id)
    {
        return DB::transaction(function () use ($id) {
            $receipt = $this->scopeMutationToAuthenticatedCompany(CashReceipt::query())->findOrFail($id);
            $this->periodMutationGuard->assertExisting($receipt, 'xóa phiếu thu tiền mặt');
            $this->assertMutableSource($receipt, 'xóa');
            $before = $receipt->load('lines')->toArray();
            $receipt->lines()->delete();
            $receipt->references()->delete();
            $receipt->delete();
            $this->recordSourceAudit($receipt, 'cash_receipt.deleted', $before, ['deleted' => true]);

            return true;
        });
    }

    private function assertMutableSource(CashReceipt $receipt, string $operation): void
    {
        $status = strtolower(trim((string) $receipt->status));
        if ($receipt->is_posted || $status === 'posted') {
            throw new ConflictHttpException("Không thể {$operation} phiếu thu tiền mặt đã ghi sổ. Vui lòng bỏ ghi sổ trước khi {$operation}.");
        }

        if (in_array($status, ['voided', 'cancelled', 'canceled'], true)) {
            throw new ConflictHttpException("Không thể {$operation} phiếu thu tiền mặt đã hủy.");
        }
    }

    public function duplicate($id): CashReceipt
    {
        return DB::transaction(function () use ($id) {
            $original = $this->scopeMutationToAuthenticatedCompany(CashReceipt::with(['lines', 'references', 'referencedBy']))->findOrFail($id);
            $this->periodMutationGuard->assertDuplicate($original, 'nhân bản phiếu thu tiền mặt');

            $newVoucherNumber = 'PT'.rand(10000, 99999);
            while (CashReceipt::where('company_id', $original->company_id)->where('voucher_number', $newVoucherNumber)->exists()) {
                $newVoucherNumber = 'PT'.rand(10000, 99999);
            }

            $duplicate = CashReceipt::create([
                'company_id' => $original->company_id,
                'contact_type' => $original->contact_type,
                'contact_id' => $original->contact_id,
                'contact_name' => $original->contact_name,
                'voucher_number' => $newVoucherNumber,
                'voucher_date' => now()->toDateString(),
                'posting_date' => now()->toDateString(),
                'payer_name' => $original->payer_name,
                'payer_address' => $original->payer_address,
                'employee_id' => $original->employee_id,
                'employee_name' => $original->employee_name,
                'reason' => '(Nhân bản) '.$original->reason,
                'currency' => $original->currency,
                'exchange_rate' => $original->exchange_rate,
                'total_amount' => $original->total_amount,
                'is_posted' => false,
            ]);

            foreach ($original->lines as $line) {
                $duplicate->lines()->create([
                    'description' => $line->description,
                    'debit_account' => $line->debit_account,
                    'credit_account' => $line->credit_account,
                    'amount' => $line->amount,
                    'operation' => $line->operation,
                    'loan_contract' => $line->loan_contract,
                    'line_contact_id' => $line->line_contact_id,
                    'line_contact_name' => $line->line_contact_name,
                    'invoice_id' => $line->invoice_id,
                ]);
            }

            $refData = $original->referenced_vouchers;
            if (empty($refData) && $original->references->isNotEmpty()) {
                $refData = $original->references->map(function ($r) {
                    return [
                        'target_type' => $r->target_type,
                        'target_id' => $r->target_id,
                        'voucher_type' => $r->target_voucher_type,
                        'voucher_number' => $r->target_voucher_number,
                        'voucher_date' => $r->target_voucher_date,
                        'total_amount' => $r->target_total_amount,
                        'description' => $r->description,
                    ];
                })->toArray();
            }
            if (! empty($refData)) {
                $duplicate->referenced_vouchers = $refData;
                $duplicate->save();
                $duplicate->syncReferences($refData);
            }

            $this->recordSourceAudit($duplicate, 'cash_receipt.duplicated', [], $duplicate->fresh('lines')->toArray(), ['original_source_id' => $original->id]);

            return $duplicate->load(['lines', 'references']);
        });
    }

    public function post($id)
    {
        return DB::transaction(function () use ($id) {
            $receipt = $this->scopeMutationToAuthenticatedCompany(CashReceipt::with('lines')->lockForUpdate())->findOrFail($id);
            $this->periodMutationGuard->assertExisting($receipt, 'ghi sổ phiếu thu tiền mặt');
            $before = $receipt->toArray();
            if ($receipt->is_posted) {
                throw new \Exception('Voucher is already posted');
            }
            $authorization = $this->postingAuthorizer->authorize('cash.receipts.post', (int) $receipt->company_id);
            $policy = ($this->requiresCashBankPolicy() || $this->requiresCashBankAccountMappings()) ? $this->accountingPolicyResolver->requireForVoucher((int) $receipt->company_id, ($receipt->posting_date ?? $receipt->voucher_date ?? now())->toDateString(), SystemVoucherType::CASH_RECEIPT) : null;
            $accountMappings = $this->requiresCashBankAccountMappings() ? $this->accountMappingGate->requireSatisfied($receipt, $policy) : null;

            $glLines = [];
            foreach ($receipt->lines as $line) {
                $debitAccount = $accountMappings !== null ? CashBankVoucherAccountMappingPostingGate::accountFor($accountMappings, $receipt, 'debit', (string) $line->debit_account) : $this->accountOrLegacyFallback($line->debit_account, 'debit_account', '1111');
                $creditAccount = $accountMappings !== null ? CashBankVoucherAccountMappingPostingGate::accountFor($accountMappings, $receipt, 'credit', (string) $line->credit_account) : $this->accountOrLegacyFallback($line->credit_account, 'credit_account', '131');
                $glLines[] = [
                    'account_code' => $debitAccount,
                    'description' => $line->description ?: $receipt->reason,
                    'debit_amount' => $line->amount,
                    'credit_amount' => 0,
                ];
                $glLines[] = [
                    'account_code' => $creditAccount,
                    'description' => $line->description ?: $receipt->reason,
                    'debit_amount' => 0,
                    'credit_amount' => $line->amount,
                ];

                if ($line->invoice_id) {
                    SalesInvoice::where('company_id', $receipt->company_id)
                        ->where('id', $line->invoice_id)
                        ->update(['status' => 'Paid']);
                    $this->settlementStatusPolicy->sync(SalesInvoice::where('company_id', $receipt->company_id)->findOrFail($line->invoice_id));
                }
            }

            $je = $this->journalEntryService->createPosted([
                'company_id' => $receipt->company_id,
                'voucher_type' => 'cash_receipt',
                'voucher_number' => 'GL-CR-'.$receipt->voucher_number,
                'voucher_date' => $receipt->voucher_date,
                'posting_date' => $receipt->posting_date ?? now()->toDateString(),
                'description' => $receipt->reason ?? 'Thu tiền mặt',
                'total_amount' => 0,
                'status' => 'posted',
                'source_document_type' => CashReceipt::class,
                'source_document_id' => $receipt->id,
                'lines' => $glLines,
            ]);

            $receipt->journal_entry_id = $je->id;
            $receipt->is_posted = true;
            // Keep the persisted lifecycle fields in lock-step.  MISA exposes
            // one voucher state; returning `draft` with is_posted=true makes
            // list/detail consumers disagree about whether the voucher is
            // editable.
            $receipt->status = 'posted';
            $receipt->save();
            $this->recordControlLineage($receipt, $policy, $authorization, $accountMappings, $je->id);
            $this->recordSourceAudit($receipt, 'cash_receipt.posted', $before, $receipt->fresh()->toArray(), array_filter(['accounting_policy' => $policy, 'posting_authorization' => $authorization, 'account_mappings' => $accountMappings]));

            return $receipt;
        });
    }

    private function requiresCashBankPolicy(): bool { return (bool) config('accounting.enforce_cash_bank_posting_policy', true); }
    private function requiresCashBankAccountMappings(): bool { return (bool) config('accounting.enforce_cash_bank_posting_account_mappings', true); }

    /**
     * The legacy literals remain available to non-production diagnostic flows,
     * but a production voucher must carry explicit account evidence. This
     * validates the input only; it does not infer a TT99 account mapping.
     *
     * @param array<int, mixed> $lines
     */
    private function assertProductionExplicitLineAccounts(array $lines): void
    {
        if (! in_array(strtolower((string) config('app.env')), ['production', 'prod'], true)) {
            return;
        }

        $errors = [];
        foreach ($lines as $index => $line) {
            foreach (['debit_account', 'credit_account'] as $field) {
                if (! is_array($line) || trim((string) ($line[$field] ?? '')) === '') {
                    $errors["lines.{$index}.{$field}"] = 'Production requires an explicit account code; no default account is inferred.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function accountOrLegacyFallback(mixed $account, string $field, string $legacyFallback): string
    {
        $account = trim((string) $account);
        if ($account !== '') {
            return $account;
        }

        if (in_array(strtolower((string) config('app.env')), ['production', 'prod'], true)) {
            throw ValidationException::withMessages([
                "lines.0.{$field}" => 'Production requires an explicit account code; no default account is inferred.',
            ]);
        }

        return $legacyFallback;
    }
    private function recordControlLineage(CashReceipt $voucher, ?array $policy, array $authorization, ?array $accountMappings, int $journalEntryId): void { if ($policy !== null) $this->auditService->record($voucher, 'cash_receipt.policy_applied', [], [], null, ['journal_entry_id' => $journalEntryId, 'accounting_policy' => $policy]); $this->auditService->record($voucher, 'cash_receipt.posting_authorization_applied', [], [], null, ['journal_entry_id' => $journalEntryId] + $authorization); if ($accountMappings !== null) $this->auditService->record($voucher, 'cash_receipt.account_mappings_applied', [], [], null, ['journal_entry_id' => $journalEntryId, 'account_mapping_gate' => 'enforced', 'account_mappings' => $accountMappings]); }

    /**
     * Bỏ ghi sổ (Unpost) — hủy bút toán sổ cái, đưa chứng từ về trạng thái nháp
     * Khác với Void: chứng từ vẫn tồn tại và có thể sửa, ghi sổ lại
     */
    public function unpost($id)
    {
        return DB::transaction(function () use ($id) {
            $receipt = $this->scopeMutationToAuthenticatedCompany(CashReceipt::query())->findOrFail($id);
            $this->periodMutationGuard->assertExisting($receipt, 'bỏ ghi sổ phiếu thu tiền mặt');
            $before = $receipt->toArray();
            if (! $receipt->is_posted) {
                throw new \Exception('Chứng từ chưa được ghi sổ');
            }
            $this->assertHasNoPostedSettlementAllocations((int) $receipt->company_id, 'cash_receipt', (int) $receipt->id);
            $this->assertNoPostedDependentDocuments((int) $receipt->company_id, CashReceipt::class, (int) $receipt->id);

            if ($receipt->journal_entry_id) {
                $this->journalEntryService->void($receipt->journal_entry_id, (int) $receipt->company_id);
                $receipt->journal_entry_id = null;
            }

            $receipt->is_posted = false;
            $receipt->status = 'draft';
            $receipt->save();
            $this->recordSourceAudit($receipt, 'cash_receipt.unposted', $before, $receipt->fresh()->toArray(), ['journal_entry_id' => $before['journal_entry_id'] ?? null]);

            return $receipt;
        });
    }

    /**
     * Hủy chứng từ (Void) — hủy hoàn toàn, đánh dấu trạng thái voided
     */
    public function void($id)
    {
        return DB::transaction(function () use ($id) {
            $receipt = $this->scopeMutationToAuthenticatedCompany(CashReceipt::query())->findOrFail($id);
            $this->periodMutationGuard->assertExisting($receipt, 'hủy phiếu thu tiền mặt');
            $before = $receipt->toArray();
            $this->assertHasNoPostedSettlementAllocations((int) $receipt->company_id, 'cash_receipt', (int) $receipt->id);
            $this->assertNoPostedDependentDocuments((int) $receipt->company_id, CashReceipt::class, (int) $receipt->id);

            if ($receipt->journal_entry_id) {
                $this->journalEntryService->void($receipt->journal_entry_id, (int) $receipt->company_id);
            }

            foreach ($receipt->lines as $line) {
                if ($line->invoice_id) {
                    SalesInvoice::where('company_id', $receipt->company_id)
                        ->where('id', $line->invoice_id)
                        ->update(['status' => 'Unpaid']);
                    $this->settlementStatusPolicy->sync(SalesInvoice::where('company_id', $receipt->company_id)->findOrFail($line->invoice_id));
                }
            }

            $receipt->is_posted = false;
            $receipt->status = 'voided';
            $receipt->save();
            $this->recordSourceAudit($receipt, 'cash_receipt.voided', $before, $receipt->fresh()->toArray(), ['journal_entry_id' => $before['journal_entry_id'] ?? null]);

            return $receipt;
        });
    }

    /**
     * Lấy mã phiếu thu tiếp theo dạng PT00001
     */
    public function getNextCode(int $companyId): string
    {
        $companyId = $this->requireCompanyId(['company_id' => $companyId]);
        $last = CashReceipt::where('company_id', $companyId)
            ->where('voucher_number', 'like', 'PT%')
            ->orderBy('voucher_number', 'desc')
            ->value('voucher_number');

        if ($last) {
            $num = (int) substr($last, 2);
            $next = $num + 1;
        } else {
            $next = 1;
        }

        return 'PT'.str_pad($next, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Lọc và làm sạch payload theo từng lý do thu tiền để chống chèn dữ liệu chéo trái phép
     */
    protected function sanitizeReceiptData(array $data): array
    {
        $voucherType = $data['voucher_type'] ?? '1. Thu tiền khách hàng (không theo hóa đơn)';
        $isExactThuKhachHang = ($voucherType === '1. Thu tiền khách hàng (không theo hóa đơn)');
        $isThuHoiVay = str_contains($voucherType, '4. Thu hồi') || str_contains($voucherType, 'cho vay');

        // Đối với chứng từ chuẩn thu tiền khách hàng (không theo hóa đơn), không lưu nhân viên
        if ($isExactThuKhachHang) {
            $data['employee_id'] = null;
            $data['employee_name'] = null;
        } elseif (! empty($data['employee_id']) && empty($data['employee_name'])) {
            $emp = Employee::where('company_id', $this->requireCompanyId($data))
                ->find($data['employee_id']);
            if ($emp) {
                $data['employee_name'] = $emp->name;
            }
        }

        // Chỉ có lý do Thu hồi cho vay mới được phép lưu khế ước vay
        if (! $isThuHoiVay && ! empty($data['lines'])) {
            foreach ($data['lines'] as &$line) {
                $line['loan_contract'] = null;
            }
        }

        return $data;
    }

    /**
     * Scope lifecycle resource loads when an authenticated caller exists.
     * Internal CLI/queue callers without an auth guard retain their explicit
     * company context; authenticated callers can never address another tenant.
     */
    private function scopeMutationToAuthenticatedCompany($query)
    {
        $actor = auth()->user();
        if ($actor !== null) {
            $companyId = (int) ($actor->company_id ?? 0);
            if ($companyId <= 0) {
                throw ValidationException::withMessages(['company_id' => 'An authenticated company context is required.']);
            }
            $query->where('company_id', $companyId);
        }

        return $query;
    }

    private function requireCompanyId(array $data): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        $resolvedCompanyId = $data['company_id'] ?? $actorCompanyId;
        if ($resolvedCompanyId === null || (int) $resolvedCompanyId <= 0) {
            throw ValidationException::withMessages([
                'company_id' => 'An authenticated company context is required.',
            ]);
        }
        if ($actorCompanyId !== null && (int) $resolvedCompanyId !== (int) $actorCompanyId) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return (int) $resolvedCompanyId;
    }
}
