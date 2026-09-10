<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Exceptions\AccountingPolicyUnavailableException;
use App\Models\JournalEntry;
use App\Models\SalesDiscount;
use App\Services\Concerns\GuardsPostedDependentDocuments;
use App\Services\Concerns\GuardsPostedSettlementAllocations;
use App\Support\CommercialSourceAuditContext;
use App\Support\DecimalMoney;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SalesDiscountService extends BaseService
{
    use GuardsPostedDependentDocuments, GuardsPostedSettlementAllocations;

    public function __construct(
        protected JournalEntryService $journalEntryService,
        private readonly AuditService $auditService,
        private readonly AccountingPolicyResolver $accountingPolicyResolver,
        private readonly CommercialAdjustmentAccountMappingPostingGate $accountMappingGate,
        private readonly CommercialAdjustmentSettlementService $adjustmentSettlements,
        private readonly AccountingPeriodGuard $periodGuard,
    ) {}

    public function getAll(array $filters = [])
    {
        $query = $this->scopeMutationToAuthenticatedCompany(SalesDiscount::with([
            'customer',
            'employee',
            'bankAccount',
            'lines.item',
            'references',
            'journalEntry.lines',
        ]))
            ->orderBy('voucher_date', 'desc')
            ->orderBy('id', 'desc');

        if (! empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
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
                    ->orWhere('customer_name', 'like', "%{$s}%")
                    ->orWhere('reason', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%");
            });
        }

        if (isset($filters['per_page']) && is_numeric($filters['per_page']) && (int) $filters['per_page'] > 0) {
            return $query->paginate((int) $filters['per_page']);
        }

        return $query->get();
    }

    public function getById(int|string $id): SalesDiscount
    {
        return $this->scopeMutationToAuthenticatedCompany(SalesDiscount::with([
            'lines.item',
            'customer',
            'employee',
            'bankAccount',
            'referenceInvoice',
            'references',
            'journalEntry.lines',
        ]))->findOrFail($id);
    }

    public function create(array $data): SalesDiscount
    {
        $autoPost = ! empty($data['is_posted']) || ($data['status'] ?? '') === 'posted';
        if ($autoPost) {
            $this->assertCanAutoPost();
        }

        $companyId = $this->requireCompanyId($data);
        $accountingDate = $data['accounting_date'] ?? $data['voucher_date'] ?? now()->toDateString();
        $this->periodGuard->assertOpen((int) $companyId, $accountingDate, 'lập chứng từ giảm giá hàng bán');

        return DB::transaction(function () use ($data, $autoPost, $companyId) {
            $subTotal = DecimalMoney::ZERO;
            $taxAmount = DecimalMoney::ZERO;
            $lines = $data['lines'] ?? [];
            $preparedLines = [];

            if (! is_array($lines) || $lines === []) {
                throw ValidationException::withMessages([
                    'lines' => 'A sales discount requires at least one detail line.',
                ]);
            }
            if (empty($data['customer_id'])) {
                throw ValidationException::withMessages([
                    'customer_id' => 'A customer is required for a sales discount.',
                ]);
            }

            foreach ($lines as $line) {
                $amounts = $this->lineAmounts($line);
                $preparedLines[] = array_merge($line, $amounts);
                $subTotal = DecimalMoney::add($subTotal, $amounts['amount']);
                $taxAmount = DecimalMoney::add($taxAmount, $amounts['tax']);
            }

            $totalAmount = DecimalMoney::add($subTotal, $taxAmount);
            $paymentMethod = $data['payment_method'] ?? 'reduce_receivable';
            $isDecreaseDebt = isset($data['is_decrease_debt']) ? (bool) $data['is_decrease_debt'] : ($paymentMethod === 'reduce_receivable');

            CommercialDraftAccountEvidenceGate::assertSatisfied('sales_discount', $lines);

            $voucherNumber = $data['voucher_number'] ?? $this->generateNextCode($companyId);

            $salesDiscount = SalesDiscount::create([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_address' => $data['customer_address'] ?? null,
                'tax_code' => $data['tax_code'] ?? null,
                'receiver_name' => $data['receiver_name'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'voucher_type' => $data['voucher_type'] ?? 'sales_discount',
                'payment_method' => $paymentMethod,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'voucher_number' => $voucherNumber,
                'voucher_date' => $data['voucher_date'] ?? now()->toDateString(),
                'accounting_date' => $data['accounting_date'] ?? $data['voucher_date'] ?? now()->toDateString(),
                'reason' => $data['reason'] ?? null,
                'description' => $data['description'] ?? $data['reason'] ?? null,
                'attached_docs' => $data['attached_docs'] ?? null,
                'sub_total' => $subTotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'grand_total' => $totalAmount,
                'is_posted' => false,
                'is_decrease_debt' => $isDecreaseDebt,
                'status' => 'draft',
                'reference_invoice_id' => $data['reference_invoice_id'] ?? null,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
                'updated_by' => $data['updated_by'] ?? auth()->id(),
            ]);

            $defaultCredit = match ($paymentMethod) {
                'cash' => '1111',
                'bank' => '1121',
                default => '131',
            };

            foreach ($preparedLines as $idx => $line) {
                $salesDiscount->lines()->create([
                    'line_order' => $line['line_order'] ?? ($idx + 1),
                    'item_id' => $line['item_id'] ?? null,
                    'item_code' => $line['item_code'] ?? null,
                    'item_name' => $line['item_name'] ?? $line['description'] ?? null,
                    'description' => $line['description'] ?? $line['item_name'] ?? $salesDiscount->reason ?? 'Giảm giá hàng bán',
                    'unit' => $line['unit'] ?? null,
                    'debit_account' => CommercialDraftAccountEvidenceGate::account($line, 'debit_account', 'sales_discount', '5213'),
                    'credit_account' => CommercialDraftAccountEvidenceGate::account($line, 'credit_account', 'sales_discount', $defaultCredit),
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'amount' => $line['amount'],
                    'discount_amount' => $line['amount'],
                    'tax_rate' => $line['tax_rate'],
                    'tax_amount' => $line['tax'],
                    'tax_account' => CommercialDraftAccountEvidenceGate::account($line, 'tax_account', 'sales_discount', '33311'),
                    'invoice_number' => $line['invoice_number'] ?? null,
                    'invoice_date' => $line['invoice_date'] ?? null,
                    'sales_order_id' => $line['sales_order_id'] ?? null,
                    'contract_id' => $line['contract_id'] ?? null,
                ]);
            }

            if (! empty($data['referenced_vouchers'])) {
                $salesDiscount->syncReferences($data['referenced_vouchers']);
            }

            if ($autoPost) {
                return $this->post($salesDiscount->id);
            }

            return $salesDiscount->load(['lines.item', 'customer', 'employee', 'bankAccount', 'references']);
        });
    }

    public function update(int|string $id, array $data): SalesDiscount
    {
        $this->assertDraftMutationIntent($data);

        return DB::transaction(function () use ($id, $data) {
            $salesDiscount = $this->scopeMutationToAuthenticatedCompany(SalesDiscount::with('lines'))->findOrFail($id);
            $this->periodGuard->assertOpen((int) $salesDiscount->company_id, $salesDiscount->accounting_date ?? $salesDiscount->voucher_date, 'sửa chứng từ giảm giá hàng bán');
            if (array_key_exists('accounting_date', $data)) {
                $this->periodGuard->assertOpen((int) $salesDiscount->company_id, $data['accounting_date'], 'sửa chứng từ giảm giá hàng bán');
            }

            $this->assertMutableSource($salesDiscount, 'sửa');

            $lines = $data['lines'] ?? null;
            CommercialDraftAccountEvidenceGate::assertUpdateLinesProvided('sales_discount', $lines);
            $subTotal = DecimalMoney::ZERO;
            $taxAmount = DecimalMoney::ZERO;
            $preparedLines = null;

            if ($lines !== null) {
                $preparedLines = [];
                foreach ($lines as $line) {
                    $amounts = $this->lineAmounts($line);
                    $preparedLines[] = array_merge($line, $amounts);
                    $subTotal = DecimalMoney::add($subTotal, $amounts['amount']);
                    $taxAmount = DecimalMoney::add($taxAmount, $amounts['tax']);
                }
            } else {
                $subTotal = $this->persistedMoney($salesDiscount, 'sub_total');
                $taxAmount = $this->persistedMoney($salesDiscount, 'tax_amount');
            }

            $totalAmount = DecimalMoney::add($subTotal, $taxAmount);
            $paymentMethod = $data['payment_method'] ?? $salesDiscount->payment_method ?? 'reduce_receivable';
            $isDecreaseDebt = isset($data['is_decrease_debt']) ? (bool) $data['is_decrease_debt'] : ($paymentMethod === 'reduce_receivable');

            CommercialDraftAccountEvidenceGate::assertSatisfied('sales_discount', $lines ?? $salesDiscount->lines->map(
                static fn ($line): array => $line->toArray(),
            )->all());

            $salesDiscount->update([
                'branch_id' => $data['branch_id'] ?? $salesDiscount->branch_id,
                'customer_id' => $data['customer_id'] ?? $salesDiscount->customer_id,
                'customer_name' => $data['customer_name'] ?? $salesDiscount->customer_name,
                'customer_address' => $data['customer_address'] ?? $salesDiscount->customer_address,
                'tax_code' => $data['tax_code'] ?? $salesDiscount->tax_code,
                'receiver_name' => $data['receiver_name'] ?? $salesDiscount->receiver_name,
                'employee_id' => $data['employee_id'] ?? $salesDiscount->employee_id,
                'voucher_type' => $data['voucher_type'] ?? $salesDiscount->voucher_type,
                'payment_method' => $paymentMethod,
                'bank_account_id' => $data['bank_account_id'] ?? $salesDiscount->bank_account_id,
                'voucher_number' => $data['voucher_number'] ?? $salesDiscount->voucher_number,
                'voucher_date' => $data['voucher_date'] ?? $salesDiscount->voucher_date,
                'accounting_date' => $data['accounting_date'] ?? $salesDiscount->accounting_date,
                'reason' => $data['reason'] ?? $salesDiscount->reason,
                'description' => $data['description'] ?? $data['reason'] ?? $salesDiscount->description,
                'attached_docs' => $data['attached_docs'] ?? $salesDiscount->attached_docs,
                'sub_total' => $subTotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'grand_total' => $totalAmount,
                'is_decrease_debt' => $isDecreaseDebt,
                'status' => $data['status'] ?? $salesDiscount->status,
                'reference_invoice_id' => $data['reference_invoice_id'] ?? $salesDiscount->reference_invoice_id,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? $salesDiscount->referenced_vouchers,
                'updated_by' => $data['updated_by'] ?? auth()->id(),
            ]);

            if ($lines !== null) {
                $salesDiscount->lines()->delete();

                $defaultCredit = match ($paymentMethod) {
                    'cash' => '1111',
                    'bank' => '1121',
                    default => '131',
                };

                foreach ($preparedLines ?? [] as $idx => $line) {
                    $salesDiscount->lines()->create([
                        'line_order' => $line['line_order'] ?? ($idx + 1),
                        'item_id' => $line['item_id'] ?? null,
                        'item_code' => $line['item_code'] ?? null,
                        'item_name' => $line['item_name'] ?? $line['description'] ?? null,
                        'description' => $line['description'] ?? $line['item_name'] ?? $salesDiscount->reason ?? 'Giảm giá hàng bán',
                        'unit' => $line['unit'] ?? null,
                        'debit_account' => CommercialDraftAccountEvidenceGate::account($line, 'debit_account', 'sales_discount', '5213'),
                        'credit_account' => CommercialDraftAccountEvidenceGate::account($line, 'credit_account', 'sales_discount', $defaultCredit),
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'amount' => $line['amount'],
                        'discount_amount' => $line['amount'],
                        'tax_rate' => $line['tax_rate'],
                        'tax_amount' => $line['tax'],
                        'tax_account' => CommercialDraftAccountEvidenceGate::account($line, 'tax_account', 'sales_discount', '33311'),
                        'invoice_number' => $line['invoice_number'] ?? null,
                        'invoice_date' => $line['invoice_date'] ?? null,
                        'sales_order_id' => $line['sales_order_id'] ?? null,
                        'contract_id' => $line['contract_id'] ?? null,
                    ]);
                }
            }

            if (isset($data['referenced_vouchers'])) {
                $salesDiscount->syncReferences($data['referenced_vouchers']);
            }

            return $salesDiscount->load(['lines.item', 'customer', 'employee', 'bankAccount', 'references']);
        });
    }

    private function money(mixed $value): string
    {
        return DecimalMoney::normalize($value ?? DecimalMoney::ZERO);
    }

    private function persistedMoney(object $model, string $attribute): string
    {
        return $this->money($model->getRawOriginal($attribute) ?? DecimalMoney::ZERO);
    }

    /**
     * @return array{quantity:string,unit_price:string,amount:string,tax_rate:string,tax:string}
     */
    private function lineAmounts(array $line): array
    {
        $quantity = $this->money($line['quantity'] ?? '1');
        $unitPrice = $this->money($line['unit_price'] ?? '0');
        $rawAmount = trim((string) ($line['amount'] ?? ''));
        if ($rawAmount === '') {
            $rawAmount = trim((string) ($line['discount_amount'] ?? ''));
        }
        $amount = $rawAmount !== ''
            ? $this->money($rawAmount)
            : DecimalMoney::multiply($quantity, $unitPrice);
        $taxRate = $this->money($line['tax_rate'] ?? '0');
        $rawTax = trim((string) ($line['tax_amount'] ?? ''));
        $tax = $rawTax !== ''
            ? $this->money($rawTax)
            : DecimalMoney::percentage($amount, $taxRate);

        return [
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $amount,
            'tax_rate' => $taxRate,
            'tax' => $tax,
        ];
    }

    public function delete(int|string $id): bool
    {
        return DB::transaction(function () use ($id) {
            $salesDiscount = $this->scopeMutationToAuthenticatedCompany(SalesDiscount::query())->findOrFail($id);
            $this->periodGuard->assertOpen((int) $salesDiscount->company_id, $salesDiscount->accounting_date ?? $salesDiscount->voucher_date, 'xóa chứng từ giảm giá hàng bán');
            $this->assertMutableSource($salesDiscount, 'xóa');
            $salesDiscount->lines()->delete();
            $salesDiscount->references()->delete();
            $salesDiscount->delete();

            return true;
        });
    }

    private function assertMutableSource(SalesDiscount $source, string $operation): void
    {
        $status = strtolower(trim((string) $source->status));
        if ($source->is_posted || in_array($status, ['posted', 'voided', 'cancelled', 'canceled'], true)) {
            throw new ConflictHttpException("Không thể {$operation} chứng từ đã ghi sổ hoặc đã hủy. Hãy sử dụng thao tác bỏ ghi sổ/hủy riêng.");
        }
    }

    public function post(int|string $id): SalesDiscount
    {
        return DB::transaction(function () use ($id) {
            $salesDiscount = $this->scopeMutationToAuthenticatedCompany(SalesDiscount::with('lines'))->findOrFail($id);
            $this->periodGuard->assertOpen((int) $salesDiscount->company_id, $salesDiscount->accounting_date ?? $salesDiscount->voucher_date, 'ghi sổ chứng từ giảm giá hàng bán');
            if ($salesDiscount->is_posted) {
                throw new Exception('Chứng từ đã được ghi sổ.');
            }
            app(CommercialAdjustmentCapacityService::class)->assertSalesDiscount($salesDiscount);

            $policy = null;
            $accountMappings = null;
            if ((bool) config('accounting.enforce_return_discount_posting_account_mappings', true)) {
                try {
                    $policy = $this->accountingPolicyResolver->requireForVoucher(
                        (int) $salesDiscount->company_id,
                        ($salesDiscount->accounting_date ?? $salesDiscount->voucher_date ?? now())->toDateString(),
                        SystemVoucherType::SALES_DISCOUNT,
                    );
                } catch (AccountingPolicyUnavailableException $exception) {
                    throw ValidationException::withMessages([
                        'account_mappings' => 'Không thể ghi sổ trả lại/giảm giá khi chưa có mapping tài khoản/policy được phê duyệt.',
                    ]);
                }
                $accountMappings = $this->accountMappingGate->requireSatisfied($salesDiscount, $policy);
            }

            $paymentMethod = strtolower($salesDiscount->payment_method ?? 'reduce_receivable');
            $sourceCreditAccount = match ($paymentMethod) {
                'bank' => '1121',
                'cash' => '1111',
                default => '131',
            };
            $creditAccount = $accountMappings === null
                ? $sourceCreditAccount
                : CommercialAdjustmentAccountMappingPostingGate::accountFor($accountMappings, 'settlement_credit', $salesDiscount, null, $sourceCreditAccount);

            $glLines = [];

            // 1. Revenue Discount
            // Nợ 5213 (Giảm giá hàng bán)
            $discountByAccount = [];
            foreach ($salesDiscount->lines as $line) {
                $sourceDebitAccount = $line->debit_account ?: '5213';
                $debitAcc = $accountMappings === null
                    ? $sourceDebitAccount
                    : CommercialAdjustmentAccountMappingPostingGate::accountFor($accountMappings, 'revenue_reduction', $salesDiscount, $line, $sourceDebitAccount);
                $lineAmt = $this->persistedMoney($line, 'amount');
                if (DecimalMoney::compare($lineAmt, DecimalMoney::ZERO) === 0) {
                    $lineAmt = $this->persistedMoney($line, 'discount_amount');
                }
                $discountByAccount[$debitAcc] = DecimalMoney::add(
                    $discountByAccount[$debitAcc] ?? DecimalMoney::ZERO,
                    $lineAmt,
                );
            }

            foreach ($discountByAccount as $acc => $amt) {
                if (DecimalMoney::compare($amt, DecimalMoney::ZERO) > 0) {
                    $glLines[] = [
                        'account_code' => $acc,
                        'description' => $salesDiscount->reason ?: 'Giảm giá hàng bán',
                        'debit_amount' => $amt,
                        'credit_amount' => 0,
                    ];
                }
            }

            // Nợ 33311 (Thuế GTGT đầu ra giảm), preserving each line's
            // explicitly mapped tax-account context.
            $taxByAccount = [];
            foreach ($salesDiscount->lines as $line) {
                $lineTax = $this->persistedMoney($line, 'tax_amount');
                if (DecimalMoney::compare($lineTax, DecimalMoney::ZERO) <= 0) {
                    continue;
                }
                $sourceTaxAccount = $line->tax_account ?: '33311';
                $taxAccount = $accountMappings === null
                    ? $sourceTaxAccount
                    : CommercialAdjustmentAccountMappingPostingGate::accountFor($accountMappings, 'output_vat_reduction', $salesDiscount, $line, $sourceTaxAccount);
                $taxByAccount[$taxAccount] = DecimalMoney::add(
                    $taxByAccount[$taxAccount] ?? DecimalMoney::ZERO,
                    $lineTax,
                );
            }
            foreach ($taxByAccount as $taxAccount => $taxAmount) {
                $glLines[] = [
                    'account_code' => $taxAccount,
                    'description' => 'Thuế GTGT giảm do giảm giá hàng bán',
                    'debit_amount' => $taxAmount,
                    'credit_amount' => 0,
                ];
            }

            // Có 131 / 1111 / 1121
            $persistedTotal = $this->persistedMoney($salesDiscount, 'total_amount');
            $totalCreditAmt = DecimalMoney::compare($persistedTotal, DecimalMoney::ZERO) !== 0
                ? $persistedTotal
                : DecimalMoney::add(
                    $this->persistedMoney($salesDiscount, 'sub_total'),
                    $this->persistedMoney($salesDiscount, 'tax_amount'),
                );
            $glLines[] = [
                'account_code' => $creditAccount,
                'description' => $salesDiscount->reason ?: 'Giảm trừ công nợ/tiền do giảm giá hàng bán',
                'debit_amount' => 0,
                'credit_amount' => $totalCreditAmt,
            ];

            $postingDate = $salesDiscount->accounting_date ? $salesDiscount->accounting_date->toDateString() : now()->toDateString();
            $voucherDate = $salesDiscount->voucher_date ? $salesDiscount->voucher_date->toDateString() : now()->toDateString();

            // Create Journal Entry — generate unique GL voucher number to allow re-posting
            $jeBaseNumber = 'GL-'.$salesDiscount->voucher_number;
            $existingCount = JournalEntry::where('company_id', $salesDiscount->company_id)
                ->where('voucher_number', 'like', $jeBaseNumber.'%')
                ->count();
            $jeVoucherNumber = $existingCount > 0
                ? $jeBaseNumber.'-R'.$existingCount
                : $jeBaseNumber;

            $je = $this->journalEntryService->createPosted([
                'company_id' => $salesDiscount->company_id,
                'voucher_type' => 'sales_discount',
                'voucher_number' => $jeVoucherNumber,
                'voucher_date' => $voucherDate,
                'posting_date' => $postingDate,
                'description' => $salesDiscount->reason ?? 'Giảm giá hàng bán '.$salesDiscount->voucher_number,
                'total_amount' => $totalCreditAmt,
                'status' => 'posted',
                'source_document_type' => SalesDiscount::class,
                'source_document_id' => $salesDiscount->id,
                'lines' => $glLines,
            ]);

            $salesDiscount->journal_entry_id = $je->id;
            $salesDiscount->is_posted = true;
            $salesDiscount->status = 'posted';
            $salesDiscount->save();
            $this->adjustmentSettlements->createFor($salesDiscount);

            if ($accountMappings !== null) {
                $this->auditService->record($salesDiscount, 'sales_discount.account_mappings_applied', [], [], null, [
                    'journal_entry_id' => $je->id,
                    'account_mapping_gate' => 'enforced',
                    'account_mappings' => $accountMappings,
                ]);
            }

            return $salesDiscount->load(['lines.item', 'customer', 'employee', 'bankAccount', 'references', 'journalEntry']);
        });
    }

    private function assertCanAutoPost(): void
    {
        if (! auth()->check() || ! auth()->user()->can('sales.discounts.post')) {
            throw new AuthorizationException('Posting a sales discount requires the dedicated posting permission.');
        }
    }

    private function assertDraftMutationIntent(array $data): void
    {
        if (! empty($data['is_posted']) || ($data['status'] ?? '') === 'posted') {
            throw new AuthorizationException('Posting must use the dedicated sales-discount posting action.');
        }
    }

    public function unpost(int|string $id): SalesDiscount
    {
        return DB::transaction(function () use ($id) {
            $salesDiscount = $this->scopeMutationToAuthenticatedCompany(SalesDiscount::query())->findOrFail($id);
            $this->periodGuard->assertOpen((int) $salesDiscount->company_id, $salesDiscount->accounting_date ?? $salesDiscount->voucher_date, 'bỏ ghi sổ chứng từ giảm giá hàng bán');
            if (! $salesDiscount->is_posted) {
                throw new Exception('Chứng từ chưa được ghi sổ.');
            }
            $this->assertHasNoPostedSettlementAllocations((int) $salesDiscount->company_id, 'sales_discount', (int) $salesDiscount->id);
            $this->assertNoPostedDependentDocuments((int) $salesDiscount->company_id, SalesDiscount::class, (int) $salesDiscount->id);

            if ($salesDiscount->journal_entry_id) {
                $this->journalEntryService->void($salesDiscount->journal_entry_id, (int) $salesDiscount->company_id);
            }

            $salesDiscount->is_posted = false;
            $salesDiscount->status = 'draft';
            CommercialSourceAuditContext::mark($salesDiscount, 'unposted');
            $salesDiscount->save();

            return $salesDiscount->load(['lines.item', 'customer', 'employee', 'bankAccount', 'references']);
        });
    }

    public function void(int|string $id): SalesDiscount
    {
        return DB::transaction(function () use ($id) {
            $salesDiscount = $this->scopeMutationToAuthenticatedCompany(SalesDiscount::query())->findOrFail($id);
            $this->periodGuard->assertOpen((int) $salesDiscount->company_id, $salesDiscount->accounting_date ?? $salesDiscount->voucher_date, 'hủy chứng từ giảm giá hàng bán');
            $this->assertHasNoPostedSettlementAllocations((int) $salesDiscount->company_id, 'sales_discount', (int) $salesDiscount->id);
            $this->assertNoPostedDependentDocuments((int) $salesDiscount->company_id, SalesDiscount::class, (int) $salesDiscount->id);

            if ($salesDiscount->journal_entry_id) {
                $this->journalEntryService->void($salesDiscount->journal_entry_id, (int) $salesDiscount->company_id);
            }

            $salesDiscount->is_posted = false;
            $salesDiscount->status = 'voided';
            CommercialSourceAuditContext::mark($salesDiscount, 'voided');
            $salesDiscount->save();

            return $salesDiscount->load(['lines.item', 'customer', 'employee', 'bankAccount', 'references']);
        });
    }

    public function duplicate(int|string $id): SalesDiscount
    {
        return DB::transaction(function () use ($id) {
            $original = $this->scopeMutationToAuthenticatedCompany(SalesDiscount::with('lines'))->findOrFail($id);
            $this->periodGuard->assertOpen((int) $original->company_id, now()->toDateString(), 'nhân bản chứng từ giảm giá hàng bán');

            $newVoucherNumber = $this->generateNextCode($original->company_id);

            $newDiscount = $original->replicate();
            $newDiscount->voucher_number = $newVoucherNumber;
            $newDiscount->is_posted = false;
            $newDiscount->journal_entry_id = null;
            $newDiscount->voucher_date = now()->toDateString();
            $newDiscount->accounting_date = now()->toDateString();
            $newDiscount->status = 'draft';
            CommercialSourceAuditContext::mark($newDiscount, 'duplicated');
            $newDiscount->save();

            foreach ($original->lines as $line) {
                $newLine = $line->replicate();
                $newLine->sales_discount_id = $newDiscount->id;
                $newLine->save();
            }

            if ($original->referenced_vouchers) {
                $newDiscount->syncReferences($original->referenced_vouchers);
            }

            return $newDiscount->load(['lines.item', 'customer', 'employee', 'bankAccount', 'references']);
        });
    }

    public function generateNextCode(int $companyId, ?string $prefix = 'GGHB'): string
    {
        $companyId = $this->requireCompanyId(['company_id' => $companyId]);
        $p = $prefix ?: 'GGHB';
        $latest = SalesDiscount::where('company_id', $companyId)
            ->where('voucher_number', 'like', $p.'%')
            ->orderBy('id', 'desc')
            ->value('voucher_number');

        if ($latest && preg_match('/'.preg_quote($p, '/').'[-]?(\d+)/', $latest, $m)) {
            $nextSeq = str_pad((int) $m[1] + 1, 5, '0', STR_PAD_LEFT);
        } else {
            $count = SalesDiscount::where('company_id', $companyId)->count() + 1;
            $nextSeq = str_pad($count, 5, '0', STR_PAD_LEFT);
        }

        return $p.$nextSeq;
    }

    public function getNextCode(int $companyId, ?string $prefix = 'GGHB'): string
    {
        return $this->generateNextCode($companyId, $prefix);
    }

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
}
