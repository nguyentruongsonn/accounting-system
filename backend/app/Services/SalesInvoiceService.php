<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Models\Item;
use App\Models\SalesInvoice;
use App\Services\Concerns\GuardsPostedSettlementAllocations;
use App\Support\CommercialSourceAuditContext;
use App\Support\CommercialTenantReferenceGuard;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SalesInvoiceService
{
    use GuardsPostedSettlementAllocations;

    protected JournalEntryService $journalEntryService;

    public function __construct(
        JournalEntryService $journalEntryService,
        private readonly AuditService $auditService,
        private readonly AccountingPolicyResolver $accountingPolicyResolver,
        private readonly CoreDocumentPostingAuthorizer $postingAuthorizer,
        private readonly SalesInvoiceAccountMappingPostingGate $accountMappingGate,
        private readonly SalesInvoiceDimensionPostingGate $dimensionGate,
        private readonly AccountingPeriodGuard $periodGuard,
        private readonly PostedDependentDocumentGuard $dependentDocumentGuard,
    ) {
        $this->journalEntryService = $journalEntryService;
    }

    public function getAll(array $filters = [])
    {
        $query = $this->scopeToActorCompany(SalesInvoice::with(['customer', 'employee', 'lines.item', 'references']))
            ->orderBy('invoice_date', 'desc')
            ->orderBy('id', 'desc');

        if (! empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if (! empty($filters['from_date'])) {
            $query->whereDate('invoice_date', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate('invoice_date', '<=', $filters['to_date']);
        }
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('invoice_number', 'like', "%{$s}%")
                    ->orWhere('customer_name', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%");
            });
        }

        return $query->get();
    }

    public function getById($id): SalesInvoice
    {
        $query = $this->scopeToActorCompany(
            SalesInvoice::with(['lines.item', 'customer', 'employee', 'references', 'journalEntry.lines'])
        );

        return $query->findOrFail($id);
    }

    public function create(array $data): SalesInvoice
    {
        return DB::transaction(function () use ($data) {
            $companyId = $this->requireCompanyId($data);
            CommercialTenantReferenceGuard::assertSalesInvoice($data, $companyId);
            $accountingDate = $data['accounting_date'] ?? $data['invoice_date'] ?? now()->toDateString();
            $this->periodGuard->assertOpen($companyId, $accountingDate, 'lập hóa đơn bán hàng');
            $subTotal = DecimalMoney::ZERO;
            $taxAmount = DecimalMoney::ZERO;
            $sumLineDiscounts = DecimalMoney::ZERO;
            $lines = $data['lines'] ?? [];

            if (! is_array($lines) || $lines === []) {
                throw ValidationException::withMessages([
                    'lines' => 'A sales invoice requires at least one detail line.',
                ]);
            }

            foreach ($lines as $line) {
                $amounts = $this->lineAmounts($line);
                $subTotal = DecimalMoney::add($subTotal, $amounts['amount']);
                $sumLineDiscounts = DecimalMoney::add($sumLineDiscounts, $amounts['discount']);
                $taxAmount = DecimalMoney::add($taxAmount, $amounts['tax']);
            }

            CommercialDraftAccountEvidenceGate::assertSatisfied('sales_invoice', $lines, [
                'is_export_slip' => ! empty($data['is_export_slip']) || ! empty($data['is_include_delivery']),
            ]);

            $discountAmount = array_key_exists('discount_amount', $data) && DecimalMoney::compare($this->money($data['discount_amount']), DecimalMoney::ZERO) > 0
                ? $this->money($data['discount_amount'])
                : $sumLineDiscounts;
            $totalAmount = DecimalMoney::add(DecimalMoney::subtract($subTotal, $discountAmount), $taxAmount);
            $isExportSlip = ! empty($data['is_export_slip']) || ! empty($data['is_include_delivery']);
            $paymentMethod = array_key_exists('payment_method', $data)
                ? (string) $data['payment_method']
                : $this->paymentMethodFromLineEvidence($lines);
            if (empty($data['customer_id'])) {
                throw ValidationException::withMessages([
                    'customer_id' => 'A customer is required; no default customer may be selected.',
                ]);
            }

            $invoice = SalesInvoice::create([
                'company_id' => $companyId,
                'customer_id' => (int) $data['customer_id'],
                'customer_name' => $data['customer_name'] ?? null,
                'customer_address' => $data['customer_address'] ?? null,
                'receiver_name' => $data['receiver_name'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'employee_name' => $data['employee_name'] ?? null,
                'voucher_type' => $data['voucher_type'] ?? 'domestic',
                'payment_method' => $paymentMethod,
                'invoice_number' => $data['invoice_number'] ?? $this->generateNextCode($companyId),
                'invoice_symbol' => $data['invoice_symbol'] ?? null,
                'invoice_code' => $data['invoice_code'] ?? null,
                'delivery_voucher_number' => $data['delivery_voucher_number'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'accounting_date' => $accountingDate,
                'due_date' => $data['due_date'] ?? now()->addDays(30)->toDateString(),
                'sub_total' => $subTotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'status' => $paymentMethod === 'unpaid' ? 'Unpaid' : 'Paid',
                'payment_status' => $paymentMethod === 'unpaid' ? 'Unpaid' : 'Paid',
                'description' => $data['description'] ?? null,
                'attached_docs' => $data['attached_docs'] ?? null,
                'currency' => $data['currency'] ?? 'VND',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'functional_currency_code' => $data['functional_currency_code'] ?? null,
                'functional_total_amount_raw' => $data['functional_total_amount_raw'] ?? null,
                'functional_total_amount_scale' => $data['functional_total_amount_scale'] ?? null,
                'original_total_amount_raw' => $data['original_total_amount_raw'] ?? null,
                'original_total_amount_scale' => $data['original_total_amount_scale'] ?? null,
                'is_export_slip' => $isExportSlip,
                'is_posted' => false,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $defaultDebit = in_array($paymentMethod, ['cash', 'bank'])
                ? ($paymentMethod === 'cash' ? '1111' : '1121')
                : '131';

            foreach ($lines as $line) {
                $amounts = $this->lineAmounts($line);

                $invoice->lines()->create([
                    'item_id' => $line['item_id'] ?? null,
                    'description' => $line['description'] ?? $invoice->description,
                    'unit' => $line['unit'] ?? null,
                    'warehouse_id' => $line['warehouse_id'] ?? null,
                    'warehouse_code' => $line['warehouse_code'] ?? null,
                    'debit_account' => CommercialDraftAccountEvidenceGate::account($line, 'debit_account', 'sales_invoice', $defaultDebit),
                    'credit_account' => CommercialDraftAccountEvidenceGate::account($line, 'credit_account', 'sales_invoice', '5111'),
                    'quantity' => $amounts['quantity'],
                    'unit_price' => $amounts['unit_price'],
                    'amount' => $amounts['amount'],
                    'discount_rate' => $line['discount_rate'] ?? 0,
                    'discount_amount' => $amounts['discount'],
                    'tax_rate' => $line['tax_rate'] ?? 0,
                    'tax_amount' => $amounts['tax'],
                    'tax_account' => CommercialDraftAccountEvidenceGate::account($line, 'tax_account', 'sales_invoice', '33311'),
                    'inventory_account' => CommercialDraftAccountEvidenceGate::account($line, 'inventory_account', 'sales_invoice', '1561'),
                    'cogs_account' => CommercialDraftAccountEvidenceGate::account($line, 'cogs_account', 'sales_invoice', $line['cogs_debit_account'] ?? '632'),
                    'cogs_debit_account' => CommercialDraftAccountEvidenceGate::account($line, 'cogs_debit_account', 'sales_invoice', '632'),
                    'cogs_credit_account' => CommercialDraftAccountEvidenceGate::account($line, 'cogs_credit_account', 'sales_invoice', '1561'),
                    'cogs_price' => $amounts['cogs_price'],
                    'cogs_unit_price' => $amounts['cogs_price'],
                    'cogs_amount' => $amounts['cogs_amount'],
                    'vat_group' => $line['vat_group'] ?? null,
                    'order_id' => $line['order_id'] ?? null,
                    'contract_id' => $line['contract_id'] ?? null,
                ]);
            }

            if (! empty($data['referenced_vouchers'])) {
                $invoice->syncReferences($data['referenced_vouchers']);
            }

            return $invoice->load(['lines.item', 'customer', 'employee', 'references']);
        });
    }

    public function update(int $id, array $data): SalesInvoice
    {
        return DB::transaction(function () use ($id, $data) {
            // Lock first: the evidence snapshot and the accounting write must
            // serialize with any material document change.
            $invoice = $this->scopeToActorCompany(SalesInvoice::with('lines'))
                ->lockForUpdate()
                ->findOrFail($id);
            $companyId = (int) $invoice->company_id;
            $this->assertMutationCompany($data, $companyId);
            CommercialTenantReferenceGuard::assertSalesInvoice([
                'customer_id' => $data['customer_id'] ?? $invoice->customer_id,
                'employee_id' => $data['employee_id'] ?? $invoice->employee_id,
            ], $companyId);

            // The persisted date remains authoritative while the source is
            // frozen; replacing it in this request cannot evade the close.
            $this->periodGuard->assertOpen($invoice->company_id, $invoice->accounting_date ?? $invoice->invoice_date, 'sửa hóa đơn bán hàng');
            if (array_key_exists('accounting_date', $data)) {
                $this->periodGuard->assertOpen($invoice->company_id, $data['accounting_date'], 'sửa hóa đơn bán hàng');
            }

            $this->assertMutableSource($invoice, 'sửa');

            if (array_key_exists('lines', $data) && (! is_array($data['lines']) || $data['lines'] === [])) {
                throw ValidationException::withMessages([
                    'lines' => 'A sales invoice requires at least one detail line.',
                ]);
            }

            $subTotal = DecimalMoney::ZERO;
            $taxAmount = DecimalMoney::ZERO;
            $sumLineDiscounts = DecimalMoney::ZERO;
            $lines = $data['lines'] ?? null;

            CommercialDraftAccountEvidenceGate::assertUpdateLinesProvided('sales_invoice', $lines);

            if ($lines !== null) {
                foreach ($lines as $line) {
                    $amounts = $this->lineAmounts($line);
                    $subTotal = DecimalMoney::add($subTotal, $amounts['amount']);
                    $sumLineDiscounts = DecimalMoney::add($sumLineDiscounts, $amounts['discount']);
                    $taxAmount = DecimalMoney::add($taxAmount, $amounts['tax']);
                }
            } else {
                $subTotal = $this->persistedMoney($invoice, 'sub_total');
                $taxAmount = $this->persistedMoney($invoice, 'tax_amount');
                $sumLineDiscounts = $this->persistedMoney($invoice, 'discount_amount');
            }

            $discountAmount = array_key_exists('discount_amount', $data) && DecimalMoney::compare($this->money($data['discount_amount']), DecimalMoney::ZERO) > 0
                ? $this->money($data['discount_amount'])
                : (isset($data['lines']) ? $sumLineDiscounts : $this->persistedMoney($invoice, 'discount_amount'));
            $totalAmount = DecimalMoney::add(DecimalMoney::subtract($subTotal, $discountAmount), $taxAmount);
            $isExportSlip = isset($data['is_export_slip'])
                ? (bool) $data['is_export_slip']
                : (isset($data['is_include_delivery']) ? (bool) $data['is_include_delivery'] : $invoice->is_export_slip);
            $paymentMethod = $data['payment_method'] ?? $invoice->payment_method ?? 'unpaid';

            CommercialDraftAccountEvidenceGate::assertSatisfied('sales_invoice', $lines ?? $invoice->lines->map(
                static fn ($line): array => $line->toArray(),
            )->all(), [
                'is_export_slip' => $isExportSlip,
            ]);
            CommercialTenantReferenceGuard::assertSalesInvoice([
                'customer_id' => $data['customer_id'] ?? $invoice->customer_id,
                'employee_id' => $data['employee_id'] ?? $invoice->employee_id,
                'lines' => $lines ?? $invoice->lines->map(static fn ($line): array => $line->toArray())->all(),
            ], $companyId);

            $invoice->update([
                'customer_id' => $data['customer_id'] ?? $invoice->customer_id,
                'customer_name' => $data['customer_name'] ?? $invoice->customer_name,
                'customer_address' => $data['customer_address'] ?? $invoice->customer_address,
                'receiver_name' => $data['receiver_name'] ?? $invoice->receiver_name,
                'employee_id' => $data['employee_id'] ?? $invoice->employee_id,
                'employee_name' => $data['employee_name'] ?? $invoice->employee_name,
                'voucher_type' => $data['voucher_type'] ?? $invoice->voucher_type,
                'payment_method' => $paymentMethod,
                'invoice_number' => $data['invoice_number'] ?? $invoice->invoice_number,
                'invoice_symbol' => $data['invoice_symbol'] ?? $invoice->invoice_symbol,
                'invoice_code' => $data['invoice_code'] ?? $invoice->invoice_code,
                'delivery_voucher_number' => $data['delivery_voucher_number'] ?? $invoice->delivery_voucher_number,
                'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
                'accounting_date' => $data['accounting_date'] ?? $invoice->accounting_date,
                'due_date' => $data['due_date'] ?? $invoice->due_date,
                'sub_total' => $subTotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'status' => $invoice->status,
                'payment_status' => $invoice->payment_status,
                'description' => $data['description'] ?? $invoice->description,
                'attached_docs' => $data['attached_docs'] ?? $invoice->attached_docs,
                'currency' => $data['currency'] ?? $invoice->currency,
                'exchange_rate' => $data['exchange_rate'] ?? $invoice->getRawOriginal('exchange_rate') ?? 1,
                'functional_currency_code' => $data['functional_currency_code'] ?? $invoice->functional_currency_code,
                'functional_total_amount_raw' => $data['functional_total_amount_raw'] ?? $invoice->functional_total_amount_raw,
                'functional_total_amount_scale' => $data['functional_total_amount_scale'] ?? $invoice->functional_total_amount_scale,
                'original_total_amount_raw' => $data['original_total_amount_raw'] ?? $invoice->original_total_amount_raw,
                'original_total_amount_scale' => $data['original_total_amount_scale'] ?? $invoice->original_total_amount_scale,
                'is_export_slip' => $isExportSlip,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? $invoice->referenced_vouchers,
                'updated_by' => auth()->id(),
            ]);

            if ($lines !== null) {
                $invoice->lines()->delete();
                $defaultDebit = in_array($paymentMethod, ['cash', 'bank'])
                    ? ($paymentMethod === 'cash' ? '1111' : '1121')
                    : '131';

                foreach ($lines as $line) {
                    $amounts = $this->lineAmounts($line);

                    $invoice->lines()->create([
                        'item_id' => $line['item_id'] ?? null,
                        'description' => $line['description'] ?? $invoice->description,
                        'unit' => $line['unit'] ?? null,
                        'warehouse_id' => $line['warehouse_id'] ?? null,
                        'warehouse_code' => $line['warehouse_code'] ?? null,
                        'debit_account' => CommercialDraftAccountEvidenceGate::account($line, 'debit_account', 'sales_invoice', $defaultDebit),
                        'credit_account' => CommercialDraftAccountEvidenceGate::account($line, 'credit_account', 'sales_invoice', '5111'),
                        'quantity' => $amounts['quantity'],
                        'unit_price' => $amounts['unit_price'],
                        'amount' => $amounts['amount'],
                        'discount_rate' => $line['discount_rate'] ?? 0,
                        'discount_amount' => $amounts['discount'],
                        'tax_rate' => $line['tax_rate'] ?? 0,
                        'tax_amount' => $amounts['tax'],
                        'tax_account' => CommercialDraftAccountEvidenceGate::account($line, 'tax_account', 'sales_invoice', '33311'),
                        'inventory_account' => CommercialDraftAccountEvidenceGate::account($line, 'inventory_account', 'sales_invoice', '1561'),
                        'cogs_account' => CommercialDraftAccountEvidenceGate::account($line, 'cogs_account', 'sales_invoice', $line['cogs_debit_account'] ?? '632'),
                        'cogs_debit_account' => CommercialDraftAccountEvidenceGate::account($line, 'cogs_debit_account', 'sales_invoice', '632'),
                        'cogs_credit_account' => CommercialDraftAccountEvidenceGate::account($line, 'cogs_credit_account', 'sales_invoice', '1561'),
                        'cogs_price' => $amounts['cogs_price'],
                        'cogs_unit_price' => $amounts['cogs_price'],
                        'cogs_amount' => $amounts['cogs_amount'],
                        'vat_group' => $line['vat_group'] ?? null,
                        'order_id' => $line['order_id'] ?? null,
                        'contract_id' => $line['contract_id'] ?? null,
                    ]);
                }
            }

            if (isset($data['referenced_vouchers'])) {
                $invoice->syncReferences($data['referenced_vouchers']);
            }

            return $invoice->load(['lines.item', 'customer', 'employee', 'references']);
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $invoice = $this->scopeToActorCompany(SalesInvoice::query())->findOrFail($id);
            $this->periodGuard->assertOpen($invoice->company_id, $invoice->accounting_date ?? $invoice->invoice_date, 'xóa hóa đơn bán hàng');
            $this->assertMutableSource($invoice, 'xóa');
            $invoice->lines()->delete();
            $invoice->references()->delete();
            $invoice->delete();

            return true;
        });
    }

    private function assertMutableSource(SalesInvoice $invoice, string $operation): void
    {
        $status = strtolower(trim((string) $invoice->status));
        if ($invoice->is_posted || $status === 'posted') {
            throw new ConflictHttpException("Không thể {$operation} hóa đơn đã ghi sổ. Vui lòng bỏ ghi sổ trước khi {$operation}.");
        }

        if (in_array($status, ['voided', 'cancelled', 'canceled'], true)) {
            throw new ConflictHttpException("Không thể {$operation} hóa đơn đã hủy.");
        }
    }

    private function assertPostableSource(SalesInvoice $invoice): void
    {
        $status = strtolower(trim((string) $invoice->status));
        if (in_array($status, ['voided', 'cancelled', 'canceled'], true)) {
            throw new ConflictHttpException('Không thể ghi sổ hóa đơn đã hủy. Hãy nhân bản chứng từ để ghi sổ lại.');
        }

        if ($status === 'posted') {
            throw new ConflictHttpException('Hóa đơn đã ở trạng thái ghi sổ nhưng thiếu cờ ghi sổ hợp lệ.');
        }
    }

    public function post($id)
    {
        return DB::transaction(function () use ($id) {
            // Serialize source changes with authorization and the GL write.
            $invoice = $this->scopeToActorCompany(SalesInvoice::with('lines'))
                ->lockForUpdate()
                ->findOrFail($id);
            $authorization = $this->postingAuthorizer->authorize('sales.invoices.post', (int) $invoice->company_id);
            $this->periodGuard->assertOpen($invoice->company_id, $invoice->accounting_date ?? $invoice->invoice_date, 'ghi sổ hóa đơn bán hàng');
            if ($invoice->is_posted) {
                throw new \Exception('Invoice is already posted');
            }
            $this->assertPostableSource($invoice);
            $this->assertPostedForeignCurrencyEvidence($invoice);

            // A tenant-approved policy is the control-plane prerequisite for
            // this canonical sales posting path. Do not infer an accounting
            // contract from legacy account defaults when this gate is active.
            $policy = ($this->requiresAccountingPolicy() || $this->requiresAccountMappingPosting() || $this->requiresDimensionPosting())
                ? $this->accountingPolicyResolver->requireForVoucher(
                    (int) $invoice->company_id,
                    ($invoice->accounting_date ?? $invoice->invoice_date ?? now())->toDateString(),
                    SystemVoucherType::SALES_INVOICE,
                )
                : null;
            $accountMappings = $this->requiresAccountMappingPosting()
                ? $this->accountMappingGate->requireSatisfied($invoice, $policy)
                : null;
            $dimensions = $this->requiresDimensionPosting()
                ? $this->dimensionGate->requireSatisfied($invoice, $policy)
                : null;

            // Determine Debit Account (Payment method / status)
            $settlementContext = [
                'entry' => 'settlement_debit', 'payment_method' => strtolower((string) $invoice->payment_method),
                'payment_status' => strtolower((string) $invoice->payment_status), 'document_status' => strtolower((string) $invoice->status),
                'source_account_code' => SalesInvoiceAccountMappingPostingGate::legacySettlementAccount($invoice),
            ];
            $receivableAccount = $accountMappings === null
                ? SalesInvoiceAccountMappingPostingGate::legacySettlementAccount($invoice)
                : SalesInvoiceAccountMappingPostingGate::accountFor($accountMappings, 'settlement_debit', $settlementContext);

            $glLines = [];

            // 1. Revenue & Tax
            // Debit Receivable / Cash / Bank (Total Amount)
            $glLines[] = [
                'account_code' => $receivableAccount,
                'description' => $invoice->description ?? 'Doanh thu bán hàng',
                'debit_amount' => $this->persistedMoney($invoice, 'total_amount'),
                'credit_amount' => DecimalMoney::ZERO,
            ];

            // Multiple Revenue Accounts by Line Item (5111, 5112, 5113, etc.)
            $revenueByAccount = [];
            foreach ($invoice->lines as $line) {
                $revenueContext = ['entry' => 'revenue_credit', 'voucher_type' => (string) $invoice->voucher_type, 'source_account_code' => (string) ($line->credit_account ?: '5111')];
                $creditAccount = $accountMappings === null ? ($line->credit_account ?: '5111') : SalesInvoiceAccountMappingPostingGate::accountFor($accountMappings, 'revenue_credit', $revenueContext);
                $lineNetRevenue = DecimalMoney::subtract(
                    $this->persistedMoney($line, 'amount'),
                    $this->persistedMoney($line, 'discount_amount'),
                );
                if (! isset($revenueByAccount[$creditAccount])) {
                    $revenueByAccount[$creditAccount] = DecimalMoney::ZERO;
                }
                $revenueByAccount[$creditAccount] = DecimalMoney::add($revenueByAccount[$creditAccount], $lineNetRevenue);
            }

            foreach ($revenueByAccount as $acc => $amt) {
                if (DecimalMoney::compare($amt, DecimalMoney::ZERO) > 0) {
                    $glLines[] = [
                        'account_code' => $acc,
                        'description' => $invoice->description ?? 'Doanh thu bán hàng',
                        'debit_amount' => DecimalMoney::ZERO,
                        'credit_amount' => $amt,
                    ];
                }
            }

            // When owner mappings are enabled, preserve every source VAT
            // context rather than attaching the entire header amount to an
            // arbitrary line. A header/line mismatch has no approved lineage
            // and therefore fails before the journal is created.
            if ($accountMappings !== null) {
                $taxByAccount = [];
                $lineTaxTotal = DecimalMoney::ZERO;
                foreach ($invoice->lines as $line) {
                    $lineTax = $this->persistedMoney($line, 'tax_amount');
                    if (DecimalMoney::compare($lineTax, DecimalMoney::ZERO) <= 0) continue;
                    $taxContext = ['entry' => 'output_vat', 'source_account_code' => (string) ($line->tax_account ?: '33311')];
                    $taxAccount = SalesInvoiceAccountMappingPostingGate::accountFor($accountMappings, 'output_vat', $taxContext);
                    $taxByAccount[$taxAccount] = DecimalMoney::add($taxByAccount[$taxAccount] ?? DecimalMoney::ZERO, $lineTax);
                    $lineTaxTotal = DecimalMoney::add($lineTaxTotal, $lineTax);
                }
                if (DecimalMoney::compare($lineTaxTotal, $this->persistedMoney($invoice, 'tax_amount')) !== 0) {
                    throw new \LogicException('Sales invoice output VAT header does not match its mapped line evidence.');
                }
                foreach ($taxByAccount as $taxAccount => $taxAmount) {
                    $glLines[] = ['account_code' => $taxAccount, 'description' => 'Thuế GTGT đầu ra', 'debit_amount' => DecimalMoney::ZERO, 'credit_amount' => $taxAmount];
                }
            } elseif (DecimalMoney::compare($this->persistedMoney($invoice, 'tax_amount'), DecimalMoney::ZERO) > 0) {
                $glLines[] = ['account_code' => '33311', 'description' => 'Thuế GTGT đầu ra', 'debit_amount' => DecimalMoney::ZERO, 'credit_amount' => $this->persistedMoney($invoice, 'tax_amount')];
            }

            // 2. Cost of Goods Sold (Giá vốn) if kiêm phiếu xuất kho
            if ($invoice->is_export_slip) {
                foreach ($invoice->lines as $line) {
                    $cogsAmount = $this->persistedMoney($line, 'cogs_amount');
                    if (DecimalMoney::compare($cogsAmount, DecimalMoney::ZERO) === 0) {
                        $cogsAmount = $this->multiplyMoney(
                            $this->persistedMoney($line, 'quantity'),
                            $this->persistedMoney($line, 'cogs_price')
                        );
                    }

                    if (DecimalMoney::compare($cogsAmount, DecimalMoney::ZERO) > 0) {
                        $glLines[] = [
                            'account_code' => $accountMappings === null ? ($line->cogs_account ?: ($line->cogs_debit_account ?: '632')) : SalesInvoiceAccountMappingPostingGate::accountFor($accountMappings, 'cogs_debit', ['entry' => 'cogs_debit', 'source_account_code' => (string) ($line->cogs_account ?: ($line->cogs_debit_account ?: '632'))]),
                            'description' => 'Giá vốn hàng bán',
                            'debit_amount' => $cogsAmount,
                            'credit_amount' => DecimalMoney::ZERO,
                        ];
                        $glLines[] = [
                            'account_code' => $accountMappings === null ? ($line->inventory_account ?: ($line->cogs_credit_account ?: '1561')) : SalesInvoiceAccountMappingPostingGate::accountFor($accountMappings, 'inventory_credit', ['entry' => 'inventory_credit', 'source_account_code' => (string) ($line->inventory_account ?: ($line->cogs_credit_account ?: '1561'))]),
                            'description' => 'Xuất kho bán hàng',
                            'debit_amount' => DecimalMoney::ZERO,
                            'credit_amount' => $cogsAmount,
                        ];
                    }
                }
            }

            $nonZeroGlLines = array_values(array_filter($glLines, static function (array $line): bool {
                return DecimalMoney::compare($line['debit_amount'] ?? DecimalMoney::ZERO, DecimalMoney::ZERO) !== 0
                    || DecimalMoney::compare($line['credit_amount'] ?? DecimalMoney::ZERO, DecimalMoney::ZERO) !== 0;
            }));

            // A fully discounted/free sample can remain an auditable sales
            // document without manufacturing an all-zero journal entry.
            if ($nonZeroGlLines === []) {
                $before = $invoice->toArray();
                $invoice->journal_entry_id = null;
                $invoice->is_posted = true;
                $invoice->status = 'posted';
                $invoice->save();
                $this->auditService->record(
                    $invoice,
                    'sales_invoice.posted_without_journal',
                    $before,
                    $invoice->fresh()->toArray(),
                    null,
                    ['reason' => 'zero_net_monetary_posting', 'journal_entry_created' => false] + $this->policyAuditMetadata($policy),
                );
                $this->recordPostingAuthorizationLineage($invoice, $authorization, null);
                $this->recordAccountMappingLineage($invoice, $accountMappings, null);
                $this->recordDimensionLineage($invoice, $dimensions, null);

                return $invoice;
            }

            // Create Journal Entry
            $je = $this->journalEntryService->createPosted([
                'company_id' => $invoice->company_id,
                'voucher_type' => 'sales_invoice',
                'voucher_number' => 'GL-'.$invoice->invoice_number,
                'voucher_date' => $invoice->invoice_date,
                'posting_date' => $invoice->accounting_date ? $invoice->accounting_date->toDateString() : now()->toDateString(),
                'description' => $invoice->description ?? 'Hóa đơn bán hàng '.$invoice->invoice_number,
                'total_amount' => 0,
                'status' => 'posted',
                'source_document_type' => SalesInvoice::class,
                'source_document_id' => $invoice->id,
                'lines' => $nonZeroGlLines,
            ]);

            $invoice->journal_entry_id = $je->id;
            $invoice->is_posted = true;
            $invoice->status = 'posted';
            $invoice->save();

            if ($policy !== null) {
                $this->auditService->record(
                    $invoice,
                    'sales_invoice.policy_applied',
                    [],
                    [],
                    null,
                    ['journal_entry_id' => $je->id] + $this->policyAuditMetadata($policy),
                );
            }
            $this->recordPostingAuthorizationLineage($invoice, $authorization, $je->id);
            $this->recordAccountMappingLineage($invoice, $accountMappings, $je->id);
            $this->recordDimensionLineage($invoice, $dimensions, $je->id);

            return $invoice->load(['lines.item', 'customer', 'employee', 'references', 'journalEntry']);
        });
    }

    public function void($id, string $auditEvent = 'voided')
    {
        return DB::transaction(function () use ($id, $auditEvent) {
            $invoice = $this->scopeToActorCompany(SalesInvoice::query())->findOrFail($id);
            $this->periodGuard->assertOpen($invoice->company_id, $invoice->accounting_date ?? $invoice->invoice_date, 'bỏ ghi sổ/hủy hóa đơn bán hàng');
            if (! $invoice->is_posted) {
                throw new \Exception('Invoice is not posted yet');
            }
            $this->assertHasNoPostedSettlementAllocationsForTarget((int) $invoice->company_id, 'sales_invoice', (int) $invoice->id);
            $this->dependentDocumentGuard->assertNone((int) $invoice->company_id, SalesInvoice::class, (int) $invoice->id);

            // Void the linked journal entry
            if ($invoice->journal_entry_id) {
                $this->journalEntryService->void($invoice->journal_entry_id, (int) $invoice->company_id);
            }

            $invoice->is_posted = false;
            // Keep the lifecycle state aligned with the explicit action:
            // unpost returns the source document to an editable draft,
            // while a normal void remains visibly cancelled/voided.
            $invoice->status = $auditEvent === 'unposted' ? 'draft' : 'voided';
            CommercialSourceAuditContext::mark($invoice, $auditEvent);
            $invoice->save();

            return $invoice;
        });
    }

    /**
     * Unpost (alias for void): marks invoice as draft, reverses GL
     */
    public function unpost($id)
    {
        return $this->void($id, 'unposted');
    }

    /**
     * Duplicate: clone a sales invoice with a new auto number
     */
    public function duplicate($id): SalesInvoice
    {
        return DB::transaction(function () use ($id) {
            $original = $this->scopeToActorCompany(SalesInvoice::with('lines'))->findOrFail($id);
            $this->periodGuard->assertOpen($original->company_id, now()->toDateString(), 'nhân bản hóa đơn bán hàng');

            $newNumber = $this->generateNextCode($original->company_id);

            $newInvoice = $original->replicate();
            $newInvoice->invoice_number = $newNumber;
            $newInvoice->is_posted = false;
            $newInvoice->journal_entry_id = null;
            $newInvoice->invoice_date = now()->toDateString();
            $newInvoice->accounting_date = now()->toDateString();
            $newInvoice->status = 'draft';
            CommercialSourceAuditContext::mark($newInvoice, 'duplicated');
            $newInvoice->save();

            foreach ($original->lines as $line) {
                $newLine = $line->replicate();
                $newLine->sales_invoice_id = $newInvoice->id;
                $newLine->save();
            }

            if ($original->referenced_vouchers) {
                $newInvoice->syncReferences($original->referenced_vouchers);
            }

            return $newInvoice->load(['lines.item', 'customer', 'employee', 'references']);
        });
    }

    public function generateNextCode(int $companyId, ?string $prefix = 'HDBH'): string
    {
        $companyId = $this->requireCompanyId(['company_id' => $companyId]);
        $year = now()->format('Y');
        $codePrefix = ($prefix ?: 'HDBH').'-'.$year.'-';
        $latest = SalesInvoice::where('company_id', $companyId)
            ->where('invoice_number', 'like', $codePrefix.'%')
            ->orderBy('id', 'desc')
            ->value('invoice_number');

        if ($latest && preg_match('/'.preg_quote($codePrefix, '/').'(\d+)/', $latest, $m)) {
            $nextSeq = str_pad((int) $m[1] + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $count = SalesInvoice::where('company_id', $companyId)->count() + 1;
            $nextSeq = str_pad($count, 4, '0', STR_PAD_LEFT);
        }

        return $codePrefix.$nextSeq;
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

    private function assertPostedForeignCurrencyEvidence(SalesInvoice $invoice): void
    {
        if (strtoupper((string) $invoice->currency) === 'VND') return;
        foreach (['functional_currency_code', 'functional_total_amount_raw', 'functional_total_amount_scale', 'original_total_amount_raw', 'original_total_amount_scale'] as $field) {
            if ($invoice->getAttribute($field) === null) throw new \LogicException('Foreign-currency invoices require complete dual-currency evidence before posting.');
        }
        if (strtoupper((string) $invoice->functional_currency_code) !== 'VND') throw new \LogicException('Foreign-currency invoice functional currency must be VND.');
        $functional = $this->exactEvidenceAmount((string) $invoice->functional_total_amount_raw, (int) $invoice->functional_total_amount_scale);
        $this->exactEvidenceAmount((string) $invoice->original_total_amount_raw, (int) $invoice->original_total_amount_scale);
        if (DecimalMoney::compare($functional, $this->persistedMoney($invoice, 'total_amount')) !== 0) throw new \LogicException('Foreign-currency invoice functional evidence must equal the posted functional total.');
    }

    private function exactEvidenceAmount(string $raw, int $scale): string
    {
        if ($scale < 0 || $scale > 2 || ! preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $raw) || strlen(explode('.', $raw)[1] ?? '') > $scale) throw new \LogicException('Foreign-currency invoice evidence must use an exact supported amount and scale.');
        return DecimalMoney::normalize($scale === 0 ? $raw.'.00' : $raw);
    }

    /**
     * Legacy clients represented an immediate cash sale only by the explicit
     * debit account on every invoice line. Treat that as accounting evidence,
     * never as a lifecycle/status override supplied by the client.
     */
    private function paymentMethodFromLineEvidence(array $lines): string
    {
        $debitAccounts = array_values(array_unique(array_map(
            static fn (array $line): string => trim((string) ($line['debit_account'] ?? '')),
            $lines,
        )));

        return count($debitAccounts) === 1 && $debitAccounts[0] === '1111' ? 'cash'
            : (count($debitAccounts) === 1 && $debitAccounts[0] === '1121' ? 'bank' : 'unpaid');
    }

    private function money(mixed $value): string
    {
        return DecimalMoney::normalize($value);
    }

    /** @return array{quantity:string,unit_price:string,amount:string,discount:string,tax:string,cogs_price:string,cogs_amount:string} */
    private function lineAmounts(array $line): array
    {
        $quantity = $this->money($line['quantity'] ?? 1);
        $unitPrice = $this->money($line['unit_price'] ?? 0);
        $amount = array_key_exists('amount', $line)
            ? $this->money($line['amount'])
            : $this->multiplyMoney($quantity, $unitPrice);
        $discount = array_key_exists('discount_amount', $line)
            ? $this->money($line['discount_amount'])
            : $this->percentageOf($amount, $line['discount_rate'] ?? 0);
        $tax = array_key_exists('tax_amount', $line)
            ? $this->money($line['tax_amount'])
            : $this->percentageOf(DecimalMoney::subtract($amount, $discount), $line['tax_rate'] ?? 0);
        $cogsPrice = $this->money($line['cogs_price'] ?? $line['cogs_unit_price'] ?? 0);
        $cogsAmount = array_key_exists('cogs_amount', $line)
            ? $this->money($line['cogs_amount'])
            : $this->multiplyMoney($quantity, $cogsPrice);

        return compact('quantity', 'amount', 'discount', 'tax', 'cogsPrice', 'cogsAmount') + [
            'unit_price' => $unitPrice,
            'cogs_price' => $cogsPrice,
            'cogs_amount' => $cogsAmount,
        ];
    }

    private function persistedMoney(object $model, string $attribute): string
    {
        return $this->money($model->getRawOriginal($attribute) ?? DecimalMoney::ZERO);
    }

    private function multiplyMoney(string $left, string $right): string
    {
        return $this->multiplyMinorWithRounding($left, $right, 2);
    }

    private function percentageOf(string $amount, mixed $rate): string
    {
        return $this->multiplyMinorWithRounding($amount, $this->money($rate), 4);
    }

    private function multiplyMinorWithRounding(string $left, string $right, int $divisorDigits): string
    {
        $left = $this->money($left);
        $right = $this->money($right);
        $negative = str_starts_with($left, '-') !== str_starts_with($right, '-');
        $leftDigits = ltrim(str_replace(['-', '.'], '', $left), '0') ?: '0';
        $rightDigits = ltrim(str_replace(['-', '.'], '', $right), '0') ?: '0';
        $product = $this->multiplyUnsigned($leftDigits, $rightDigits);
        $product = str_pad($product, $divisorDigits + 1, '0', STR_PAD_LEFT);
        $quotient = substr($product, 0, -$divisorDigits);
        $remainder = substr($product, -$divisorDigits);
        if ($remainder >= '5'.str_repeat('0', $divisorDigits - 1)) {
            $quotient = $this->incrementUnsigned($quotient);
        }
        $quotient = ltrim($quotient, '0') ?: '0';
        $minor = str_pad($quotient, 3, '0', STR_PAD_LEFT);
        $result = substr($minor, 0, -2).'.'.substr($minor, -2);

        return $negative && $result !== DecimalMoney::ZERO ? '-'.$result : $result;
    }

    private function multiplyUnsigned(string $left, string $right): string
    {
        $result = '0';
        for ($index = strlen($right) - 1, $zeros = ''; $index >= 0; $index--, $zeros .= '0') {
            $carry = 0;
            $partial = '';
            $digit = ord($right[$index]) - 48;
            for ($inner = strlen($left) - 1; $inner >= 0; $inner--) {
                $value = (ord($left[$inner]) - 48) * $digit + $carry;
                $partial = (string) ($value % 10).$partial;
                $carry = intdiv($value, 10);
            }
            $partial = ($carry > 0 ? (string) $carry : '').$partial.$zeros;
            $result = $this->addUnsigned($result, $partial);
        }

        return ltrim($result, '0') ?: '0';
    }

    private function addUnsigned(string $left, string $right): string
    {
        $carry = 0;
        $result = '';
        for ($leftIndex = strlen($left) - 1, $rightIndex = strlen($right) - 1; $leftIndex >= 0 || $rightIndex >= 0 || $carry > 0;) {
            $sum = ($leftIndex >= 0 ? ord($left[$leftIndex--]) - 48 : 0)
                + ($rightIndex >= 0 ? ord($right[$rightIndex--]) - 48 : 0) + $carry;
            $result = (string) ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return $result;
    }

    private function incrementUnsigned(string $value): string
    {
        return $this->addUnsigned($value, '1');
    }

    private function requiresAccountingPolicy(): bool
    {
        return (bool) config('accounting.enforce_sales_invoice_posting_policy', true);
    }

    private function requiresAccountMappingPosting(): bool
    {
        return (bool) config('accounting.enforce_sales_invoice_posting_account_mappings', true);
    }

    private function requiresDimensionPosting(): bool
    {
        return (bool) config('accounting.enforce_sales_invoice_posting_dimensions', true);
    }

    /** @param array<string,mixed>|null $accountMappings */
    private function recordAccountMappingLineage(SalesInvoice $invoice, ?array $accountMappings, ?int $journalEntryId): void
    {
        if ($accountMappings === null) return;
        $this->auditService->record($invoice, 'sales_invoice.account_mappings_applied', [], [], null, [
            'journal_entry_id' => $journalEntryId, 'account_mapping_gate' => 'enforced', 'account_mappings' => $accountMappings,
        ]);
    }

    /** @param array<string,mixed>|null $dimensions */
    private function recordDimensionLineage(SalesInvoice $invoice, ?array $dimensions, ?int $journalEntryId): void
    {
        if ($dimensions === null) return;
        $this->auditService->record($invoice, 'sales_invoice.dimensions_applied', [], [], null, $dimensions + [
            'journal_entry_id' => $journalEntryId,
            'posting_dimension_gate' => 'enforced',
        ]);
    }

    /** @param array<string,mixed> $authorization */
    private function recordPostingAuthorizationLineage(SalesInvoice $invoice, array $authorization, ?int $journalEntryId): void
    {
        $this->auditService->record(
            $invoice,
            'sales_invoice.posting_authorization_applied',
            [],
            [],
            null,
            ['journal_entry_id' => $journalEntryId] + $authorization,
        );
    }

    /** @param array<string, mixed>|null $policy @return array<string, mixed> */
    private function policyAuditMetadata(?array $policy): array
    {
        if ($policy === null) return ['accounting_policy_gate' => 'not_enforced'];

        return [
            'accounting_policy_gate' => 'enforced',
            'accounting_policy' => [
                'policy_id' => $policy['policy_id'],
                'policy_key' => $policy['policy_key'],
                'policy_version' => $policy['policy_version'],
                'accounting_regime' => $policy['accounting_regime'],
                'effective_from' => $policy['effective_from'],
                'effective_to' => $policy['effective_to'],
                'contract_hash' => $policy['contract_hash'],
            ],
        ];
    }
}
