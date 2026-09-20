<?php

namespace App\Services;

use App\Models\PurchaseInvoice;
use App\Support\CommercialSourceAuditContext;
use App\Support\CommercialTenantReferenceGuard;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PurchaseInvoiceService
{
    public function __construct(
        private readonly AccountingPeriodGuard $periodGuard,
        private readonly PurchaseExpenseAllocationService $purchaseExpenseAllocationService,
        private readonly PurchaseInvoiceQueryService $purchaseInvoiceQueryService,
        private readonly PurchaseInvoiceAmountCalculator $purchaseInvoiceAmountCalculator,
        private readonly PurchaseInvoicePostingService $purchaseInvoicePostingService,
    ) {}

    public function getAll(?int $companyId = null)
    {
        return $this->purchaseInvoiceQueryService->getAll($companyId);
    }

    public function getById($id): PurchaseInvoice
    {
        return $this->purchaseInvoiceQueryService->getById($id);
    }

    public function create(array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($data) {
            $companyId = $this->requireCompanyId($data);
            CommercialTenantReferenceGuard::assertPurchaseInvoice($data, $companyId);
            $accountingDate = $data['accounting_date'] ?? $data['invoice_date'] ?? now()->toDateString();
            $this->periodGuard->assertOpen($companyId, $accountingDate, 'lập hóa đơn mua hàng');
            $subTotal = DecimalMoney::ZERO;
            $taxAmount = DecimalMoney::ZERO;
            $sumExpenses = DecimalMoney::ZERO;
            $totalStockValue = DecimalMoney::ZERO;
            $sumLineDiscounts = DecimalMoney::ZERO;

            CommercialDraftAccountEvidenceGate::assertUpdateLinesProvided('purchase_invoice', isset($data['lines']) ? $data['lines'] : null);

            if (! isset($data['lines']) || ! is_array($data['lines']) || $data['lines'] === []) {
                throw ValidationException::withMessages([
                    'lines' => 'A purchase invoice requires at least one detail line.',
                ]);
            }

            foreach ($data['lines'] as $line) {
                $amounts = $this->lineAmounts($line);
                $subTotal = DecimalMoney::add($subTotal, $amounts['amount']);
                $sumLineDiscounts = DecimalMoney::add($sumLineDiscounts, $amounts['discount']);
                $taxAmount = DecimalMoney::add($taxAmount, $amounts['tax']);
                $sumExpenses = DecimalMoney::add($sumExpenses, $amounts['expense']);
                $totalStockValue = DecimalMoney::add($totalStockValue, $amounts['stock_value']);
            }

            $voucherType = $data['voucher_type'] ?? 'domestic_inward';
            $isPurchaseExpense = (bool) ($data['is_purchase_expense'] ?? false);
            $isService = $isPurchaseExpense || in_array($voucherType, ['service', '5. Mua dịch vụ']);
            $isDirect = in_array($voucherType, ['domestic_direct', 'import_direct']);
            $isStockInward = ! $isService && ! $isDirect;

            $totalPurchaseExpense = array_key_exists('purchase_expense', $data)
                ? $this->money($data['purchase_expense'])
                : $sumExpenses;

            if (! $isStockInward) {
                $totalStockValue = DecimalMoney::ZERO;
            } elseif (DecimalMoney::compare($totalStockValue, DecimalMoney::ZERO) === 0) {
                $totalStockValue = DecimalMoney::add(DecimalMoney::subtract($subTotal, $sumLineDiscounts), $totalPurchaseExpense);
            }

            $discountAmount = array_key_exists('discount_amount', $data) && DecimalMoney::compare($this->money($data['discount_amount']), DecimalMoney::ZERO) > 0
                ? $this->money($data['discount_amount'])
                : $sumLineDiscounts;
            $totalAmount = DecimalMoney::add(DecimalMoney::subtract($subTotal, $discountAmount), $taxAmount);

            CommercialDraftAccountEvidenceGate::assertSatisfied('purchase_invoice', $data['lines']);

            $invoiceNumber = $data['invoice_number'] ?? $this->generateNextCode($companyId);
            if (empty($data['supplier_id'])) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'A supplier is required; no default supplier may be selected.',
                ]);
            }

            $invoice = PurchaseInvoice::create([
                'company_id' => $companyId,
                'supplier_id' => (int) $data['supplier_id'],
                'supplier_name' => $data['supplier_name'] ?? null,
                'supplier_address' => $data['supplier_address'] ?? null,
                'contact_name' => array_key_exists('contact_name', $data) ? $data['contact_name'] : null,
                'invoice_address' => array_key_exists('invoice_address', $data) ? $data['invoice_address'] : null,
                'deliverer_name' => $data['deliverer_name'] ?? null,
                'receiver_name' => array_key_exists('receiver_name', $data) ? $data['receiver_name'] : null,
                'receiver_address' => array_key_exists('receiver_address', $data) ? $data['receiver_address'] : null,
                'tax_code' => array_key_exists('tax_code', $data) ? $data['tax_code'] : null,
                'employee_id' => $data['employee_id'] ?? null,
                'employee_name' => $data['employee_name'] ?? null,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'accounting_date' => $accountingDate,
                'due_date' => $data['due_date'] ?? now()->addDays(30)->toDateString(),
                'voucher_type' => $voucherType,
                'payment_method' => $data['payment_method'] ?? 'unpaid',
                'payment_status' => $data['payment_status'] ?? (($data['payment_method'] ?? 'unpaid') === 'unpaid' ? 'Unpaid' : 'Paid'),
                'payment_term_code' => $data['payment_term_code'] ?? null,
                'due_days' => isset($data['due_days']) ? (int) $data['due_days'] : 0,
                'spend_reason' => $data['spend_reason'] ?? null,
                'payment_slip_number' => array_key_exists('payment_slip_number', $data) ? $data['payment_slip_number'] : null,
                'is_purchase_expense' => $isPurchaseExpense,
                'is_include_invoice' => array_key_exists('is_include_invoice', $data) ? (bool) $data['is_include_invoice'] : true,
                'invoice_option' => array_key_exists('invoice_option', $data) ? $data['invoice_option'] : null,
                'invoice_symbol' => $data['invoice_symbol'] ?? null,
                'invoice_code' => $data['invoice_code'] ?? null,
                'invoice_form' => array_key_exists('invoice_form', $data) ? $data['invoice_form'] : null,
                'description' => $data['description'] ?? 'Hóa đơn mua hàng',
                'attached_docs' => $data['attached_docs'] ?? null,
                'currency' => $data['currency'] ?? 'VND',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'functional_currency_code' => $data['functional_currency_code'] ?? null,
                'functional_total_amount_raw' => $data['functional_total_amount_raw'] ?? null,
                'functional_total_amount_scale' => $data['functional_total_amount_scale'] ?? null,
                'original_total_amount_raw' => $data['original_total_amount_raw'] ?? null,
                'original_total_amount_scale' => $data['original_total_amount_scale'] ?? null,
                'sub_total' => $subTotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'purchase_expense' => $totalPurchaseExpense,
                'total_stock_value' => $totalStockValue,
                // Posting is a separate, gated lifecycle action. A create
                // caller must never make a persisted draft look posted by
                // supplying a status value in an internal payload.
                'status' => 'draft',
                'is_posted' => false,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? null,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            foreach ($data['lines'] ?? [] as $line) {
                $amounts = $this->lineAmounts($line);

                // Determine default debit account based on purchase type if not specified
                $defaultDebit = ($isService || in_array($voucherType, ['domestic_direct', 'import_direct'])) ? ($isPurchaseExpense ? '1562' : '642') : '1561';
                $lineStockValue = $isStockInward
                    ? (DecimalMoney::compare($amounts['stock_value'], DecimalMoney::ZERO) > 0 ? $amounts['stock_value'] : DecimalMoney::add(DecimalMoney::subtract($amounts['amount'], $amounts['discount']), $amounts['expense']))
                    : DecimalMoney::ZERO;

                $invoice->lines()->create([
                    'item_id' => $line['item_id'] ?? null,
                    'service_code' => $line['service_code'] ?? null,
                    'description' => $line['description'] ?? $invoice->description,
                    'debit_account' => CommercialDraftAccountEvidenceGate::account($line, 'debit_account', 'purchase_invoice', $defaultDebit),
                    'credit_account' => CommercialDraftAccountEvidenceGate::account($line, 'credit_account', 'purchase_invoice', '331'),
                    'quantity' => $amounts['quantity'],
                    'unit_price' => $amounts['unit_price'],
                    'amount' => $amounts['amount'],
                    'discount_rate' => $line['discount_rate'] ?? 0,
                    'discount_amount' => $amounts['discount'],
                    'tax_rate' => $line['tax_rate'] ?? 0,
                    'tax_amount' => $amounts['tax'],
                    'tax_account' => CommercialDraftAccountEvidenceGate::account($line, 'tax_account', 'purchase_invoice', '1331'),
                    'purchase_expense' => $amounts['expense'],
                    'stock_value' => $lineStockValue,
                    'unit' => $line['unit'] ?? null,
                    'warehouse' => $line['warehouse'] ?? null,
                    'warehouse_id' => $line['warehouse_id'] ?? null,
                    'warehouse_code' => $line['warehouse_code'] ?? null,
                    'vat_group' => $line['vat_group'] ?? null,
                    'import_tax_rate' => $line['import_tax_rate'] ?? 0,
                    'import_tax_amount' => $line['import_tax_amount'] ?? 0,
                    'invoice_symbol' => $line['invoice_symbol'] ?? null,
                    'invoice_number' => $line['invoice_number'] ?? null,
                    'invoice_date' => $line['invoice_date'] ?? null,
                    'order_id' => $line['order_id'] ?? null,
                    'contract_id' => $line['contract_id'] ?? null,
                    'cost_item_code' => $line['cost_item_code'] ?? null,
                    'cost_object_code' => $line['cost_object_code'] ?? null,
                ]);
            }

            if (isset($data['referenced_vouchers'])) {
                $invoice->syncReferences($data['referenced_vouchers']);
            }

            if (array_key_exists('expense_allocations', $data)) {
                $this->purchaseExpenseAllocationService->replaceForSource($invoice, $data['expense_allocations'] ?? []);
            }

            return $invoice->load(['lines', 'supplier', 'employee', 'references', 'expenseAllocations']);
        });
    }

    public function update($id, array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($id, $data) {
            $invoice = $this->scopeToActorCompany(PurchaseInvoice::query())->findOrFail($id);
            $this->assertMutationCompany($data, (int) $invoice->company_id);
            $companyId = (int) $invoice->company_id;
            CommercialTenantReferenceGuard::assertPurchaseInvoice([
                'supplier_id' => $data['supplier_id'] ?? $invoice->supplier_id,
                'employee_id' => $data['employee_id'] ?? $invoice->employee_id,
            ], $companyId);

            // The existing date is checked first so a client cannot move a
            // frozen source document out of a closed period.
            $this->periodGuard->assertOpen($invoice->company_id, $invoice->accounting_date ?? $invoice->invoice_date, 'sửa hóa đơn mua hàng');
            if (array_key_exists('accounting_date', $data)) {
                $this->periodGuard->assertOpen($invoice->company_id, $data['accounting_date'], 'sửa hóa đơn mua hàng');
            }

            // A posted purchase invoice is accounting evidence. Its amounts
            // and lines must be corrected through the established unpost/
            // reversal workflow, never overwritten in place.
            $this->assertMutableSource($invoice, 'sửa');

            if (array_key_exists('lines', $data) && (! is_array($data['lines']) || $data['lines'] === [])) {
                throw ValidationException::withMessages([
                    'lines' => 'A purchase invoice requires at least one detail line.',
                ]);
            }

            CommercialDraftAccountEvidenceGate::assertUpdateLinesProvided(
                'purchase_invoice',
                array_key_exists('lines', $data) ? $data['lines'] : null,
            );

            $subTotal = DecimalMoney::ZERO;
            $taxAmount = DecimalMoney::ZERO;
            $sumExpenses = DecimalMoney::ZERO;
            $totalStockValue = DecimalMoney::ZERO;
            $sumLineDiscounts = DecimalMoney::ZERO;

            if (isset($data['lines'])) {
                foreach ($data['lines'] as $line) {
                    $amounts = $this->lineAmounts($line);
                    $subTotal = DecimalMoney::add($subTotal, $amounts['amount']);
                    $sumLineDiscounts = DecimalMoney::add($sumLineDiscounts, $amounts['discount']);
                    $taxAmount = DecimalMoney::add($taxAmount, $amounts['tax']);
                    $sumExpenses = DecimalMoney::add($sumExpenses, $amounts['expense']);
                    $totalStockValue = DecimalMoney::add($totalStockValue, $amounts['stock_value']);
                }
            } else {
                $subTotal = $this->persistedMoney($invoice, 'sub_total');
                $taxAmount = $this->persistedMoney($invoice, 'tax_amount');
                $totalStockValue = $this->persistedMoney($invoice, 'total_stock_value');
                $sumLineDiscounts = $this->persistedMoney($invoice, 'discount_amount');
            }

            $totalPurchaseExpense = array_key_exists('purchase_expense', $data)
                ? $this->money($data['purchase_expense'])
                : (isset($data['lines']) ? $sumExpenses : $this->persistedMoney($invoice, 'purchase_expense'));

            $voucherType = $data['voucher_type'] ?? $invoice->voucher_type ?? 'domestic_inward';
            $isPurchaseExpense = array_key_exists('is_purchase_expense', $data)
                ? (bool) $data['is_purchase_expense']
                : (bool) $invoice->is_purchase_expense;
            $isService = $isPurchaseExpense || in_array($voucherType, ['service', '5. Mua dịch vụ']);
            $isDirect = in_array($voucherType, ['domestic_direct', 'import_direct']);
            $isStockInward = ! $isService && ! $isDirect;

            $discountAmount = array_key_exists('discount_amount', $data) && DecimalMoney::compare($this->money($data['discount_amount']), DecimalMoney::ZERO) > 0
                ? $this->money($data['discount_amount'])
                : (isset($data['lines']) ? $sumLineDiscounts : $this->persistedMoney($invoice, 'discount_amount'));
            $totalAmount = DecimalMoney::add(DecimalMoney::subtract($subTotal, $discountAmount), $taxAmount);

            if (! $isStockInward) {
                $totalStockValue = DecimalMoney::ZERO;
            } elseif (DecimalMoney::compare($totalStockValue, DecimalMoney::ZERO) === 0) {
                $totalStockValue = DecimalMoney::add(DecimalMoney::subtract($subTotal, $discountAmount), $totalPurchaseExpense);
            }

            CommercialDraftAccountEvidenceGate::assertSatisfied('purchase_invoice', isset($data['lines'])
                ? $data['lines']
                : $invoice->lines->map(static fn ($line): array => $line->toArray())->all());
            CommercialTenantReferenceGuard::assertPurchaseInvoice([
                'supplier_id' => $data['supplier_id'] ?? $invoice->supplier_id,
                'employee_id' => $data['employee_id'] ?? $invoice->employee_id,
                'lines' => ($data['lines'] ?? null) ?? $invoice->lines->map(static fn ($line): array => $line->toArray())->all(),
            ], $companyId);

            $invoice->update([
                'supplier_id' => $data['supplier_id'] ?? $invoice->supplier_id,
                'supplier_name' => array_key_exists('supplier_name', $data) ? $data['supplier_name'] : $invoice->supplier_name,
                'supplier_address' => array_key_exists('supplier_address', $data) ? $data['supplier_address'] : $invoice->supplier_address,
                'contact_name' => array_key_exists('contact_name', $data) ? $data['contact_name'] : $invoice->contact_name,
                'invoice_address' => array_key_exists('invoice_address', $data) ? $data['invoice_address'] : $invoice->invoice_address,
                'deliverer_name' => array_key_exists('deliverer_name', $data) ? $data['deliverer_name'] : $invoice->deliverer_name,
                'receiver_name' => array_key_exists('receiver_name', $data) ? $data['receiver_name'] : $invoice->receiver_name,
                'receiver_address' => array_key_exists('receiver_address', $data) ? $data['receiver_address'] : $invoice->receiver_address,
                'tax_code' => array_key_exists('tax_code', $data) ? $data['tax_code'] : $invoice->tax_code,
                'employee_id' => array_key_exists('employee_id', $data) ? $data['employee_id'] : $invoice->employee_id,
                'employee_name' => array_key_exists('employee_name', $data) ? $data['employee_name'] : $invoice->employee_name,
                'invoice_number' => $data['invoice_number'] ?? $invoice->invoice_number,
                'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
                'accounting_date' => $data['accounting_date'] ?? $data['invoice_date'] ?? $invoice->accounting_date,
                'due_date' => $data['due_date'] ?? $invoice->due_date,
                'voucher_type' => $voucherType,
                'payment_method' => $data['payment_method'] ?? $invoice->payment_method,
                'payment_status' => array_key_exists('payment_status', $data) ? $data['payment_status'] : $invoice->payment_status,
                'payment_term_code' => array_key_exists('payment_term_code', $data) ? $data['payment_term_code'] : $invoice->payment_term_code,
                'due_days' => array_key_exists('due_days', $data) ? ($data['due_days'] !== null ? (int) $data['due_days'] : null) : $invoice->due_days,
                'spend_reason' => array_key_exists('spend_reason', $data) ? $data['spend_reason'] : $invoice->spend_reason,
                'payment_slip_number' => array_key_exists('payment_slip_number', $data) ? $data['payment_slip_number'] : $invoice->payment_slip_number,
                'is_purchase_expense' => $isPurchaseExpense,
                'is_include_invoice' => array_key_exists('is_include_invoice', $data) ? (bool) $data['is_include_invoice'] : $invoice->is_include_invoice,
                'invoice_option' => array_key_exists('invoice_option', $data) ? $data['invoice_option'] : $invoice->invoice_option,
                'invoice_symbol' => array_key_exists('invoice_symbol', $data) ? $data['invoice_symbol'] : $invoice->invoice_symbol,
                'invoice_code' => array_key_exists('invoice_code', $data) ? $data['invoice_code'] : $invoice->invoice_code,
                'invoice_form' => array_key_exists('invoice_form', $data) ? $data['invoice_form'] : $invoice->invoice_form,
                'description' => array_key_exists('description', $data) ? $data['description'] : $invoice->description,
                'attached_docs' => array_key_exists('attached_docs', $data) ? $data['attached_docs'] : $invoice->attached_docs,
                'currency' => $data['currency'] ?? $invoice->currency,
                'exchange_rate' => $data['exchange_rate'] ?? $invoice->exchange_rate,
                'functional_currency_code' => $data['functional_currency_code'] ?? $invoice->functional_currency_code,
                'functional_total_amount_raw' => $data['functional_total_amount_raw'] ?? $invoice->functional_total_amount_raw,
                'functional_total_amount_scale' => $data['functional_total_amount_scale'] ?? $invoice->functional_total_amount_scale,
                'original_total_amount_raw' => $data['original_total_amount_raw'] ?? $invoice->original_total_amount_raw,
                'original_total_amount_scale' => $data['original_total_amount_scale'] ?? $invoice->original_total_amount_scale,
                'sub_total' => $subTotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'purchase_expense' => $totalPurchaseExpense,
                'total_stock_value' => $totalStockValue,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? $invoice->referenced_vouchers,
                'updated_by' => auth()->id(),
            ]);

            if (isset($data['lines'])) {
                $invoice->lines()->delete();
                $defaultDebit = ($isService || in_array($voucherType, ['domestic_direct', 'import_direct'])) ? ($isPurchaseExpense ? '1562' : '642') : '1561';

                foreach ($data['lines'] as $line) {
                    $amounts = $this->lineAmounts($line);
                    $lineStockValue = $isStockInward
                        ? (DecimalMoney::compare($amounts['stock_value'], DecimalMoney::ZERO) > 0 ? $amounts['stock_value'] : DecimalMoney::add(DecimalMoney::subtract($amounts['amount'], $amounts['discount']), $amounts['expense']))
                        : DecimalMoney::ZERO;

                    $invoice->lines()->create([
                        'item_id' => $line['item_id'] ?? null,
                        'service_code' => $line['service_code'] ?? null,
                        'description' => $line['description'] ?? $invoice->description,
                        'debit_account' => CommercialDraftAccountEvidenceGate::account($line, 'debit_account', 'purchase_invoice', $defaultDebit),
                        'credit_account' => CommercialDraftAccountEvidenceGate::account($line, 'credit_account', 'purchase_invoice', '331'),
                        'quantity' => $amounts['quantity'],
                        'unit_price' => $amounts['unit_price'],
                        'amount' => $amounts['amount'],
                        'discount_rate' => $line['discount_rate'] ?? 0,
                        'discount_amount' => $amounts['discount'],
                        'tax_rate' => $line['tax_rate'] ?? 0,
                        'tax_amount' => $amounts['tax'],
                        'tax_account' => CommercialDraftAccountEvidenceGate::account($line, 'tax_account', 'purchase_invoice', '1331'),
                        'purchase_expense' => $amounts['expense'],
                        'stock_value' => $lineStockValue,
                        'unit' => $line['unit'] ?? null,
                        'warehouse' => $line['warehouse'] ?? null,
                        'warehouse_id' => $line['warehouse_id'] ?? null,
                        'warehouse_code' => $line['warehouse_code'] ?? null,
                        'vat_group' => $line['vat_group'] ?? null,
                        'import_tax_rate' => $line['import_tax_rate'] ?? 0,
                        'import_tax_amount' => $line['import_tax_amount'] ?? 0,
                        'invoice_symbol' => $line['invoice_symbol'] ?? null,
                        'invoice_number' => $line['invoice_number'] ?? null,
                        'invoice_date' => $line['invoice_date'] ?? null,
                        'order_id' => $line['order_id'] ?? null,
                        'contract_id' => $line['contract_id'] ?? null,
                        'cost_item_code' => $line['cost_item_code'] ?? null,
                        'cost_object_code' => $line['cost_object_code'] ?? null,
                    ]);
                }
            }

            if (isset($data['referenced_vouchers'])) {
                $invoice->syncReferences($data['referenced_vouchers']);
            }

            if (array_key_exists('expense_allocations', $data)) {
                $this->purchaseExpenseAllocationService->replaceForSource($invoice, $data['expense_allocations'] ?? []);
            }

            return $invoice->load(['lines', 'supplier', 'employee', 'references', 'expenseAllocations']);
        });
    }

    public function post($id): PurchaseInvoice
    {
        return $this->purchaseInvoicePostingService->post($id);
    }

    public function void($id, string $auditEvent = 'voided'): PurchaseInvoice
    {
        return $this->purchaseInvoicePostingService->void($id, $auditEvent);
    }

    public function unpost($id): PurchaseInvoice
    {
        return $this->purchaseInvoicePostingService->unpost($id);
    }

    public function duplicate($id): PurchaseInvoice
    {
        return DB::transaction(function () use ($id) {
            $original = $this->scopeToActorCompany(PurchaseInvoice::with('lines'))->findOrFail($id);
            $this->periodGuard->assertOpen($original->company_id, now()->toDateString(), 'nhân bản hóa đơn mua hàng');
            $newNumber = 'HDMH-'.now()->format('Ymd').'-'.strtoupper(substr(uniqid(), -4));

            $newInvoice = $original->replicate();
            $newInvoice->invoice_number = $newNumber;
            $newInvoice->is_posted = false;
            $newInvoice->journal_entry_id = null;
            $newInvoice->status = 'draft';
            $newInvoice->accounting_date = now()->toDateString();
            $newInvoice->invoice_date = now()->toDateString();
            CommercialSourceAuditContext::mark($newInvoice, 'duplicated');
            $newInvoice->save();

            foreach ($original->lines as $line) {
                $newLine = $line->replicate();
                $newLine->purchase_invoice_id = $newInvoice->id;
                $newLine->save();
            }

            if ($original->referenced_vouchers) {
                $newInvoice->syncReferences($original->referenced_vouchers);
            }

            return $newInvoice->load(['lines', 'supplier', 'employee', 'references']);
        });
    }

    /**
     * Delete is deliberately kept in the source service so it cannot bypass
     * the same tenant-aware close guard used by update and lifecycle changes.
     */
    public function delete($id): bool
    {
        return DB::transaction(function () use ($id) {
            $invoice = $this->scopeToActorCompany(PurchaseInvoice::query())->findOrFail($id);
            $this->periodGuard->assertOpen($invoice->company_id, $invoice->accounting_date ?? $invoice->invoice_date, 'xóa hóa đơn mua hàng');

            $this->assertMutableSource($invoice, 'xóa');

            $invoice->lines()->delete();
            $invoice->references()->delete();
            $invoice->delete();

            return true;
        });
    }

    private function assertMutableSource(PurchaseInvoice $invoice, string $operation): void
    {
        $status = strtolower(trim((string) $invoice->status));
        if ($invoice->is_posted || $status === 'posted') {
            throw new ConflictHttpException("Không thể {$operation} hóa đơn đã ghi sổ. Vui lòng bỏ ghi sổ trước khi {$operation}.");
        }

        if (in_array($status, ['voided', 'cancelled', 'canceled'], true)) {
            throw new ConflictHttpException("Không thể {$operation} hóa đơn đã hủy.");
        }
    }

    public function generateNextCode(int $companyId): string
    {
        return $this->purchaseInvoiceQueryService->generateNextCode($companyId);
    }

    private function scopeToActorCompany($query)
    {
        $actor = auth()->user();
        if ($actor !== null) {
            if ($actor->company_id === null) {
                throw ValidationException::withMessages([
                    'company_id' => 'An authenticated company context is required.',
                ]);
            }
            $query->where('company_id', (int) $actor->company_id);
        }

        return $query;
    }

    private function assertMutationCompany(array $data, int $documentCompanyId): void
    {
        if (auth()->user()?->company_id !== null) {
            $this->requireCompanyId($data);

            return;
        }

        if (array_key_exists('company_id', $data) && (int) $data['company_id'] !== $documentCompanyId) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the invoice.',
            ]);
        }
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

    /**
     * Purchase document monetary columns are DECIMAL(*,2). Apply the storage
     * scale before aggregation so SQLite tests and MySQL production produce the
     * same line totals and the GL kernel never receives fractional cents.
     */
    private function money(mixed $value): string
    {
        return DecimalMoney::normalize($value);
    }

    /** @return array{quantity: string, unit_price: string, amount: string, discount: string, tax: string, expense: string, stock_value: string} */
    private function lineAmounts(array $line): array
    {
        return $this->purchaseInvoiceAmountCalculator->calculate($line);
    }

}
