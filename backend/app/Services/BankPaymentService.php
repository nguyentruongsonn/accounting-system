<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Models\BankAccount;
use App\Models\BankPayment;
use App\Models\Customer;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Services\Concerns\GuardsCashBankTenantReferences;
use App\Services\Concerns\GuardsPostedDependentDocuments;
use App\Services\Concerns\GuardsPostedSettlementAllocations;
use App\Services\Concerns\RecordsSourceAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankPaymentService
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
        return BankPayment::with(['lines', 'bankAccount', 'employee'])
            ->where('company_id', $companyId)
            ->orderBy('voucher_date', 'desc')
            ->get();
    }

    public function getById($id, ?int $companyId = null)
    {
        $companyId = $this->requireCompanyId(['company_id' => $companyId]);

        return BankPayment::with(['lines', 'bankAccount', 'employee', 'references', 'referencedBy'])
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

    public function create(array $data): BankPayment
    {
        $companyId = $this->requireCompanyId($data);
        $this->assertBankAccountBelongsToCompany($data['bank_account_id'] ?? null, $companyId);
        $this->assertLineBankAccountsBelongToCompany($data['lines'] ?? [], $companyId);
        $this->assertCashBankTenantReferences($data, $companyId, PurchaseInvoice::class);
        $this->assertProductionExplicitLineAccounts($data['lines'] ?? []);

        return DB::transaction(function () use ($data, $companyId) {
            $this->periodMutationGuard->assertCreate($companyId, $data, 'lập phiếu chi tiền gửi');
            $totalAmount = collect($data['lines'] ?? [])->sum('amount');
            $contactId = $this->resolveContactId($data['contact_id'] ?? null, $data['contact_type'] ?? null, $companyId);

            $payment = BankPayment::create([
                'company_id' => $companyId,
                'voucher_type' => $data['voucher_type'] ?? 'supplier_payment',
                'contact_type' => $data['contact_type'] ?? null,
                'contact_id' => $contactId,
                'contact_name' => $data['contact_name'] ?? null,
                'bank_account_id' => $data['bank_account_id'],
                'employee_id' => $data['employee_id'] ?? null,
                'employee_name' => $data['employee_name'] ?? null,
                'voucher_number' => $data['voucher_number'] ?? $this->generateNextCode($companyId),
                'voucher_date' => $data['voucher_date'] ?? now()->toDateString(),
                'posting_date' => $data['posting_date'] ?? $data['voucher_date'] ?? now()->toDateString(),
                'payee_name' => $data['payee_name'] ?? $data['contact_name'] ?? null,
                'payee_address' => $data['payee_address'] ?? null,
                'payee_bank_account' => $data['payee_bank_account'] ?? null,
                'payee_bank_name' => $data['payee_bank_name'] ?? null,
                'payee_branch' => $data['payee_branch'] ?? null,
                'fee_bearer' => $data['fee_bearer'] ?? 'buyer',
                'description' => $data['description'] ?? $data['reason'] ?? 'Chi tiền gửi (Ủy nhiệm chi)',
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
                    $payment->lines()->create([
                        'description' => $line['description'] ?? $payment->description,
                        'debit_account' => $this->accountOrLegacyFallback($line['debit_account'] ?? null, 'debit_account', '331'),
                        'credit_account' => $this->accountOrLegacyFallback($line['credit_account'] ?? null, 'credit_account', '1121'),
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
                $payment->syncReferences($data['referenced_vouchers']);
            }

            $this->recordSourceAudit($payment, 'bank_payment.created', [], $payment->fresh('lines')->toArray());

            return $payment->load(['lines', 'bankAccount', 'employee', 'references']);
        });
    }

    public function update($id, array $data): BankPayment
    {
        if (array_key_exists('lines', $data)) {
            $this->assertProductionExplicitLineAccounts($data['lines']);
        }

        return DB::transaction(function () use ($id, $data) {
            $payment = $this->scopeMutationToAuthenticatedCompany(BankPayment::query())->findOrFail($id);
            $referenceData = $data;
            if (array_key_exists('contact_id', $referenceData) && ! array_key_exists('contact_type', $referenceData)) {
                $referenceData['contact_type'] = $payment->contact_type;
            }
            $this->assertCashBankTenantReferences($referenceData, (int) $payment->company_id, PurchaseInvoice::class);
            $this->periodMutationGuard->assertUpdate($payment, $data, 'sửa phiếu chi tiền gửi');
            $before = $payment->load('lines')->toArray();
            $this->assertBankAccountBelongsToCompany($data['bank_account_id'] ?? $payment->bank_account_id, $payment->company_id);
            $this->assertLineBankAccountsBelongToCompany($data['lines'] ?? [], $payment->company_id);
            $totalAmount = collect($data['lines'] ?? [])->sum('amount');
            $contactId = isset($data['contact_id'])
                ? $this->resolveContactId($data['contact_id'], $data['contact_type'] ?? $payment->contact_type, $payment->company_id)
                : $payment->contact_id;

            $payment->update([
                'voucher_type' => $data['voucher_type'] ?? $payment->voucher_type,
                'contact_type' => $data['contact_type'] ?? $payment->contact_type,
                'contact_id' => $contactId,
                'contact_name' => $data['contact_name'] ?? $payment->contact_name,
                'bank_account_id' => $data['bank_account_id'] ?? $payment->bank_account_id,
                'employee_id' => $data['employee_id'] ?? $payment->employee_id,
                'employee_name' => $data['employee_name'] ?? $payment->employee_name,
                'voucher_number' => $data['voucher_number'] ?? $payment->voucher_number,
                'voucher_date' => $data['voucher_date'] ?? $payment->voucher_date,
                'posting_date' => $data['posting_date'] ?? $payment->posting_date,
                'payee_name' => $data['payee_name'] ?? $payment->payee_name,
                'payee_address' => $data['payee_address'] ?? $payment->payee_address,
                'payee_bank_account' => $data['payee_bank_account'] ?? $payment->payee_bank_account,
                'payee_bank_name' => $data['payee_bank_name'] ?? $payment->payee_bank_name,
                'payee_branch' => $data['payee_branch'] ?? $payment->payee_branch,
                'fee_bearer' => $data['fee_bearer'] ?? $payment->fee_bearer,
                'description' => $data['description'] ?? $data['reason'] ?? $payment->description,
                'attached_docs' => $data['attached_docs'] ?? $payment->attached_docs,
                'currency' => $data['currency'] ?? $payment->currency,
                'exchange_rate' => $data['exchange_rate'] ?? $payment->exchange_rate,
                'amount' => $totalAmount ?: $payment->amount,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? $payment->referenced_vouchers,
                'updated_by' => $data['updated_by'] ?? auth()->id(),
            ]);

            if (isset($data['lines'])) {
                $payment->lines()->delete();
                foreach ($data['lines'] as $line) {
                    $lineContactId = $this->resolveContactId($line['line_contact_id'] ?? null, $line['line_contact_type'] ?? null, $payment->company_id, 'line_contact_id');
                    $payment->lines()->create([
                        'description' => $line['description'] ?? $payment->description,
                        'debit_account' => $this->accountOrLegacyFallback($line['debit_account'] ?? null, 'debit_account', '331'),
                        'credit_account' => $this->accountOrLegacyFallback($line['credit_account'] ?? null, 'credit_account', '1121'),
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
                $payment->syncReferences($data['referenced_vouchers']);
            }

            $this->recordSourceAudit($payment, 'bank_payment.updated', $before, $payment->fresh('lines')->toArray());

            return $payment->load(['lines', 'bankAccount', 'employee', 'references']);
        });
    }

    public function delete($id)
    {
        return DB::transaction(function () use ($id) {
            $payment = $this->scopeMutationToAuthenticatedCompany(BankPayment::query())->findOrFail($id);
            $this->periodMutationGuard->assertExisting($payment, 'xóa phiếu chi tiền gửi');
            $before = $payment->load('lines')->toArray();
            if ($payment->is_posted) {
                $this->void($id);
            }
            $payment->lines()->delete();
            $payment->references()->delete();
            $payment->delete();
            $this->recordSourceAudit($payment, 'bank_payment.deleted', $before, ['deleted' => true]);

            return true;
        });
    }

    public function post($id)
    {
        return DB::transaction(function () use ($id) {
            $payment = $this->scopeMutationToAuthenticatedCompany(BankPayment::with('lines')->lockForUpdate())->findOrFail($id);
            $this->periodMutationGuard->assertExisting($payment, 'ghi sổ phiếu chi tiền gửi');
            $before = $payment->toArray();
            if ($payment->is_posted) {
                throw new \Exception('Voucher is already posted');
            }
            $policy = ($this->requiresCashBankPolicy() || $this->requiresCashBankAccountMappings()) ? $this->accountingPolicyResolver->requireForVoucher((int) $payment->company_id, ($payment->posting_date ?? $payment->voucher_date ?? now())->toDateString(), SystemVoucherType::BANK_PAYMENT) : null;
            $approval = $this->requiresCashBankApproval() ? $this->approvalGate->requireApproved($payment, auth()->id()) : null;
            $accountMappings = $this->requiresCashBankAccountMappings() ? $this->accountMappingGate->requireSatisfied($payment, $policy) : null;

            $glLines = [];
            foreach ($payment->lines as $line) {
                $debitAccount = $accountMappings !== null ? CashBankVoucherAccountMappingPostingGate::accountFor($accountMappings, $payment, 'debit', (string) $line->debit_account) : $this->accountOrLegacyFallback($line->debit_account, 'debit_account', '331');
                $creditAccount = $accountMappings !== null ? CashBankVoucherAccountMappingPostingGate::accountFor($accountMappings, $payment, 'credit', (string) $line->credit_account) : $this->accountOrLegacyFallback($line->credit_account, 'credit_account', '1121');
                $glLines[] = [
                    'account_code' => $debitAccount,
                    'description' => $line->description ?: $payment->description,
                    'debit_amount' => $line->amount,
                    'credit_amount' => 0,
                ];
                $glLines[] = [
                    'account_code' => $creditAccount,
                    'description' => $line->description ?: $payment->description,
                    'debit_amount' => 0,
                    'credit_amount' => $line->amount,
                ];

                if ($line->invoice_id) {
                    PurchaseInvoice::where('company_id', $payment->company_id)
                        ->where('id', $line->invoice_id)
                        ->update(['status' => 'Paid']);
                    $this->settlementStatusPolicy->sync(PurchaseInvoice::where('company_id', $payment->company_id)->findOrFail($line->invoice_id));
                }
            }

            $je = $this->journalEntryService->createPosted([
                'company_id' => $payment->company_id,
                'voucher_type' => 'bank_payment',
                'voucher_number' => 'GL-BP-'.$payment->voucher_number,
                'voucher_date' => $payment->voucher_date,
                'posting_date' => $payment->posting_date ?? now()->toDateString(),
                'description' => $payment->description,
                'total_amount' => 0,
                'status' => 'posted',
                'source_document_type' => BankPayment::class,
                'source_document_id' => $payment->id,
                'lines' => $glLines,
            ]);

            $payment->journal_entry_id = $je->id;
            $payment->is_posted = true;
            $payment->status = 'posted';
            $payment->save();
            $this->recordControlLineage($payment, $policy, $approval, $accountMappings, $je->id);
            $this->recordSourceAudit($payment, 'bank_payment.posted', $before, $payment->fresh()->toArray(), array_filter(['accounting_policy' => $policy, 'approval' => $approval, 'account_mappings' => $accountMappings]));

            return $payment;
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

    private function recordControlLineage(BankPayment $voucher, ?array $policy, ?array $approval, ?array $accountMappings, int $journalEntryId): void { if ($policy !== null) $this->auditService->record($voucher, 'bank_payment.policy_applied', [], [], null, ['journal_entry_id' => $journalEntryId, 'accounting_policy' => $policy]); if ($approval !== null) $this->auditService->record($voucher, 'bank_payment.approval_applied', [], [], null, ['journal_entry_id' => $journalEntryId, 'approval_gate' => 'enforced', 'approval' => $approval]); if ($accountMappings !== null) $this->auditService->record($voucher, 'bank_payment.account_mappings_applied', [], [], null, ['journal_entry_id' => $journalEntryId, 'account_mapping_gate' => 'enforced', 'account_mappings' => $accountMappings]); }

    public function void($id)
    {
        return DB::transaction(function () use ($id) {
            $payment = $this->scopeMutationToAuthenticatedCompany(BankPayment::query())->findOrFail($id);
            $this->periodMutationGuard->assertExisting($payment, 'hủy phiếu chi tiền gửi');
            $before = $payment->toArray();
            if (! $payment->is_posted) {
                throw new \Exception('Voucher is not posted yet');
            }
            $this->assertHasNoPostedSettlementAllocations((int) $payment->company_id, 'bank_payment', (int) $payment->id);
            $this->assertNoPostedDependentDocuments((int) $payment->company_id, BankPayment::class, (int) $payment->id);

            if ($payment->journal_entry_id) {
                $this->journalEntryService->void($payment->journal_entry_id, (int) $payment->company_id);
            }

            foreach ($payment->lines as $line) {
                if ($line->invoice_id) {
                    PurchaseInvoice::where('company_id', $payment->company_id)
                        ->where('id', $line->invoice_id)
                        ->update(['status' => 'Unpaid']);
                    $this->settlementStatusPolicy->sync(PurchaseInvoice::where('company_id', $payment->company_id)->findOrFail($line->invoice_id));
                }
            }

            $payment->is_posted = false;
            $payment->status = 'voided';
            $payment->save();
            $this->recordSourceAudit($payment, 'bank_payment.voided', $before, $payment->fresh()->toArray(), ['journal_entry_id' => $before['journal_entry_id'] ?? null]);

            return $payment;
        });
    }

    public function unpost($id): BankPayment
    {
        return DB::transaction(function () use ($id) {
            $payment = $this->scopeMutationToAuthenticatedCompany(BankPayment::query())->findOrFail($id);
            $this->periodMutationGuard->assertExisting($payment, 'bỏ ghi sổ phiếu chi tiền gửi');
            $before = $payment->toArray();
            if (! $payment->is_posted) {
                throw new \Exception('Voucher is not posted yet');
            }
            $this->assertHasNoPostedSettlementAllocations((int) $payment->company_id, 'bank_payment', (int) $payment->id);
            $this->assertNoPostedDependentDocuments((int) $payment->company_id, BankPayment::class, (int) $payment->id);

            if ($payment->journal_entry_id) {
                $this->journalEntryService->void($payment->journal_entry_id, (int) $payment->company_id);
                $payment->journal_entry_id = null;
            }

            $payment->is_posted = false;
            $payment->status = 'draft';
            $payment->save();
            $this->recordSourceAudit($payment, 'bank_payment.unposted', $before, $payment->fresh()->toArray(), ['journal_entry_id' => $before['journal_entry_id'] ?? null]);

            return $payment;
        });
    }

    public function duplicate($id): BankPayment
    {
        return DB::transaction(function () use ($id) {
            $original = $this->scopeMutationToAuthenticatedCompany(BankPayment::with('lines'))->findOrFail($id);
            $this->periodMutationGuard->assertDuplicate($original, 'nhân bản phiếu chi tiền gửi');
            $newVoucherNumber = 'UNC-'.now()->format('Ymd').'-'.strtoupper(substr(uniqid(), -4));

            $newPayment = $original->replicate();
            $newPayment->voucher_number = $newVoucherNumber;
            $newPayment->voucher_date = now()->toDateString();
            $newPayment->posting_date = now()->toDateString();
            $newPayment->is_posted = false;
            $newPayment->journal_entry_id = null;
            $newPayment->status = 'draft';
            $newPayment->save();

            foreach ($original->lines as $line) {
                $newLine = $line->replicate();
                $newLine->bank_payment_id = $newPayment->id;
                $newLine->save();
            }

            if ($original->referenced_vouchers) {
                $newPayment->syncReferences($original->referenced_vouchers);
            }

            $this->recordSourceAudit($newPayment, 'bank_payment.duplicated', [], $newPayment->fresh('lines')->toArray(), ['original_source_id' => $original->id]);

            return $newPayment->load(['lines', 'bankAccount', 'employee', 'references']);
        });
    }

    public function generateNextCode(int $companyId): string
    {
        $companyId = $this->requireCompanyId(['company_id' => $companyId]);
        $year = now()->format('Y');
        $prefix = 'UNC-'.$year.'-';
        $latest = BankPayment::where('company_id', $companyId)
            ->where('voucher_number', 'like', $prefix.'%')
            ->orderBy('id', 'desc')
            ->value('voucher_number');

        if ($latest && preg_match('/'.preg_quote($prefix, '/').'(\d+)/', $latest, $m)) {
            $nextSeq = str_pad((int) $m[1] + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $count = BankPayment::where('company_id', $companyId)->count() + 1;
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
