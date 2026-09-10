<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Models\CashPayment;
use App\Models\Employee;
use App\Models\PurchaseInvoice;
use App\Services\Concerns\GuardsCashBankTenantReferences;
use App\Services\Concerns\GuardsPostedDependentDocuments;
use App\Services\Concerns\GuardsPostedSettlementAllocations;
use App\Services\Concerns\RecordsSourceAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CashPaymentService
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
        $query = CashPayment::with(['lines', 'references', 'referencedBy']);
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
                    ->orWhere('receiver_name', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('voucher_date', 'desc')->get();
    }

    public function getById($id, ?int $companyId = null)
    {
        $companyId = $this->requireCompanyId(['company_id' => $companyId]);

        return CashPayment::with(['lines', 'references', 'referencedBy'])
            ->where('company_id', $companyId)
            ->findOrFail($id);
    }

    public function create(array $data): CashPayment
    {
        $data['company_id'] = $this->requireCompanyId($data);
        $data = $this->sanitizePaymentData($data);
        $this->assertCashBankTenantReferences($data, (int) $data['company_id'], PurchaseInvoice::class);
        $data = $this->normalizeCashBankTenantReferences($data, (int) $data['company_id']);
        $this->assertProductionExplicitLineAccounts($data['lines'] ?? []);

        return DB::transaction(function () use ($data) {
            $this->periodMutationGuard->assertCreate((int) $data['company_id'], $data, 'lập phiếu chi tiền mặt');
            $totalAmount = collect($data['lines'] ?? [])->sum('amount');

            $voucherNumber = $data['voucher_number'] ?? null;
            if (empty($voucherNumber)) {
                $last = CashPayment::where('company_id', $data['company_id'])->orderBy('id', 'desc')->first();
                $nextNum = $last ? ($last->id + 1) : 1;
                $voucherNumber = 'PC'.str_pad($nextNum, 5, '0', STR_PAD_LEFT);
            }

            $payment = CashPayment::create([
                'company_id' => $data['company_id'],
                'voucher_type' => $data['voucher_type'] ?? null,
                'contact_type' => $data['contact_type'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'contact_name' => $data['contact_name'] ?? null,
                'voucher_number' => $voucherNumber,
                'voucher_date' => $data['voucher_date'],
                'posting_date' => $data['posting_date'] ?? $data['voucher_date'],
                'receiver_name' => $data['receiver_name'] ?? $data['contact_name'] ?? null,
                'receiver_address' => $data['receiver_address'] ?? null,
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
                    $payment->lines()->create([
                        'description' => $line['description'] ?? $payment->reason ?? 'Chi tiền',
                        'debit_account' => $this->accountOrLegacyFallback($line['debit_account'] ?? null, 'debit_account', '331'),
                        'credit_account' => $this->accountOrLegacyFallback($line['credit_account'] ?? null, 'credit_account', '1111'),
                        'amount' => $line['amount'] ?? 0,
                        'operation' => $line['operation'] ?? null,
                        'loan_contract' => $line['loan_contract'] ?? null,
                        'line_contact_id' => $line['line_contact_id'] ?? null,
                        'line_contact_name' => $line['line_contact_name'] ?? null,
                        'invoice_id' => $line['invoice_id'] ?? null,
                    ]);
                }
            }

            // Sync voucher_references
            if (isset($data['referenced_vouchers'])) {
                $payment->syncReferences($data['referenced_vouchers']);
            }

            $shouldPost = ! empty($data['is_posted']) || ! empty($data['auto_post']);
            if ($shouldPost) {
                $this->post($payment->id);
            }

            $this->recordSourceAudit($payment, 'cash_payment.created', [], $payment->fresh('lines')->toArray());

            return $payment->fresh(['lines', 'references', 'referencedBy']);
        });
    }

    public function update($id, array $data): CashPayment
    {
        $data = $this->sanitizePaymentData($data);
        if (array_key_exists('lines', $data)) {
            $this->assertProductionExplicitLineAccounts($data['lines']);
        }

        return DB::transaction(function () use ($id, $data) {
            $payment = $this->scopeMutationToAuthenticatedCompany(CashPayment::query())->findOrFail($id);
            $this->assertCashBankTenantReferences($data, (int) $payment->company_id, PurchaseInvoice::class);
            $data = $this->normalizeCashBankTenantReferences($data, (int) $payment->company_id);
            $this->periodMutationGuard->assertUpdate($payment, $data, 'sửa phiếu chi tiền mặt');
            $this->assertMutableSource($payment, 'sửa');
            $before = $payment->load('lines')->toArray();

            $totalAmount = collect($data['lines'] ?? [])->sum('amount');

            $payment->update([
                'voucher_type' => $data['voucher_type'] ?? $payment->voucher_type,
                'contact_type' => $data['contact_type'] ?? $payment->contact_type,
                'contact_id' => $data['contact_id'] ?? $payment->contact_id,
                'contact_name' => $data['contact_name'] ?? $payment->contact_name,
                'voucher_number' => $data['voucher_number'] ?? $payment->voucher_number,
                'voucher_date' => $data['voucher_date'] ?? $payment->voucher_date,
                'posting_date' => $data['posting_date'] ?? $payment->posting_date,
                'receiver_name' => $data['receiver_name'] ?? $payment->receiver_name,
                'receiver_address' => $data['receiver_address'] ?? $payment->receiver_address,
                'employee_id' => $data['employee_id'] ?? $payment->employee_id,
                'employee_name' => $data['employee_name'] ?? $payment->employee_name,
                'reason' => $data['reason'] ?? $data['description'] ?? $payment->reason,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? $payment->referenced_vouchers,
                'attached_docs' => $data['attached_docs'] ?? $payment->attached_docs,
                'currency' => $data['currency'] ?? $payment->currency,
                'exchange_rate' => $data['exchange_rate'] ?? $payment->exchange_rate,
                'total_amount' => $totalAmount ?: $payment->total_amount,
            ]);

            if (isset($data['lines'])) {
                $payment->lines()->delete();
                foreach ($data['lines'] as $line) {
                    $payment->lines()->create([
                        'description' => $line['description'] ?? $payment->reason,
                        'debit_account' => $this->accountOrLegacyFallback($line['debit_account'] ?? null, 'debit_account', '331'),
                        'credit_account' => $this->accountOrLegacyFallback($line['credit_account'] ?? null, 'credit_account', '1111'),
                        'amount' => $line['amount'] ?? 0,
                        'operation' => $line['operation'] ?? null,
                        'loan_contract' => $line['loan_contract'] ?? null,
                        'line_contact_id' => $line['line_contact_id'] ?? null,
                        'line_contact_name' => $line['line_contact_name'] ?? null,
                        'invoice_id' => $line['invoice_id'] ?? null,
                    ]);
                }
            }

            // Sync voucher_references
            if (isset($data['referenced_vouchers'])) {
                $payment->syncReferences($data['referenced_vouchers']);
            }

            $this->recordSourceAudit($payment, 'cash_payment.updated', $before, $payment->fresh('lines')->toArray());

            return $payment->fresh(['lines', 'references', 'referencedBy']);
        });
    }

    public function delete($id)
    {
        return DB::transaction(function () use ($id) {
            $payment = $this->scopeMutationToAuthenticatedCompany(CashPayment::query())->findOrFail($id);
            $this->periodMutationGuard->assertExisting($payment, 'xóa phiếu chi tiền mặt');
            $this->assertMutableSource($payment, 'xóa');
            $before = $payment->load('lines')->toArray();
            $payment->lines()->delete();
            $payment->references()->delete();
            $payment->delete();
            $this->recordSourceAudit($payment, 'cash_payment.deleted', $before, ['deleted' => true]);

            return true;
        });
    }

    private function assertMutableSource(CashPayment $payment, string $operation): void
    {
        $status = strtolower(trim((string) $payment->status));
        if ($payment->is_posted || $status === 'posted') {
            throw new ConflictHttpException("Không thể {$operation} phiếu chi tiền mặt đã ghi sổ. Vui lòng bỏ ghi sổ trước khi {$operation}.");
        }

        if (in_array($status, ['voided', 'cancelled', 'canceled'], true)) {
            throw new ConflictHttpException("Không thể {$operation} phiếu chi tiền mặt đã hủy.");
        }
    }

    public function duplicate($id): CashPayment
    {
        return DB::transaction(function () use ($id) {
            $original = $this->scopeMutationToAuthenticatedCompany(CashPayment::with(['lines', 'references', 'referencedBy']))->findOrFail($id);
            $this->periodMutationGuard->assertDuplicate($original, 'nhân bản phiếu chi tiền mặt');

            $newVoucherNumber = 'PC'.rand(10000, 99999);
            while (CashPayment::where('company_id', $original->company_id)->where('voucher_number', $newVoucherNumber)->exists()) {
                $newVoucherNumber = 'PC'.rand(10000, 99999);
            }

            $duplicate = CashPayment::create([
                'company_id' => $original->company_id,
                'contact_type' => $original->contact_type,
                'contact_id' => $original->contact_id,
                'contact_name' => $original->contact_name,
                'voucher_number' => $newVoucherNumber,
                'voucher_date' => now()->toDateString(),
                'posting_date' => now()->toDateString(),
                'receiver_name' => $original->receiver_name,
                'receiver_address' => $original->receiver_address,
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

            $this->recordSourceAudit($duplicate, 'cash_payment.duplicated', [], $duplicate->fresh('lines')->toArray(), ['original_source_id' => $original->id]);

            return $duplicate->load(['lines', 'references']);
        });
    }

    public function post($id)
    {
        return DB::transaction(function () use ($id) {
            $payment = $this->scopeMutationToAuthenticatedCompany(CashPayment::with('lines')->lockForUpdate())->findOrFail($id);
            $this->periodMutationGuard->assertExisting($payment, 'ghi sổ phiếu chi tiền mặt');
            $before = $payment->toArray();
            if ($payment->is_posted) {
                throw new \Exception('Voucher is already posted');
            }
            $authorization = $this->postingAuthorizer->authorize('cash.payments.post', (int) $payment->company_id);
            $policy = ($this->requiresCashBankPolicy() || $this->requiresCashBankAccountMappings()) ? $this->accountingPolicyResolver->requireForVoucher((int) $payment->company_id, ($payment->posting_date ?? $payment->voucher_date ?? now())->toDateString(), SystemVoucherType::CASH_PAYMENT) : null;
            $accountMappings = $this->requiresCashBankAccountMappings() ? $this->accountMappingGate->requireSatisfied($payment, $policy) : null;

            $glLines = [];
            foreach ($payment->lines as $line) {
                $debitAccount = $accountMappings !== null ? CashBankVoucherAccountMappingPostingGate::accountFor($accountMappings, $payment, 'debit', (string) $line->debit_account) : $this->accountOrLegacyFallback($line->debit_account, 'debit_account', '331');
                $creditAccount = $accountMappings !== null ? CashBankVoucherAccountMappingPostingGate::accountFor($accountMappings, $payment, 'credit', (string) $line->credit_account) : $this->accountOrLegacyFallback($line->credit_account, 'credit_account', '1111');
                $glLines[] = [
                    'account_code' => $debitAccount,
                    'description' => $line->description ?: $payment->reason,
                    'debit_amount' => $line->amount,
                    'credit_amount' => 0,
                ];
                $glLines[] = [
                    'account_code' => $creditAccount,
                    'description' => $line->description ?: $payment->reason,
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
                'voucher_type' => 'cash_payment',
                'voucher_number' => 'GL-CP-'.$payment->voucher_number,
                'voucher_date' => $payment->voucher_date,
                'posting_date' => $payment->posting_date ?? now()->toDateString(),
                'description' => $payment->reason ?? 'Chi tiền mặt',
                'total_amount' => 0,
                'status' => 'posted',
                'source_document_type' => CashPayment::class,
                'source_document_id' => $payment->id,
                'lines' => $glLines,
            ]);

            $payment->journal_entry_id = $je->id;
            $payment->is_posted = true;
            // Keep the persisted lifecycle fields in lock-step with the bank
            // voucher contract and the MISA-style status column.
            $payment->status = 'posted';
            $payment->save();
            $this->recordControlLineage($payment, $policy, $authorization, $accountMappings, $je->id);
            $this->recordSourceAudit($payment, 'cash_payment.posted', $before, $payment->fresh()->toArray(), array_filter(['accounting_policy' => $policy, 'posting_authorization' => $authorization, 'account_mappings' => $accountMappings]));

            return $payment;
        });
    }

    private function requiresCashBankPolicy(): bool { return (bool) config('accounting.enforce_cash_bank_posting_policy', true); }
    private function requiresCashBankAccountMappings(): bool { return (bool) config('accounting.enforce_cash_bank_posting_account_mappings', true); }

    /** @param array<int, mixed> $lines */
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
            throw ValidationException::withMessages(["lines.0.{$field}" => 'Production requires an explicit account code; no default account is inferred.']);
        }
        return $legacyFallback;
    }
    private function recordControlLineage(CashPayment $voucher, ?array $policy, array $authorization, ?array $accountMappings, int $journalEntryId): void { if ($policy !== null) $this->auditService->record($voucher, 'cash_payment.policy_applied', [], [], null, ['journal_entry_id' => $journalEntryId, 'accounting_policy' => $policy]); $this->auditService->record($voucher, 'cash_payment.posting_authorization_applied', [], [], null, ['journal_entry_id' => $journalEntryId] + $authorization); if ($accountMappings !== null) $this->auditService->record($voucher, 'cash_payment.account_mappings_applied', [], [], null, ['journal_entry_id' => $journalEntryId, 'account_mapping_gate' => 'enforced', 'account_mappings' => $accountMappings]); }

    /**
     * Bỏ ghi sổ (Unpost) — hủy bút toán, đưa về nháp, có thể sửa lại
     */
    public function unpost($id)
    {
        return DB::transaction(function () use ($id) {
            $payment = $this->scopeMutationToAuthenticatedCompany(CashPayment::query())->findOrFail($id);
            $this->periodMutationGuard->assertExisting($payment, 'bỏ ghi sổ phiếu chi tiền mặt');
            $before = $payment->toArray();
            if (! $payment->is_posted) {
                throw new \Exception('Chứng từ chưa được ghi sổ');
            }
            $this->assertHasNoPostedSettlementAllocations((int) $payment->company_id, 'cash_payment', (int) $payment->id);
            $this->assertNoPostedDependentDocuments((int) $payment->company_id, CashPayment::class, (int) $payment->id);

            if ($payment->journal_entry_id) {
                $this->journalEntryService->void($payment->journal_entry_id, (int) $payment->company_id);
                $payment->journal_entry_id = null;
            }

            $payment->is_posted = false;
            $payment->status = 'draft';
            $payment->save();
            $this->recordSourceAudit($payment, 'cash_payment.unposted', $before, $payment->fresh()->toArray(), ['journal_entry_id' => $before['journal_entry_id'] ?? null]);

            return $payment;
        });
    }

    /**
     * Hủy chứng từ (Void) — hủy hoàn toàn, đánh dấu trạng thái voided
     */
    public function void($id)
    {
        return DB::transaction(function () use ($id) {
            $payment = $this->scopeMutationToAuthenticatedCompany(CashPayment::query())->findOrFail($id);
            $this->periodMutationGuard->assertExisting($payment, 'hủy phiếu chi tiền mặt');
            $before = $payment->toArray();
            $this->assertHasNoPostedSettlementAllocations((int) $payment->company_id, 'cash_payment', (int) $payment->id);
            $this->assertNoPostedDependentDocuments((int) $payment->company_id, CashPayment::class, (int) $payment->id);

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
            $this->recordSourceAudit($payment, 'cash_payment.voided', $before, $payment->fresh()->toArray(), ['journal_entry_id' => $before['journal_entry_id'] ?? null]);

            return $payment;
        });
    }

    /**
     * Lấy mã phiếu chi tiếp theo dạng PC00001
     */
    public function getNextCode(int $companyId): string
    {
        $companyId = $this->requireCompanyId(['company_id' => $companyId]);
        $last = CashPayment::where('company_id', $companyId)
            ->where('voucher_number', 'like', 'PC%')
            ->orderBy('voucher_number', 'desc')
            ->value('voucher_number');

        if ($last) {
            $num = (int) substr($last, 2);
            $next = $num + 1;
        } else {
            $next = 1;
        }

        return 'PC'.str_pad($next, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Lọc và làm sạch payload theo từng lý do chi tiền để chống chèn dữ liệu chéo trái phép
     */
    protected function sanitizePaymentData(array $data): array
    {
        $voucherType = $data['voucher_type'] ?? '8. Chi khác';
        $isChiVay = str_contains($voucherType, '7. Chi cho vay') || str_contains($voucherType, 'cho vay');

        // Populate employee_name if employee_id provided but name missing
        if (! empty($data['employee_id']) && empty($data['employee_name'])) {
            $emp = Employee::where('company_id', $this->requireCompanyId($data))
                ->find($data['employee_id']);
            if ($emp) {
                $data['employee_name'] = $emp->name;
            }
        }

        // Chỉ có Chi cho vay mới lưu loan_contract
        if (! $isChiVay && ! empty($data['lines'])) {
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
