<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Exceptions\AccountingPolicyUnavailableException;
use App\Models\PurchaseReturn;
use App\Services\Concerns\GuardsPostedDependentDocuments;
use App\Services\Concerns\GuardsPostedSettlementAllocations;
use App\Support\CommercialSourceAuditContext;
use App\Support\DecimalMoney;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PurchaseReturnService extends BaseService
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
        $query = PurchaseReturn::withoutGlobalScope('company')
            ->where('company_id', $this->requireCompanyId([]))
            ->with([
                'supplier',
                'employee',
                'bankAccount',
                'lines.item',
                'lines.warehouse',
                'references',
                'journalEntry.lines',
            ])
            ->orderBy('voucher_date', 'desc')
            ->orderBy('id', 'desc');

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

    public function getById(int|string $id): PurchaseReturn
    {
        return PurchaseReturn::withoutGlobalScope('company')
            ->where('company_id', $this->requireCompanyId([]))
            ->with([
                'lines.item',
                'lines.warehouse',
                'supplier',
                'employee',
                'bankAccount',
                'referenceInvoice',
                'references',
                'journalEntry.lines',
            ])->findOrFail($id);
    }

    public function create(array $data): PurchaseReturn
    {
        $autoPost = ! empty($data['is_posted']) || ($data['status'] ?? '') === 'posted';
        if ($autoPost) {
            $this->assertCanAutoPost();
        }

        $companyId = $this->requireCompanyId($data);
        $accountingDate = $data['accounting_date'] ?? $data['voucher_date'] ?? now()->toDateString();
        $this->periodGuard->assertOpen((int) $companyId, $accountingDate, 'lập chứng từ trả lại hàng mua');

        return DB::transaction(function () use ($data, $autoPost, $companyId) {
            $subTotal = DecimalMoney::ZERO;
            $taxAmount = DecimalMoney::ZERO;
            $sumDiscounts = DecimalMoney::ZERO;
            $lines = $data['lines'] ?? [];
            $preparedLines = [];

            if (! is_array($lines) || $lines === []) {
                throw ValidationException::withMessages([
                    'lines' => 'A purchase return requires at least one detail line.',
                ]);
            }
            if (empty($data['supplier_id'])) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'A supplier is required for a purchase return.',
                ]);
            }

            foreach ($lines as $line) {
                $amounts = $this->lineAmounts($line);
                $preparedLines[] = array_merge($line, $amounts);
                $subTotal = DecimalMoney::add($subTotal, $amounts['amount']);
                $sumDiscounts = DecimalMoney::add($sumDiscounts, $amounts['discount']);
                $taxAmount = DecimalMoney::add($taxAmount, $amounts['tax']);
            }

            $discountAmount = isset($data['discount_amount']) ? $this->money($data['discount_amount']) : $sumDiscounts;
            $totalAmount = DecimalMoney::add(DecimalMoney::subtract($subTotal, $discountAmount), $taxAmount);

            $rawPaymentMethod = $data['payment_method'] ?? 'reduce_payable';
            $paymentMethod = match ($rawPaymentMethod) {
                'cash_refund', 'cash' => 'cash',
                'bank_refund', 'bank' => 'bank',
                default => 'reduce_payable',
            };

            $isOutward = isset($data['is_outward']) ? (bool) $data['is_outward'] : (isset($data['is_export_slip']) ? (bool) $data['is_export_slip'] : (isset($data['is_stock_out']) ? (bool) $data['is_stock_out'] : true));
            $isDecreaseDebt = isset($data['is_decrease_debt']) ? (bool) $data['is_decrease_debt'] : ($paymentMethod === 'reduce_payable');

            CommercialDraftAccountEvidenceGate::assertSatisfied('purchase_return', $lines);

            $voucherNumber = $data['voucher_number'] ?? $this->generateNextCode($companyId);

            $purchaseReturn = PurchaseReturn::create([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'supplier_name' => $data['supplier_name'] ?? null,
                'supplier_address' => $data['supplier_address'] ?? null,
                'tax_code' => $data['tax_code'] ?? null,
                'deliverer_name' => $data['deliverer_name'] ?? null,
                'receiver_name' => $data['receiver_name'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'voucher_type' => $data['voucher_type'] ?? 'purchase_return',
                'payment_method' => $paymentMethod,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'voucher_number' => $voucherNumber,
                'voucher_date' => $data['voucher_date'] ?? now()->toDateString(),
                'accounting_date' => $data['accounting_date'] ?? $data['voucher_date'] ?? now()->toDateString(),
                'reason' => $data['reason'] ?? null,
                'description' => $data['description'] ?? $data['reason'] ?? 'Trả lại hàng mua '.$voucherNumber,
                'attached_docs' => $data['attached_docs'] ?? null,
                'sub_total' => $subTotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'grand_total' => $totalAmount,
                'is_posted' => false,
                'is_outward' => $isOutward,
                'is_export_slip' => $isOutward,
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
                $purchaseReturn->lines()->create([
                    'line_order' => $line['line_order'] ?? ($idx + 1),
                    'item_id' => $line['item_id'] ?? null,
                    'item_code' => $line['item_code'] ?? null,
                    'item_name' => $line['item_name'] ?? $line['description'] ?? null,
                    'description' => $line['description'] ?? $line['item_name'] ?? $purchaseReturn->description ?? 'Trả lại hàng mua',
                    'unit' => $line['unit'] ?? null,
                    'warehouse_id' => $line['warehouse_id'] ?? null,
                    'warehouse_code' => $line['warehouse_code'] ?? null,
                    'debit_account' => CommercialDraftAccountEvidenceGate::account($line, 'debit_account', 'purchase_return', $defaultDebit),
                    'credit_account' => CommercialDraftAccountEvidenceGate::account($line, 'credit_account', 'purchase_return', '1561'),
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'amount' => $line['amount'],
                    'discount_rate' => $this->money($line['discount_rate'] ?? 0),
                    'discount_amount' => $line['discount'],
                    'tax_rate' => $this->money($line['tax_rate'] ?? 0),
                    'tax_amount' => $line['tax'],
                    'tax_account' => CommercialDraftAccountEvidenceGate::account($line, 'tax_account', 'purchase_return', '1331'),
                    'invoice_number' => $line['invoice_number'] ?? null,
                    'invoice_date' => $line['invoice_date'] ?? null,
                    'order_id' => $line['order_id'] ?? null,
                    'contract_id' => $line['contract_id'] ?? null,
                ]);
            }

            if (! empty($data['referenced_vouchers'])) {
                $purchaseReturn->syncReferences($data['referenced_vouchers']);
            }

            if ($autoPost) {
                return $this->post($purchaseReturn->id);
            }

            return $purchaseReturn->load(['lines.item', 'lines.warehouse', 'supplier', 'employee', 'bankAccount', 'references']);
        });
    }

    public function update(int|string $id, array $data): PurchaseReturn
    {
        $this->assertDraftMutationIntent($data);

        return DB::transaction(function () use ($id, $data) {
            $purchaseReturn = PurchaseReturn::withoutGlobalScope('company')
                ->where('company_id', $this->requireCompanyId([]))
                ->with('lines')
                ->findOrFail($id);
            $this->periodGuard->assertOpen((int) $purchaseReturn->company_id, $purchaseReturn->accounting_date ?? $purchaseReturn->voucher_date, 'sửa chứng từ trả lại hàng mua');
            if (array_key_exists('accounting_date', $data)) {
                $this->periodGuard->assertOpen((int) $purchaseReturn->company_id, $data['accounting_date'], 'sửa chứng từ trả lại hàng mua');
            }

            $this->assertMutableSource($purchaseReturn, 'sửa');

            $lines = $data['lines'] ?? null;
            CommercialDraftAccountEvidenceGate::assertUpdateLinesProvided('purchase_return', $lines);
            $subTotal = DecimalMoney::ZERO;
            $taxAmount = DecimalMoney::ZERO;
            $sumDiscounts = DecimalMoney::ZERO;
            $preparedLines = null;

            if ($lines !== null) {
                $preparedLines = [];
                foreach ($lines as $line) {
                    $amounts = $this->lineAmounts($line);
                    $preparedLines[] = array_merge($line, $amounts);
                    $subTotal = DecimalMoney::add($subTotal, $amounts['amount']);
                    $sumDiscounts = DecimalMoney::add($sumDiscounts, $amounts['discount']);
                    $taxAmount = DecimalMoney::add($taxAmount, $amounts['tax']);
                }
            } else {
                $subTotal = $this->persistedMoney($purchaseReturn, 'sub_total');
                $taxAmount = $this->persistedMoney($purchaseReturn, 'tax_amount');
                $sumDiscounts = $this->persistedMoney($purchaseReturn, 'discount_amount');
            }

            $discountAmount = isset($data['discount_amount']) ? $this->money($data['discount_amount']) : $sumDiscounts;
            $totalAmount = DecimalMoney::add(DecimalMoney::subtract($subTotal, $discountAmount), $taxAmount);

            $rawPaymentMethod = $data['payment_method'] ?? $purchaseReturn->payment_method ?? 'reduce_payable';
            $paymentMethod = match ($rawPaymentMethod) {
                'cash_refund', 'cash' => 'cash',
                'bank_refund', 'bank' => 'bank',
                default => 'reduce_payable',
            };

            $isOutward = isset($data['is_outward']) ? (bool) $data['is_outward'] : (isset($data['is_export_slip']) ? (bool) $data['is_export_slip'] : (isset($data['is_stock_out']) ? (bool) $data['is_stock_out'] : $purchaseReturn->is_outward));
            $isDecreaseDebt = isset($data['is_decrease_debt']) ? (bool) $data['is_decrease_debt'] : ($paymentMethod === 'reduce_payable');

            CommercialDraftAccountEvidenceGate::assertSatisfied('purchase_return', $lines ?? $purchaseReturn->lines->map(
                static fn ($line): array => $line->toArray(),
            )->all());

            $purchaseReturn->update([
                'branch_id' => $data['branch_id'] ?? $purchaseReturn->branch_id,
                'supplier_id' => $data['supplier_id'] ?? $purchaseReturn->supplier_id,
                'supplier_name' => $data['supplier_name'] ?? $purchaseReturn->supplier_name,
                'supplier_address' => $data['supplier_address'] ?? $purchaseReturn->supplier_address,
                'tax_code' => $data['tax_code'] ?? $purchaseReturn->tax_code,
                'deliverer_name' => $data['deliverer_name'] ?? $purchaseReturn->deliverer_name,
                'receiver_name' => $data['receiver_name'] ?? $purchaseReturn->receiver_name,
                'employee_id' => $data['employee_id'] ?? $purchaseReturn->employee_id,
                'voucher_type' => $data['voucher_type'] ?? $purchaseReturn->voucher_type,
                'payment_method' => $paymentMethod,
                'bank_account_id' => $data['bank_account_id'] ?? $purchaseReturn->bank_account_id,
                'voucher_number' => $data['voucher_number'] ?? $purchaseReturn->voucher_number,
                'voucher_date' => $data['voucher_date'] ?? $purchaseReturn->voucher_date,
                'accounting_date' => $data['accounting_date'] ?? $purchaseReturn->accounting_date,
                'reason' => $data['reason'] ?? $purchaseReturn->reason,
                'description' => $data['description'] ?? $data['reason'] ?? $purchaseReturn->description,
                'attached_docs' => $data['attached_docs'] ?? $purchaseReturn->attached_docs,
                'sub_total' => $subTotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'grand_total' => $totalAmount,
                'is_outward' => $isOutward,
                'is_export_slip' => $isOutward,
                'is_decrease_debt' => $isDecreaseDebt,
                'status' => $data['status'] ?? $purchaseReturn->status,
                'reference_invoice_id' => $data['reference_invoice_id'] ?? $purchaseReturn->reference_invoice_id,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? $purchaseReturn->referenced_vouchers,
                'updated_by' => $data['updated_by'] ?? auth()->id(),
            ]);

            if ($lines !== null) {
                $purchaseReturn->lines()->delete();

                $defaultDebit = match ($paymentMethod) {
                    'cash' => '1111',
                    'bank' => '1121',
                    default => '331',
                };

                foreach ($preparedLines ?? [] as $idx => $line) {
                    $purchaseReturn->lines()->create([
                        'line_order' => $line['line_order'] ?? ($idx + 1),
                        'item_id' => $line['item_id'] ?? null,
                        'item_code' => $line['item_code'] ?? null,
                        'item_name' => $line['item_name'] ?? $line['description'] ?? null,
                        'description' => $line['description'] ?? $line['item_name'] ?? $purchaseReturn->description ?? 'Trả lại hàng mua',
                        'unit' => $line['unit'] ?? null,
                        'warehouse_id' => $line['warehouse_id'] ?? null,
                        'warehouse_code' => $line['warehouse_code'] ?? null,
                        'debit_account' => CommercialDraftAccountEvidenceGate::account($line, 'debit_account', 'purchase_return', $defaultDebit),
                        'credit_account' => CommercialDraftAccountEvidenceGate::account($line, 'credit_account', 'purchase_return', '1561'),
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'amount' => $line['amount'],
                        'discount_rate' => $this->money($line['discount_rate'] ?? 0),
                        'discount_amount' => $line['discount'],
                        'tax_rate' => $this->money($line['tax_rate'] ?? 0),
                        'tax_amount' => $line['tax'],
                        'tax_account' => CommercialDraftAccountEvidenceGate::account($line, 'tax_account', 'purchase_return', '1331'),
                        'invoice_number' => $line['invoice_number'] ?? null,
                        'invoice_date' => $line['invoice_date'] ?? null,
                        'order_id' => $line['order_id'] ?? null,
                        'contract_id' => $line['contract_id'] ?? null,
                    ]);
                }
            }

            if (isset($data['referenced_vouchers'])) {
                $purchaseReturn->syncReferences($data['referenced_vouchers']);
            }

            return $purchaseReturn->load(['lines.item', 'lines.warehouse', 'supplier', 'employee', 'bankAccount', 'references']);
        });
    }

    public function delete(int|string $id): bool
    {
        return DB::transaction(function () use ($id) {
            $purchaseReturn = PurchaseReturn::withoutGlobalScope('company')
                ->where('company_id', $this->requireCompanyId([]))
                ->findOrFail($id);
            $this->periodGuard->assertOpen((int) $purchaseReturn->company_id, $purchaseReturn->accounting_date ?? $purchaseReturn->voucher_date, 'xóa chứng từ trả lại hàng mua');
            $this->assertMutableSource($purchaseReturn, 'xóa');
            $purchaseReturn->lines()->delete();
            $purchaseReturn->references()->delete();
            $purchaseReturn->delete();

            return true;
        });
    }

    private function assertMutableSource(PurchaseReturn $source, string $operation): void
    {
        $status = strtolower(trim((string) $source->status));
        if ($source->is_posted || in_array($status, ['posted', 'voided', 'cancelled', 'canceled'], true)) {
            throw new ConflictHttpException("Không thể {$operation} chứng từ đã ghi sổ hoặc đã hủy. Hãy sử dụng thao tác bỏ ghi sổ/hủy riêng.");
        }
    }

    public function post(int|string $id): PurchaseReturn
    {
        return DB::transaction(function () use ($id) {
            $purchaseReturn = PurchaseReturn::withoutGlobalScope('company')
                ->where('company_id', $this->requireCompanyId([]))
                ->with('lines')
                ->findOrFail($id);
            $this->periodGuard->assertOpen((int) $purchaseReturn->company_id, $purchaseReturn->accounting_date ?? $purchaseReturn->voucher_date, 'ghi sổ chứng từ trả lại hàng mua');
            if ($purchaseReturn->is_posted) {
                throw new Exception('Chứng từ đã được ghi sổ.');
            }
            app(CommercialAdjustmentCapacityService::class)->assertPurchaseReturn($purchaseReturn);

            $policy = null;
            $accountMappings = null;
            if ((bool) config('accounting.enforce_return_discount_posting_account_mappings', true)) {
                try {
                    $policy = $this->accountingPolicyResolver->requireForVoucher(
                        (int) $purchaseReturn->company_id,
                        ($purchaseReturn->accounting_date ?? $purchaseReturn->voucher_date ?? now())->toDateString(),
                        SystemVoucherType::PURCHASE_RETURN,
                    );
                } catch (AccountingPolicyUnavailableException $exception) {
                    // Preserve the established field-level response for a
                    // draft that has not yet received a tenant policy.
                    throw ValidationException::withMessages([
                        'account_mappings' => 'Không thể ghi sổ trả lại/giảm giá khi chưa có mapping tài khoản/policy được phê duyệt.',
                    ]);
                }
                $accountMappings = $this->accountMappingGate->requireSatisfied($purchaseReturn, $policy);
            }

            $paymentMethod = strtolower($purchaseReturn->payment_method ?? 'reduce_payable');
            $sourceDebitAccount = match ($paymentMethod) {
                'bank', 'bank_refund' => '1121',
                'cash', 'cash_refund' => '1111',
                default => '331',
            };
            $debitAccount = $accountMappings === null
                ? $sourceDebitAccount
                : CommercialAdjustmentAccountMappingPostingGate::accountFor($accountMappings, 'settlement_debit', $purchaseReturn, null, $sourceDebitAccount);

            $glLines = [];

            // 1. Debit Payable / Cash / Bank Account (Nợ 331 / 1111 / 1121)
            $totalDebitAmt = $this->persistedMoney($purchaseReturn, 'total_amount');
            $glLines[] = [
                'account_code' => $debitAccount,
                'description' => $purchaseReturn->description ?: 'Giảm trừ công nợ/tiền hàng mua trả lại',
                'debit_amount' => $totalDebitAmt,
                'credit_amount' => 0,
            ];

            // 2. Credit Inventory Goods (Có 1561 / 152 / 153) and VAT Deductible Reduction (Có 1331)
            foreach ($purchaseReturn->lines as $line) {
                $sourceCreditAccount = $line->credit_account ?: '1561';
                $creditAcc = $accountMappings === null
                    ? $sourceCreditAccount
                    : CommercialAdjustmentAccountMappingPostingGate::accountFor($accountMappings, 'inventory_credit', $purchaseReturn, $line, $sourceCreditAccount);
                $netLineAmt = DecimalMoney::subtract(
                    $this->persistedMoney($line, 'amount'),
                    $this->persistedMoney($line, 'discount_amount'),
                );

                if (DecimalMoney::compare($netLineAmt, DecimalMoney::ZERO) > 0) {
                    $glLines[] = [
                        'account_code' => $creditAcc,
                        'description' => $line->description ?: 'Xuất trả lại hàng mua',
                        'debit_amount' => 0,
                        'credit_amount' => $netLineAmt,
                    ];
                }

                $taxAmount = $this->persistedMoney($line, 'tax_amount');
                if (DecimalMoney::compare($taxAmount, DecimalMoney::ZERO) > 0) {
                    $glLines[] = [
                        'account_code' => $accountMappings === null
                            ? ($line->tax_account ?: '1331')
                            : CommercialAdjustmentAccountMappingPostingGate::accountFor($accountMappings, 'input_vat_reduction', $purchaseReturn, $line, $line->tax_account ?: '1331'),
                        'description' => 'Giảm thuế GTGT đầu vào do trả lại hàng mua',
                        'debit_amount' => 0,
                        'credit_amount' => $taxAmount,
                    ];
                }
            }

            $postingDate = $purchaseReturn->accounting_date ? $purchaseReturn->accounting_date->toDateString() : now()->toDateString();
            $voucherDate = $purchaseReturn->voucher_date ? $purchaseReturn->voucher_date->toDateString() : now()->toDateString();

            // Create Journal Entry (Strictly validates Sum Debit == Sum Credit)
            $je = $this->journalEntryService->createPosted([
                'company_id' => $purchaseReturn->company_id,
                'voucher_type' => 'purchase_return',
                'voucher_number' => 'GL-'.$purchaseReturn->voucher_number,
                'voucher_date' => $voucherDate,
                'posting_date' => $postingDate,
                'description' => $purchaseReturn->description ?? 'Trả lại hàng mua '.$purchaseReturn->voucher_number,
                'total_amount' => $totalDebitAmt,
                'status' => 'posted',
                'source_document_type' => PurchaseReturn::class,
                'source_document_id' => $purchaseReturn->id,
                'lines' => $glLines,
            ]);

            $purchaseReturn->journal_entry_id = $je->id;
            $purchaseReturn->is_posted = true;
            $purchaseReturn->status = 'posted';
            $purchaseReturn->save();
            $this->adjustmentSettlements->createFor($purchaseReturn);

            if ($accountMappings !== null) {
                $this->auditService->record($purchaseReturn, 'purchase_return.account_mappings_applied', [], [], null, [
                    'journal_entry_id' => $je->id,
                    'account_mapping_gate' => 'enforced',
                    'account_mappings' => $accountMappings,
                ]);
            }

            return $purchaseReturn->load(['lines.item', 'lines.warehouse', 'supplier', 'employee', 'bankAccount', 'references', 'journalEntry']);
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

    /** @return array{quantity:string,unit_price:string,amount:string,discount:string,tax:string} */
    private function lineAmounts(array $line): array
    {
        $quantity = $this->money($line['quantity'] ?? '1');
        $unitPrice = $this->money($line['unit_price'] ?? '0');
        $rawAmount = trim((string) ($line['amount'] ?? ''));
        $amount = $rawAmount !== ''
            ? $this->money($rawAmount)
            : DecimalMoney::multiply($quantity, $unitPrice);
        $rawDiscount = trim((string) ($line['discount_amount'] ?? ''));
        $discount = $rawDiscount !== ''
            ? $this->money($rawDiscount)
            : DecimalMoney::percentage($amount, $line['discount_rate'] ?? '0');
        $net = DecimalMoney::subtract($amount, $discount);
        $rawTax = trim((string) ($line['tax_amount'] ?? ''));
        $tax = $rawTax !== ''
            ? $this->money($rawTax)
            : DecimalMoney::percentage($net, $line['tax_rate'] ?? '0');

        return [
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $amount,
            'discount' => $discount,
            'tax' => $tax,
        ];
    }

    private function assertCanAutoPost(): void
    {
        if (! auth()->check() || ! auth()->user()->can('purchase.returns.post')) {
            throw new AuthorizationException('Posting a purchase return requires the dedicated posting permission.');
        }
    }

    private function assertDraftMutationIntent(array $data): void
    {
        if (! empty($data['is_posted']) || ($data['status'] ?? '') === 'posted') {
            throw new AuthorizationException('Posting must use the dedicated purchase-return posting action.');
        }
    }

    public function unpost(int|string $id): PurchaseReturn
    {
        return DB::transaction(function () use ($id) {
            $purchaseReturn = PurchaseReturn::withoutGlobalScope('company')
                ->where('company_id', $this->requireCompanyId([]))
                ->findOrFail($id);
            $this->periodGuard->assertOpen((int) $purchaseReturn->company_id, $purchaseReturn->accounting_date ?? $purchaseReturn->voucher_date, 'bỏ ghi sổ chứng từ trả lại hàng mua');
            if (! $purchaseReturn->is_posted) {
                throw new Exception('Chứng từ chưa được ghi sổ.');
            }
            $this->assertHasNoPostedSettlementAllocations((int) $purchaseReturn->company_id, 'purchase_return', (int) $purchaseReturn->id);
            $this->assertNoPostedDependentDocuments((int) $purchaseReturn->company_id, PurchaseReturn::class, (int) $purchaseReturn->id);

            if ($purchaseReturn->journal_entry_id) {
                $this->journalEntryService->void($purchaseReturn->journal_entry_id, (int) $purchaseReturn->company_id);
            }

            $purchaseReturn->is_posted = false;
            $purchaseReturn->status = 'draft';
            CommercialSourceAuditContext::mark($purchaseReturn, 'unposted');
            $purchaseReturn->save();

            return $purchaseReturn->load(['lines.item', 'lines.warehouse', 'supplier', 'employee', 'bankAccount', 'references']);
        });
    }

    public function void(int|string $id): PurchaseReturn
    {
        return DB::transaction(function () use ($id) {
            $purchaseReturn = PurchaseReturn::withoutGlobalScope('company')
                ->where('company_id', $this->requireCompanyId([]))
                ->findOrFail($id);
            $this->periodGuard->assertOpen((int) $purchaseReturn->company_id, $purchaseReturn->accounting_date ?? $purchaseReturn->voucher_date, 'hủy chứng từ trả lại hàng mua');
            $this->assertHasNoPostedSettlementAllocations((int) $purchaseReturn->company_id, 'purchase_return', (int) $purchaseReturn->id);
            $this->assertNoPostedDependentDocuments((int) $purchaseReturn->company_id, PurchaseReturn::class, (int) $purchaseReturn->id);

            if ($purchaseReturn->journal_entry_id) {
                $this->journalEntryService->void($purchaseReturn->journal_entry_id, (int) $purchaseReturn->company_id);
            }

            $purchaseReturn->is_posted = false;
            $purchaseReturn->status = 'voided';
            CommercialSourceAuditContext::mark($purchaseReturn, 'voided');
            $purchaseReturn->save();

            return $purchaseReturn->load(['lines.item', 'lines.warehouse', 'supplier', 'employee', 'bankAccount', 'references']);
        });
    }

    public function duplicate(int|string $id): PurchaseReturn
    {
        return DB::transaction(function () use ($id) {
            $original = PurchaseReturn::withoutGlobalScope('company')
                ->where('company_id', $this->requireCompanyId([]))
                ->with('lines')
                ->findOrFail($id);
            $this->periodGuard->assertOpen((int) $original->company_id, now()->toDateString(), 'nhân bản chứng từ trả lại hàng mua');

            $newVoucherNumber = $this->generateNextCode($original->company_id);

            $newReturn = $original->replicate();
            $newReturn->voucher_number = $newVoucherNumber;
            $newReturn->is_posted = false;
            $newReturn->journal_entry_id = null;
            $newReturn->voucher_date = now()->toDateString();
            $newReturn->accounting_date = now()->toDateString();
            $newReturn->status = 'draft';
            CommercialSourceAuditContext::mark($newReturn, 'duplicated');
            $newReturn->save();

            foreach ($original->lines as $line) {
                $newLine = $line->replicate();
                $newLine->purchase_return_id = $newReturn->id;
                $newLine->save();
            }

            if ($original->referenced_vouchers) {
                $newReturn->syncReferences($original->referenced_vouchers);
            }

            return $newReturn->load(['lines.item', 'lines.warehouse', 'supplier', 'employee', 'bankAccount', 'references']);
        });
    }

    public function generateNextCode(int $companyId, ?string $prefix = 'TLMH'): string
    {
        $companyId = $this->requireCompanyId(['company_id' => $companyId]);
        $p = $prefix ?: 'TLMH';
        $latest = PurchaseReturn::where('company_id', $companyId)
            ->where('voucher_number', 'like', $p.'%')
            ->orderBy('id', 'desc')
            ->value('voucher_number');

        if ($latest && preg_match('/'.preg_quote($p, '/').'[-]?(\d+)/', $latest, $m)) {
            $nextSeq = str_pad((int) $m[1] + 1, 5, '0', STR_PAD_LEFT);
        } else {
            $count = PurchaseReturn::where('company_id', $companyId)->count() + 1;
            $nextSeq = str_pad($count, 5, '0', STR_PAD_LEFT);
        }

        return $p.$nextSeq;
    }

    public function getNextCode(int $companyId, ?string $prefix = 'TLMH'): string
    {
        return $this->generateNextCode($companyId, $prefix);
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
