<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Exceptions\AccountingPolicyUnavailableException;
use App\Models\PurchaseDiscount;
use App\Services\Concerns\GuardsPostedDependentDocuments;
use App\Services\Concerns\GuardsPostedSettlementAllocations;
use App\Support\CommercialSourceAuditContext;
use App\Support\DecimalMoney;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PurchaseDiscountService extends BaseService
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
        $query = $this->scopeMutationToAuthenticatedCompany(PurchaseDiscount::with([
            'supplier',
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
        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
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
                    ->orWhere('supplier_name', 'like', "%{$s}%")
                    ->orWhere('reason', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%");
            });
        }

        if (isset($filters['per_page']) && is_numeric($filters['per_page']) && (int) $filters['per_page'] > 0) {
            return $query->paginate((int) $filters['per_page']);
        }

        return $query->get();
    }

    public function getById(int|string $id): PurchaseDiscount
    {
        return $this->scopeMutationToAuthenticatedCompany(PurchaseDiscount::with([
            'lines.item',
            'supplier',
            'employee',
            'bankAccount',
            'referenceInvoice',
            'references',
            'journalEntry.lines',
        ]))->findOrFail($id);
    }

    public function create(array $data): PurchaseDiscount
    {
        $autoPost = ! empty($data['is_posted']) || ($data['status'] ?? '') === 'posted';
        if ($autoPost) {
            $this->assertCanAutoPost();
        }

        // Resolve the trusted tenant and period before validating business
        // details so an unauthenticated/closed-period caller cannot learn or
        // mutate anything through payload validation precedence.
        $companyId = $this->requireCompanyId($data);
        $accountingDate = $data['accounting_date'] ?? $data['voucher_date'] ?? now()->toDateString();
        $this->periodGuard->assertOpen((int) $companyId, $accountingDate, 'lập chứng từ giảm giá hàng mua');

        return DB::transaction(function () use ($data, $autoPost, $companyId) {
            $subTotal = DecimalMoney::ZERO;
            $taxAmount = DecimalMoney::ZERO;
            $lines = $data['lines'] ?? [];
            $preparedLines = [];

            if (! is_array($lines) || $lines === []) {
                throw ValidationException::withMessages([
                    'lines' => 'A purchase discount requires at least one detail line.',
                ]);
            }
            if (empty($data['supplier_id'])) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'A supplier is required for a purchase discount.',
                ]);
            }

            foreach ($lines as $line) {
                $amounts = $this->lineAmounts($line);
                $preparedLines[] = array_merge($line, $amounts);
                $subTotal = DecimalMoney::add($subTotal, $amounts['amount']);
                $taxAmount = DecimalMoney::add($taxAmount, $amounts['tax']);
            }

            $totalAmount = DecimalMoney::add($subTotal, $taxAmount);

            CommercialDraftAccountEvidenceGate::assertSatisfied('purchase_discount', $lines);

            $rawPaymentMethod = $data['payment_method'] ?? 'reduce_payable';
            $paymentMethod = match ($rawPaymentMethod) {
                'cash_refund', 'cash' => 'cash',
                'bank_refund', 'bank' => 'bank',
                default => 'reduce_payable',
            };

            $isDecreaseDebt = isset($data['is_decrease_debt']) ? (bool) $data['is_decrease_debt'] : ($paymentMethod === 'reduce_payable');

            $voucherNumber = $data['voucher_number'] ?? $this->generateNextCode($companyId);

            $purchaseDiscount = PurchaseDiscount::create([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'supplier_name' => $data['supplier_name'] ?? null,
                'supplier_address' => $data['supplier_address'] ?? null,
                'tax_code' => $data['tax_code'] ?? null,
                'deliverer_name' => $data['deliverer_name'] ?? null,
                'receiver_name' => $data['receiver_name'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'voucher_type' => $data['voucher_type'] ?? 'purchase_discount',
                'payment_method' => $paymentMethod,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'voucher_number' => $voucherNumber,
                'voucher_date' => $data['voucher_date'] ?? now()->toDateString(),
                'accounting_date' => $data['accounting_date'] ?? $data['voucher_date'] ?? now()->toDateString(),
                'reason' => $data['reason'] ?? null,
                'description' => $data['description'] ?? $data['reason'] ?? 'Giảm giá hàng mua '.$voucherNumber,
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

            $defaultDebit = match ($paymentMethod) {
                'cash' => '1111',
                'bank' => '1121',
                default => '331',
            };

            foreach ($preparedLines as $idx => $line) {
                $purchaseDiscount->lines()->create([
                    'line_order' => $line['line_order'] ?? ($idx + 1),
                    'item_id' => $line['item_id'] ?? null,
                    'item_code' => $line['item_code'] ?? null,
                    'item_name' => $line['item_name'] ?? $line['description'] ?? null,
                    'description' => $line['description'] ?? $line['item_name'] ?? $purchaseDiscount->description ?? 'Giảm giá hàng mua',
                    'unit' => $line['unit'] ?? null,
                    'debit_account' => CommercialDraftAccountEvidenceGate::account($line, 'debit_account', 'purchase_discount', $defaultDebit),
                    'credit_account' => CommercialDraftAccountEvidenceGate::account($line, 'credit_account', 'purchase_discount', '1561'),
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'amount' => $line['amount'],
                    'discount_amount' => $line['amount'],
                    'tax_rate' => $line['tax_rate'],
                    'tax_amount' => $line['tax'],
                    'tax_account' => CommercialDraftAccountEvidenceGate::account($line, 'tax_account', 'purchase_discount', '1331'),
                    'invoice_number' => $line['invoice_number'] ?? null,
                    'invoice_date' => $line['invoice_date'] ?? null,
                    'order_id' => $line['order_id'] ?? null,
                    'contract_id' => $line['contract_id'] ?? null,
                ]);
            }

            if (! empty($data['referenced_vouchers'])) {
                $purchaseDiscount->syncReferences($data['referenced_vouchers']);
            }

            if ($autoPost) {
                return $this->post($purchaseDiscount->id);
            }

            return $purchaseDiscount->load(['lines.item', 'supplier', 'employee', 'bankAccount', 'references']);
        });
    }

    public function update(int|string $id, array $data): PurchaseDiscount
    {
        $this->assertDraftMutationIntent($data);

        return DB::transaction(function () use ($id, $data) {
            $purchaseDiscount = $this->scopeMutationToAuthenticatedCompany(PurchaseDiscount::with('lines'))->findOrFail($id);
            $this->periodGuard->assertOpen((int) $purchaseDiscount->company_id, $purchaseDiscount->accounting_date ?? $purchaseDiscount->voucher_date, 'sửa chứng từ giảm giá hàng mua');
            if (array_key_exists('accounting_date', $data)) {
                $this->periodGuard->assertOpen((int) $purchaseDiscount->company_id, $data['accounting_date'], 'sửa chứng từ giảm giá hàng mua');
            }

            $this->assertMutableSource($purchaseDiscount, 'sửa');

            $lines = $data['lines'] ?? null;
            CommercialDraftAccountEvidenceGate::assertUpdateLinesProvided('purchase_discount', $lines);
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
                $subTotal = $this->persistedMoney($purchaseDiscount, 'sub_total');
                $taxAmount = $this->persistedMoney($purchaseDiscount, 'tax_amount');
            }

            $totalAmount = DecimalMoney::add($subTotal, $taxAmount);

            CommercialDraftAccountEvidenceGate::assertSatisfied('purchase_discount', $lines ?? $purchaseDiscount->lines->map(
                static fn ($line): array => $line->toArray(),
            )->all());

            $rawPaymentMethod = $data['payment_method'] ?? $purchaseDiscount->payment_method ?? 'reduce_payable';
            $paymentMethod = match ($rawPaymentMethod) {
                'cash_refund', 'cash' => 'cash',
                'bank_refund', 'bank' => 'bank',
                default => 'reduce_payable',
            };

            $isDecreaseDebt = isset($data['is_decrease_debt']) ? (bool) $data['is_decrease_debt'] : ($paymentMethod === 'reduce_payable');

            $purchaseDiscount->update([
                'branch_id' => $data['branch_id'] ?? $purchaseDiscount->branch_id,
                'supplier_id' => $data['supplier_id'] ?? $purchaseDiscount->supplier_id,
                'supplier_name' => $data['supplier_name'] ?? $purchaseDiscount->supplier_name,
                'supplier_address' => $data['supplier_address'] ?? $purchaseDiscount->supplier_address,
                'tax_code' => $data['tax_code'] ?? $purchaseDiscount->tax_code,
                'deliverer_name' => $data['deliverer_name'] ?? $purchaseDiscount->deliverer_name,
                'receiver_name' => $data['receiver_name'] ?? $purchaseDiscount->receiver_name,
                'employee_id' => $data['employee_id'] ?? $purchaseDiscount->employee_id,
                'voucher_type' => $data['voucher_type'] ?? $purchaseDiscount->voucher_type,
                'payment_method' => $paymentMethod,
                'bank_account_id' => $data['bank_account_id'] ?? $purchaseDiscount->bank_account_id,
                'voucher_number' => $data['voucher_number'] ?? $purchaseDiscount->voucher_number,
                'voucher_date' => $data['voucher_date'] ?? $purchaseDiscount->voucher_date,
                'accounting_date' => $data['accounting_date'] ?? $purchaseDiscount->accounting_date,
                'reason' => $data['reason'] ?? $purchaseDiscount->reason,
                'description' => $data['description'] ?? $data['reason'] ?? $purchaseDiscount->description,
                'attached_docs' => $data['attached_docs'] ?? $purchaseDiscount->attached_docs,
                'sub_total' => $subTotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'grand_total' => $totalAmount,
                'is_decrease_debt' => $isDecreaseDebt,
                'status' => $data['status'] ?? $purchaseDiscount->status,
                'reference_invoice_id' => $data['reference_invoice_id'] ?? $purchaseDiscount->reference_invoice_id,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? $purchaseDiscount->referenced_vouchers,
                'updated_by' => $data['updated_by'] ?? auth()->id(),
            ]);

            if ($lines !== null) {
                $purchaseDiscount->lines()->delete();

                $defaultDebit = match ($paymentMethod) {
                    'cash' => '1111',
                    'bank' => '1121',
                    default => '331',
                };

                foreach ($preparedLines ?? [] as $idx => $line) {
                    $purchaseDiscount->lines()->create([
                        'line_order' => $line['line_order'] ?? ($idx + 1),
                        'item_id' => $line['item_id'] ?? null,
                        'item_code' => $line['item_code'] ?? null,
                        'item_name' => $line['item_name'] ?? $line['description'] ?? null,
                        'description' => $line['description'] ?? $line['item_name'] ?? $purchaseDiscount->description ?? 'Giảm giá hàng mua',
                        'unit' => $line['unit'] ?? null,
                        'debit_account' => CommercialDraftAccountEvidenceGate::account($line, 'debit_account', 'purchase_discount', $defaultDebit),
                        'credit_account' => CommercialDraftAccountEvidenceGate::account($line, 'credit_account', 'purchase_discount', '1561'),
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'amount' => $line['amount'],
                        'discount_amount' => $line['amount'],
                        'tax_rate' => $line['tax_rate'],
                        'tax_amount' => $line['tax'],
                        'tax_account' => CommercialDraftAccountEvidenceGate::account($line, 'tax_account', 'purchase_discount', '1331'),
                        'invoice_number' => $line['invoice_number'] ?? null,
                        'invoice_date' => $line['invoice_date'] ?? null,
                        'order_id' => $line['order_id'] ?? null,
                        'contract_id' => $line['contract_id'] ?? null,
                    ]);
                }
            }

            if (isset($data['referenced_vouchers'])) {
                $purchaseDiscount->syncReferences($data['referenced_vouchers']);
            }

            return $purchaseDiscount->load(['lines.item', 'supplier', 'employee', 'bankAccount', 'references']);
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
            $purchaseDiscount = $this->scopeMutationToAuthenticatedCompany(PurchaseDiscount::query())->findOrFail($id);
            $this->periodGuard->assertOpen((int) $purchaseDiscount->company_id, $purchaseDiscount->accounting_date ?? $purchaseDiscount->voucher_date, 'xóa chứng từ giảm giá hàng mua');
            $this->assertMutableSource($purchaseDiscount, 'xóa');
            $purchaseDiscount->lines()->delete();
            $purchaseDiscount->references()->delete();
            $purchaseDiscount->delete();

            return true;
        });
    }

    private function assertMutableSource(PurchaseDiscount $source, string $operation): void
    {
        $status = strtolower(trim((string) $source->status));
        if ($source->is_posted || in_array($status, ['posted', 'voided', 'cancelled', 'canceled'], true)) {
            throw new ConflictHttpException("Không thể {$operation} chứng từ đã ghi sổ hoặc đã hủy. Hãy sử dụng thao tác bỏ ghi sổ/hủy riêng.");
        }
    }

    public function post(int|string $id): PurchaseDiscount
    {
        return DB::transaction(function () use ($id) {
            $purchaseDiscount = $this->scopeMutationToAuthenticatedCompany(PurchaseDiscount::with('lines'))->findOrFail($id);
            $this->periodGuard->assertOpen((int) $purchaseDiscount->company_id, $purchaseDiscount->accounting_date ?? $purchaseDiscount->voucher_date, 'ghi sổ chứng từ giảm giá hàng mua');
            if ($purchaseDiscount->is_posted) {
                throw new Exception('Chứng từ đã được ghi sổ.');
            }
            app(CommercialAdjustmentCapacityService::class)->assertPurchaseDiscount($purchaseDiscount);

            $policy = null;
            $accountMappings = null;
            if ((bool) config('accounting.enforce_return_discount_posting_account_mappings', true)) {
                try {
                    $policy = $this->accountingPolicyResolver->requireForVoucher(
                        (int) $purchaseDiscount->company_id,
                        ($purchaseDiscount->accounting_date ?? $purchaseDiscount->voucher_date ?? now())->toDateString(),
                        SystemVoucherType::PURCHASE_DISCOUNT,
                    );
                } catch (AccountingPolicyUnavailableException $exception) {
                    throw ValidationException::withMessages([
                        'account_mappings' => 'Không thể ghi sổ trả lại/giảm giá khi chưa có mapping tài khoản/policy được phê duyệt.',
                    ]);
                }
                $accountMappings = $this->accountMappingGate->requireSatisfied($purchaseDiscount, $policy);
            }

            $paymentMethod = strtolower($purchaseDiscount->payment_method ?? 'reduce_payable');
            $sourceDebitAccount = match ($paymentMethod) {
                'bank', 'bank_refund' => '1121',
                'cash', 'cash_refund' => '1111',
                default => '331',
            };
            $debitAccount = $accountMappings === null
                ? $sourceDebitAccount
                : CommercialAdjustmentAccountMappingPostingGate::accountFor($accountMappings, 'settlement_debit', $purchaseDiscount, null, $sourceDebitAccount);

            $glLines = [];

            // 1. Debit Payable / Cash / Bank (Nợ 331 / 1111 / 1121)
            $persistedTotal = $this->persistedMoney($purchaseDiscount, 'total_amount');
            $totalDebitAmt = DecimalMoney::compare($persistedTotal, DecimalMoney::ZERO) !== 0
                ? $persistedTotal
                : DecimalMoney::add(
                    $this->persistedMoney($purchaseDiscount, 'sub_total'),
                    $this->persistedMoney($purchaseDiscount, 'tax_amount'),
                );
            $glLines[] = [
                'account_code' => $debitAccount,
                'description' => $purchaseDiscount->description ?: 'Giảm trừ công nợ/tiền do giảm giá hàng mua',
                'debit_amount' => $totalDebitAmt,
                'credit_amount' => 0,
            ];

            // 2. Credit Inventory Goods (Có 1561 / 152 / 632) and Tax Deductible (Có 1331)
            foreach ($purchaseDiscount->lines as $line) {
                $sourceCreditAccount = $line->credit_account ?: '1561';
                $creditAcc = $accountMappings === null
                    ? $sourceCreditAccount
                    : CommercialAdjustmentAccountMappingPostingGate::accountFor($accountMappings, 'inventory_credit', $purchaseDiscount, $line, $sourceCreditAccount);
                $lineAmt = $this->persistedMoney($line, 'amount');
                if (DecimalMoney::compare($lineAmt, DecimalMoney::ZERO) === 0) {
                    $lineAmt = $this->persistedMoney($line, 'discount_amount');
                }

                if (DecimalMoney::compare($lineAmt, DecimalMoney::ZERO) > 0) {
                    $glLines[] = [
                        'account_code' => $creditAcc,
                        'description' => $line->description ?: 'Giảm giá hàng mua',
                        'debit_amount' => 0,
                        'credit_amount' => $lineAmt,
                    ];
                }

                $lineTax = $this->persistedMoney($line, 'tax_amount');
                if (DecimalMoney::compare($lineTax, DecimalMoney::ZERO) > 0) {
                    $glLines[] = [
                        'account_code' => $accountMappings === null
                            ? ($line->tax_account ?: '1331')
                            : CommercialAdjustmentAccountMappingPostingGate::accountFor($accountMappings, 'input_vat_reduction', $purchaseDiscount, $line, $line->tax_account ?: '1331'),
                        'description' => 'Giảm thuế GTGT đầu vào do giảm giá hàng mua',
                        'debit_amount' => 0,
                        'credit_amount' => $lineTax,
                    ];
                }
            }

            $postingDate = $purchaseDiscount->accounting_date ? $purchaseDiscount->accounting_date->toDateString() : now()->toDateString();
            $voucherDate = $purchaseDiscount->voucher_date ? $purchaseDiscount->voucher_date->toDateString() : now()->toDateString();

            // Create Journal Entry (Strictly validates Sum Debit == Sum Credit)
            $je = $this->journalEntryService->createPosted([
                'company_id' => $purchaseDiscount->company_id,
                'voucher_type' => 'purchase_discount',
                'voucher_number' => 'GL-'.$purchaseDiscount->voucher_number,
                'voucher_date' => $voucherDate,
                'posting_date' => $postingDate,
                'description' => $purchaseDiscount->description ?? 'Giảm giá hàng mua '.$purchaseDiscount->voucher_number,
                'total_amount' => $totalDebitAmt,
                'status' => 'posted',
                'source_document_type' => PurchaseDiscount::class,
                'source_document_id' => $purchaseDiscount->id,
                'lines' => $glLines,
            ]);

            $purchaseDiscount->journal_entry_id = $je->id;
            $purchaseDiscount->is_posted = true;
            $purchaseDiscount->status = 'posted';
            $purchaseDiscount->save();
            $this->adjustmentSettlements->createFor($purchaseDiscount);

            if ($accountMappings !== null) {
                $this->auditService->record($purchaseDiscount, 'purchase_discount.account_mappings_applied', [], [], null, [
                    'journal_entry_id' => $je->id,
                    'account_mapping_gate' => 'enforced',
                    'account_mappings' => $accountMappings,
                ]);
            }

            return $purchaseDiscount->load(['lines.item', 'supplier', 'employee', 'bankAccount', 'references', 'journalEntry']);
        });
    }

    private function assertCanAutoPost(): void
    {
        if (! auth()->check() || ! auth()->user()->can('purchase.discounts.post')) {
            throw new AuthorizationException('Posting a purchase discount requires the dedicated posting permission.');
        }
    }

    private function assertDraftMutationIntent(array $data): void
    {
        if (! empty($data['is_posted']) || ($data['status'] ?? '') === 'posted') {
            throw new AuthorizationException('Posting must use the dedicated purchase-discount posting action.');
        }
    }

    public function unpost(int|string $id): PurchaseDiscount
    {
        return DB::transaction(function () use ($id) {
            $purchaseDiscount = $this->scopeMutationToAuthenticatedCompany(PurchaseDiscount::query())->findOrFail($id);
            $this->periodGuard->assertOpen((int) $purchaseDiscount->company_id, $purchaseDiscount->accounting_date ?? $purchaseDiscount->voucher_date, 'bỏ ghi sổ chứng từ giảm giá hàng mua');
            if (! $purchaseDiscount->is_posted) {
                throw new Exception('Chứng từ chưa được ghi sổ.');
            }
            $this->assertHasNoPostedSettlementAllocations((int) $purchaseDiscount->company_id, 'purchase_discount', (int) $purchaseDiscount->id);
            $this->assertNoPostedDependentDocuments((int) $purchaseDiscount->company_id, PurchaseDiscount::class, (int) $purchaseDiscount->id);

            if ($purchaseDiscount->journal_entry_id) {
                $this->journalEntryService->void($purchaseDiscount->journal_entry_id, (int) $purchaseDiscount->company_id);
            }

            $purchaseDiscount->is_posted = false;
            $purchaseDiscount->status = 'draft';
            CommercialSourceAuditContext::mark($purchaseDiscount, 'unposted');
            $purchaseDiscount->save();

            return $purchaseDiscount->load(['lines.item', 'supplier', 'employee', 'bankAccount', 'references']);
        });
    }

    public function void(int|string $id): PurchaseDiscount
    {
        return DB::transaction(function () use ($id) {
            $purchaseDiscount = $this->scopeMutationToAuthenticatedCompany(PurchaseDiscount::query())->findOrFail($id);
            $this->periodGuard->assertOpen((int) $purchaseDiscount->company_id, $purchaseDiscount->accounting_date ?? $purchaseDiscount->voucher_date, 'hủy chứng từ giảm giá hàng mua');
            $this->assertHasNoPostedSettlementAllocations((int) $purchaseDiscount->company_id, 'purchase_discount', (int) $purchaseDiscount->id);
            $this->assertNoPostedDependentDocuments((int) $purchaseDiscount->company_id, PurchaseDiscount::class, (int) $purchaseDiscount->id);

            if ($purchaseDiscount->journal_entry_id) {
                $this->journalEntryService->void($purchaseDiscount->journal_entry_id, (int) $purchaseDiscount->company_id);
            }

            $purchaseDiscount->is_posted = false;
            $purchaseDiscount->status = 'voided';
            CommercialSourceAuditContext::mark($purchaseDiscount, 'voided');
            $purchaseDiscount->save();

            return $purchaseDiscount->load(['lines.item', 'supplier', 'employee', 'bankAccount', 'references']);
        });
    }

    public function duplicate(int|string $id): PurchaseDiscount
    {
        return DB::transaction(function () use ($id) {
            $original = $this->scopeMutationToAuthenticatedCompany(PurchaseDiscount::with('lines'))->findOrFail($id);
            $this->periodGuard->assertOpen((int) $original->company_id, now()->toDateString(), 'nhân bản chứng từ giảm giá hàng mua');

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
                $newLine->purchase_discount_id = $newDiscount->id;
                $newLine->save();
            }

            if ($original->referenced_vouchers) {
                $newDiscount->syncReferences($original->referenced_vouchers);
            }

            return $newDiscount->load(['lines.item', 'supplier', 'employee', 'bankAccount', 'references']);
        });
    }

    public function generateNextCode(int $companyId, ?string $prefix = 'GGMH'): string
    {
        $companyId = $this->requireCompanyId(['company_id' => $companyId]);
        $p = $prefix ?: 'GGMH';
        $latest = PurchaseDiscount::where('company_id', $companyId)
            ->where('voucher_number', 'like', $p.'%')
            ->orderBy('id', 'desc')
            ->value('voucher_number');

        if ($latest && preg_match('/'.preg_quote($p, '/').'[-]?(\d+)/', $latest, $m)) {
            $nextSeq = str_pad((int) $m[1] + 1, 5, '0', STR_PAD_LEFT);
        } else {
            $count = PurchaseDiscount::where('company_id', $companyId)->count() + 1;
            $nextSeq = str_pad($count, 5, '0', STR_PAD_LEFT);
        }

        return $p.$nextSeq;
    }

    public function getNextCode(int $companyId, ?string $prefix = 'GGMH'): string
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
