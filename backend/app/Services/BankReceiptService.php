<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Models\BankAccount;
use App\Models\BankReceipt;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Services\Concerns\GuardsCashBankTenantReferences;
use App\Services\Concerns\GuardsPostedDependentDocuments;
use App\Services\Concerns\GuardsPostedSettlementAllocations;
use App\Services\Concerns\RecordsSourceAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankReceiptService
{
    use GuardsCashBankTenantReferences, GuardsPostedDependentDocuments, GuardsPostedSettlementAllocations, RecordsSourceAudit;

    protected JournalEntryService $journalEntryService;

    public function __construct(
        JournalEntryService $journalEntryService,
        private readonly AuditService $auditService,
        private readonly AccountingPolicyResolver $accountingPolicyResolver,
        private readonly CashBankVoucherPostingApprovalGate $approvalGate,
        private readonly CashBankVoucherAccountMappingPostingGate $accountMappingGate,
        private readonly CashBankVoucherPeriodMutationGuard $periodMutationGuard,
        private readonly ApArSettlementStatusPolicy $settlementStatusPolicy,
    )
    {
        $this->journalEntryService = $journalEntryService;
    }

    public function getAll(?int $companyId = null)
    {
        $companyId = $this->requireCompanyId(['company_id' => $companyId]);
        return BankReceipt::with(['lines', 'bankAccount', 'employee'])
            ->where('company_id', $companyId)
            ->orderBy('voucher_date', 'desc')
            ->get();
    }

    public function getById($id, ?int $companyId = null)
    {
        $companyId = $this->requireCompanyId(['company_id' => $companyId]);

        return BankReceipt::with(['lines', 'bankAccount', 'employee', 'references', 'referencedBy'])
            ->where('company_id', $companyId)
            ->findOrFail($id);
    }

    /**
     * Resolve string contact code to integer ID if applicable
     */
    private function resolveContactId($contactId, ?string $contactType, int $companyId, string $field = 'contact_id'): ?int
    {
        return $this->resolveCashBankTenantContactId($contactId, $contactType, $companyId, $field);
    }

    public function create(array $data): BankReceipt
    {
        $companyId = $this->requireCompanyId($data);
        $this->assertBankAccountBelongsToCompany($data['bank_account_id'] ?? null, $companyId);
        $this->assertLineBankAccountsBelongToCompany($data['lines'] ?? [], $companyId);
        $this->assertCashBankTenantReferences($data, $companyId, SalesInvoice::class);
        $this->assertProductionExplicitLineAccounts($data['lines'] ?? []);

        return DB::transaction(function () use ($data, $companyId) {
            $this->periodMutationGuard->assertCreate($companyId, $data, 'lập phiếu thu tiền gửi');
            $totalAmount = collect($data['lines'] ?? [])->sum('amount');
            $contactId = $this->resolveContactId($data['contact_id'] ?? null, $data['contact_type'] ?? null, $companyId);

            $receipt = BankReceipt::create([
                'company_id' => $companyId,
                'voucher_type' => $data['voucher_type'] ?? 'customer_payment',
                'contact_type' => $data['contact_type'] ?? null,
                'contact_id' => $contactId,
                'contact_name' => $data['contact_name'] ?? null,
                'bank_account_id' => $data['bank_account_id'],
                'employee_id' => $data['employee_id'] ?? null,
                'employee_name' => $data['employee_name'] ?? null,
                'voucher_number' => $data['voucher_number'] ?? $this->generateNextCode($companyId),
                'voucher_date' => $data['voucher_date'] ?? now()->toDateString(),
                'posting_date' => $data['posting_date'] ?? $data['voucher_date'] ?? now()->toDateString(),
                'payer_name' => $data['payer_name'] ?? $data['contact_name'] ?? null,
                'payer_address' => $data['payer_address'] ?? null,
                'payer_bank_account' => $data['payer_bank_account'] ?? null,
                'description' => $data['description'] ?? $data['reason'] ?? 'Thu tiền gửi',
                'attached_docs' => $data['attached_docs'] ?? null,
                'currency' => $data['currency'] ?? 'VND',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'amount' => $totalAmount,
                'status' => $data['status'] ?? 'draft',
                'is_posted' => false,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
                'updated_by' => $data['updated_by'] ?? auth()->id(),
            ]);

            if (! empty($data['lines'])) {
                foreach ($data['lines'] as $line) {
                    $lineContactId = $this->resolveContactId($line['line_contact_id'] ?? null, $line['line_contact_type'] ?? null, $companyId, 'line_contact_id');
                    $receipt->lines()->create([
                        'description' => $line['description'] ?? $receipt->description,
                        'debit_account' => $this->accountOrLegacyFallback($line['debit_account'] ?? null, 'debit_account', '1121'),
                        'credit_account' => $this->accountOrLegacyFallback($line['credit_account'] ?? null, 'credit_account', '131'),
                        'amount' => $line['amount'] ?? 0,
                        'invoice_id' => $line['invoice_id'] ?? null,
                        'operation' => $line['operation'] ?? null,
                        'loan_contract' => $line['loan_contract'] ?? null,
                        'line_contact_id' => $lineContactId,
                        'line_contact_name' => $line['line_contact_name'] ?? null,
                        'bank_account_id' => $line['bank_account_id'] ?? null,
                    ]);
                }
            }

            if (isset($data['referenced_vouchers'])) {
                $receipt->syncReferences($data['referenced_vouchers']);
            }

            $this->recordSourceAudit($receipt, 'bank_receipt.created', [], $receipt->fresh('lines')->toArray());

            return $receipt->load(['lines', 'bankAccount', 'employee', 'references']);
        });
    }

    public function update($id, array $data): BankReceipt
    {
        if (array_key_exists('lines', $data)) {
            $this->assertProductionExplicitLineAccounts($data['lines']);
        }

        return DB::transaction(function () use ($id, $data) {
            $receipt = $this->scopeMutationToAuthenticatedCompany(BankReceipt::query())->findOrFail($id);
            $referenceData = $data;
            if (array_key_exists('contact_id', $referenceData) && ! array_key_exists('contact_type', $referenceData)) {
                $referenceData['contact_type'] = $receipt->contact_type;
            }
            $this->assertCashBankTenantReferences($referenceData, (int) $receipt->company_id, SalesInvoice::class);
            $this->periodMutationGuard->assertUpdate($receipt, $data, 'sửa phiếu thu tiền gửi');
            $before = $receipt->load('lines')->toArray();
            $this->assertBankAccountBelongsToCompany($data['bank_account_id'] ?? $receipt->bank_account_id, $receipt->company_id);
            $this->assertLineBankAccountsBelongToCompany($data['lines'] ?? [], $receipt->company_id);
            $totalAmount = collect($data['lines'] ?? [])->sum('amount');
            $contactId = isset($data['contact_id'])
                ? $this->resolveContactId($data['contact_id'], $data['contact_type'] ?? $receipt->contact_type, $receipt->company_id)
                : $receipt->contact_id;

            $receipt->update([
                'voucher_type' => $data['voucher_type'] ?? $receipt->voucher_type,
                'contact_type' => $data['contact_type'] ?? $receipt->contact_type,
                'contact_id' => $contactId,
                'contact_name' => $data['contact_name'] ?? $receipt->contact_name,
                'bank_account_id' => $data['bank_account_id'] ?? $receipt->bank_account_id,
                'employee_id' => $data['employee_id'] ?? $receipt->employee_id,
                'employee_name' => $data['employee_name'] ?? $receipt->employee_name,
                'voucher_number' => $data['voucher_number'] ?? $receipt->voucher_number,
                'voucher_date' => $data['voucher_date'] ?? $receipt->voucher_date,
                'posting_date' => $data['posting_date'] ?? $receipt->posting_date,
                'payer_name' => $data['payer_name'] ?? $receipt->payer_name,
                'payer_address' => $data['payer_address'] ?? $receipt->payer_address,
                'payer_bank_account' => $data['payer_bank_account'] ?? $receipt->payer_bank_account,
                'description' => $data['description'] ?? $data['reason'] ?? $receipt->description,
                'attached_docs' => $data['attached_docs'] ?? $receipt->attached_docs,
                'currency' => $data['currency'] ?? $receipt->currency,
                'exchange_rate' => $data['exchange_rate'] ?? $receipt->exchange_rate,
                'amount' => $totalAmount ?: $receipt->amount,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? $receipt->referenced_vouchers,
                'updated_by' => $data['updated_by'] ?? auth()->id(),
            ]);

            if (isset($data['lines'])) {
                $receipt->lines()->delete();
                foreach ($data['lines'] as $line) {
                    $lineContactId = $this->resolveContactId($line['line_contact_id'] ?? null, $line['line_contact_type'] ?? null, $receipt->company_id, 'line_contact_id');
                    $receipt->lines()->create([
                        'description' => $line['description'] ?? $receipt->description,
                        'debit_account' => $this->accountOrLegacyFallback($line['debit_account'] ?? null, 'debit_account', '1121'),
                        'credit_account' => $this->accountOrLegacyFallback($line['credit_account'] ?? null, 'credit_account', '131'),
                        'amount' => $line['amount'] ?? 0,
                        'invoice_id' => $line['invoice_id'] ?? null,
                        'operation' => $line['operation'] ?? null,
                        'loan_contract' => $line['loan_contract'] ?? null,
                        'line_contact_id' => $lineContactId,
                        'line_contact_name' => $line['line_contact_name'] ?? null,
                        'bank_account_id' => $line['bank_account_id'] ?? null,
                    ]);
                }
            }

            if (isset($data['referenced_vouchers'])) {
                $receipt->syncReferences($data['referenced_vouchers']);
            }

            $this->recordSourceAudit($receipt, 'bank_receipt.updated', $before, $receipt->fresh('lines')->toArray());

            return $receipt->load(['lines', 'bankAccount', 'employee', 'references']);
        });
    }

    public function delete($id)
    {
        return DB::transaction(function () use ($id) {
            $receipt = $this->scopeMutationToAuthenticatedCompany(BankReceipt::query())->findOrFail($id);
            $this->periodMutationGuard->assertExisting($receipt, 'xóa phiếu thu tiền gửi');
            $before = $receipt->load('lines')->toArray();
            if ($receipt->is_posted) {
                $this->void($id);
            }
            $receipt->lines()->delete();
            $receipt->references()->delete();
            $receipt->delete();
            $this->recordSourceAudit($receipt, 'bank_receipt.deleted', $before, ['deleted' => true]);

            return true;
        });
    }

    public function post($id)
    {
        return DB::transaction(function () use ($id) {
            $receipt = $this->scopeMutationToAuthenticatedCompany(BankReceipt::with('lines')->lockForUpdate())->findOrFail($id);
            $this->periodMutationGuard->assertExisting($receipt, 'ghi sổ phiếu thu tiền gửi');
            $before = $receipt->toArray();
            if ($receipt->is_posted) {
                throw new \Exception('Voucher is already posted');
            }
            $policy = ($this->requiresCashBankPolicy() || $this->requiresCashBankAccountMappings()) ? $this->accountingPolicyResolver->requireForVoucher((int) $receipt->company_id, ($receipt->posting_date ?? $receipt->voucher_date ?? now())->toDateString(), SystemVoucherType::BANK_RECEIPT) : null;
            $approval = $this->requiresCashBankApproval() ? $this->approvalGate->requireApproved($receipt, auth()->id()) : null;
            $accountMappings = $this->requiresCashBankAccountMappings() ? $this->accountMappingGate->requireSatisfied($receipt, $policy) : null;

            $glLines = [];
            foreach ($receipt->lines as $line) {
                $debitAccount = $accountMappings !== null ? CashBankVoucherAccountMappingPostingGate::accountFor($accountMappings, $receipt, 'debit', (string) $line->debit_account) : $this->accountOrLegacyFallback($line->debit_account, 'debit_account', '1121');
                $creditAccount = $accountMappings !== null ? CashBankVoucherAccountMappingPostingGate::accountFor($accountMappings, $receipt, 'credit', (string) $line->credit_account) : $this->accountOrLegacyFallback($line->credit_account, 'credit_account', '131');
                $glLines[] = [
                    'account_code' => $debitAccount,
                    'description' => $line->description ?: $receipt->description,
                    'debit_amount' => $line->amount,
                    'credit_amount' => 0,
                ];
                $glLines[] = [
                    'account_code' => $creditAccount,
                    'description' => $line->description ?: $receipt->description,
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
                'voucher_type' => 'bank_receipt',
                'voucher_number' => 'GL-BR-'.$receipt->voucher_number,
                'voucher_date' => $receipt->voucher_date,
                'posting_date' => $receipt->posting_date ?? now()->toDateString(),
                'description' => $receipt->description,
                'total_amount' => 0,
                'status' => 'posted',
                'source_document_type' => BankReceipt::class,
                'source_document_id' => $receipt->id,
                'lines' => $glLines,
            ]);

            $receipt->journal_entry_id = $je->id;
            $receipt->is_posted = true;
            $receipt->status = 'posted';
            $receipt->save();
            $this->recordControlLineage($receipt, $policy, $approval, $accountMappings, $je->id);
            $this->recordSourceAudit($receipt, 'bank_receipt.posted', $before, $receipt->fresh()->toArray(), array_filter(['accounting_policy' => $policy, 'approval' => $approval, 'account_mappings' => $accountMappings]));

            return $receipt;
        });
    }

    private function requiresCashBankPolicy(): bool { return (bool) config('accounting.enforce_cash_bank_posting_policy', true); }
    private function requiresCashBankApproval(): bool { return (bool) config('accounting.enforce_cash_bank_posting_approval', true); }
    private function requiresCashBankAccountMappings(): bool { return (bool) config('accounting.enforce_cash_bank_posting_account_mappings', true); }

    /** @param array<int, mixed> $lines */
    private function assertProductionExplicitLineAccounts(array $lines): void
    {
        if (! $this->isProductionEnvironment()) {
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
        if ($this->isProductionEnvironment()) {
            throw ValidationException::withMessages([
                "lines.0.{$field}" => 'Production requires an explicit account code; no default account is inferred.',
            ]);
        }

        return $legacyFallback;
    }

    private function isProductionEnvironment(): bool
    {
        return in_array(strtolower((string) config('app.env')), ['production', 'prod'], true);
    }

    private function recordControlLineage(BankReceipt $voucher, ?array $policy, ?array $approval, ?array $accountMappings, int $journalEntryId): void { if ($policy !== null) $this->auditService->record($voucher, 'bank_receipt.policy_applied', [], [], null, ['journal_entry_id' => $journalEntryId, 'accounting_policy' => $policy]); if ($approval !== null) $this->auditService->record($voucher, 'bank_receipt.approval_applied', [], [], null, ['journal_entry_id' => $journalEntryId, 'approval_gate' => 'enforced', 'approval' => $approval]); if ($accountMappings !== null) $this->auditService->record($voucher, 'bank_receipt.account_mappings_applied', [], [], null, ['journal_entry_id' => $journalEntryId, 'account_mapping_gate' => 'enforced', 'account_mappings' => $accountMappings]); }

    public function void($id)
    {
        return DB::transaction(function () use ($id) {
            $receipt = $this->scopeMutationToAuthenticatedCompany(BankReceipt::query())->findOrFail($id);
            $this->periodMutationGuard->assertExisting($receipt, 'hủy phiếu thu tiền gửi');
            $before = $receipt->toArray();
            if (! $receipt->is_posted) {
                throw new \Exception('Voucher is not posted yet');
            }
            $this->assertHasNoPostedSettlementAllocations((int) $receipt->company_id, 'bank_receipt', (int) $receipt->id);
            $this->assertNoPostedDependentDocuments((int) $receipt->company_id, BankReceipt::class, (int) $receipt->id);

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
            $this->recordSourceAudit($receipt, 'bank_receipt.voided', $before, $receipt->fresh()->toArray(), ['journal_entry_id' => $before['journal_entry_id'] ?? null]);

            return $receipt;
        });
    }

    public function unpost($id): BankReceipt
    {
        return DB::transaction(function () use ($id) {
            $receipt = $this->scopeMutationToAuthenticatedCompany(BankReceipt::query())->findOrFail($id);
            $this->periodMutationGuard->assertExisting($receipt, 'bỏ ghi sổ phiếu thu tiền gửi');
            $before = $receipt->toArray();
            if (! $receipt->is_posted) {
                throw new \Exception('Voucher is not posted yet');
            }
            $this->assertHasNoPostedSettlementAllocations((int) $receipt->company_id, 'bank_receipt', (int) $receipt->id);
            $this->assertNoPostedDependentDocuments((int) $receipt->company_id, BankReceipt::class, (int) $receipt->id);

            if ($receipt->journal_entry_id) {
                $this->journalEntryService->void($receipt->journal_entry_id, (int) $receipt->company_id);
                $receipt->journal_entry_id = null;
            }

            $receipt->is_posted = false;
            $receipt->status = 'draft';
            $receipt->save();
            $this->recordSourceAudit($receipt, 'bank_receipt.unposted', $before, $receipt->fresh()->toArray(), ['journal_entry_id' => $before['journal_entry_id'] ?? null]);

            return $receipt;
        });
    }

    public function duplicate($id): BankReceipt
    {
        return DB::transaction(function () use ($id) {
            $original = $this->scopeMutationToAuthenticatedCompany(BankReceipt::with('lines'))->findOrFail($id);
            $this->periodMutationGuard->assertDuplicate($original, 'nhân bản phiếu thu tiền gửi');
            $newVoucherNumber = 'BC-'.now()->format('Ymd').'-'.strtoupper(substr(uniqid(), -4));

            $newReceipt = $original->replicate();
            $newReceipt->voucher_number = $newVoucherNumber;
            $newReceipt->voucher_date = now()->toDateString();
            $newReceipt->posting_date = now()->toDateString();
            $newReceipt->is_posted = false;
            $newReceipt->journal_entry_id = null;
            $newReceipt->status = 'draft';
            $newReceipt->save();

            foreach ($original->lines as $line) {
                $newLine = $line->replicate();
                $newLine->bank_receipt_id = $newReceipt->id;
                $newLine->save();
            }

            if ($original->referenced_vouchers) {
                $newReceipt->syncReferences($original->referenced_vouchers);
            }

            $this->recordSourceAudit($newReceipt, 'bank_receipt.duplicated', [], $newReceipt->fresh('lines')->toArray(), ['original_source_id' => $original->id]);

            return $newReceipt->load(['lines', 'bankAccount', 'employee', 'references']);
        });
    }

    public function generateNextCode(int $companyId): string
    {
        $companyId = $this->requireCompanyId(['company_id' => $companyId]);
        $year = now()->format('Y');
        $prefix = 'BC-'.$year.'-';
        $latest = BankReceipt::where('company_id', $companyId)
            ->where('voucher_number', 'like', $prefix.'%')
            ->orderBy('id', 'desc')
            ->value('voucher_number');

        if ($latest && preg_match('/'.preg_quote($prefix, '/').'(\d+)/', $latest, $m)) {
            $nextSeq = str_pad((int) $m[1] + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $count = BankReceipt::where('company_id', $companyId)->count() + 1;
            $nextSeq = str_pad($count, 4, '0', STR_PAD_LEFT);
        }

        return $prefix.$nextSeq;
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
            throw ValidationException::withMessages(['company_id' => 'An authenticated company context is required.']);
        }
        if ($actorCompanyId !== null && (int) $resolvedCompanyId !== (int) $actorCompanyId) {
            throw ValidationException::withMessages(['company_id' => 'The requested company does not belong to the authenticated user.']);
        }

        return (int) $resolvedCompanyId;
    }

    private function assertBankAccountBelongsToCompany(mixed $bankAccountId, int $companyId): void
    {
        if (empty($bankAccountId) || ! BankAccount::where('company_id', $companyId)->whereKey($bankAccountId)->exists()) {
            throw ValidationException::withMessages([
                'bank_account_id' => 'The selected bank account does not belong to the active company.',
            ]);
        }
    }

    private function assertLineBankAccountsBelongToCompany(array $lines, int $companyId): void
    {
        foreach ($lines as $index => $line) {
            if (! empty($line['bank_account_id'])
                && ! BankAccount::where('company_id', $companyId)->whereKey($line['bank_account_id'])->exists()) {
                throw ValidationException::withMessages([
                    "lines.$index.bank_account_id" => 'The selected bank account does not belong to the active company.',
                ]);
            }
        }
    }
}
