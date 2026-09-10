<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Models\PurchaseInvoice;
use App\Services\Concerns\GuardsPostedSettlementAllocations;
use App\Support\CommercialSourceAuditContext;
use App\Support\CommercialTenantReferenceGuard;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PurchaseInvoiceService
{
    use GuardsPostedSettlementAllocations;

    protected JournalEntryService $journalEntryService;

    public function __construct(
        JournalEntryService $journalEntryService,
        private readonly AuditService $auditService,
        private readonly AccountingPolicyResolver $accountingPolicyResolver,
        private readonly CoreDocumentPostingAuthorizer $postingAuthorizer,
        private readonly PurchaseInvoiceDimensionPostingGate $dimensionGate,
        private readonly PurchaseInvoiceAccountMappingPostingGate $accountMappingGate,
        private readonly AccountingPeriodGuard $periodGuard,
        private readonly PostedDependentDocumentGuard $dependentDocumentGuard,
    ) {
        $this->journalEntryService = $journalEntryService;
    }

    public function getAll(?int $companyId = null)
    {
        $actorCompanyId = auth()->user()?->company_id;
        if ($actorCompanyId !== null) {
            if ($companyId !== null && (int) $companyId !== (int) $actorCompanyId) {
                throw ValidationException::withMessages([
                    'company_id' => 'The requested company does not belong to the authenticated user.',
                ]);
            }

            $companyId = (int) $actorCompanyId;
        }

        if ($companyId === null) {
            throw ValidationException::withMessages([
                'company_id' => 'A company context is required to list purchase invoices.',
            ]);
        }

        $query = PurchaseInvoice::query()->with(['supplier', 'lines', 'employee']);
        $query->where('company_id', $companyId);

        return $query
            ->orderBy('invoice_date', 'desc')
            ->get();
    }

    public function getById($id): PurchaseInvoice
    {
        $query = $this->scopeToActorCompany(
            PurchaseInvoice::with(['lines', 'supplier', 'employee', 'references', 'referencedBy'])
        );

        return $query->findOrFail($id);
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

            $totalPurchaseExpense = $this->maxMoney($this->money($data['purchase_expense'] ?? DecimalMoney::ZERO), $sumExpenses);
            if (DecimalMoney::compare($totalStockValue, DecimalMoney::ZERO) === 0) {
                $totalStockValue = DecimalMoney::add(DecimalMoney::subtract($subTotal, $sumLineDiscounts), $totalPurchaseExpense);
            }

            $discountAmount = array_key_exists('discount_amount', $data) && DecimalMoney::compare($this->money($data['discount_amount']), DecimalMoney::ZERO) > 0
                ? $this->money($data['discount_amount'])
                : $sumLineDiscounts;
            $totalAmount = DecimalMoney::add(DecimalMoney::subtract($subTotal, $discountAmount), $taxAmount);

            CommercialDraftAccountEvidenceGate::assertSatisfied('purchase_invoice', $data['lines']);

            $voucherType = $data['voucher_type'] ?? 'domestic_inward';
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
                'deliverer_name' => $data['deliverer_name'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'employee_name' => $data['employee_name'] ?? null,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'accounting_date' => $accountingDate,
                'due_date' => $data['due_date'] ?? now()->addDays(30)->toDateString(),
                'voucher_type' => $voucherType,
                'payment_method' => $data['payment_method'] ?? 'unpaid',
                'invoice_symbol' => $data['invoice_symbol'] ?? null,
                'invoice_code' => $data['invoice_code'] ?? null,
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
                $defaultDebit = in_array($voucherType, ['domestic_direct', 'import_direct']) ? '642' : '1561';

                $invoice->lines()->create([
                    'item_id' => $line['item_id'] ?? null,
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
                    'stock_value' => $amounts['stock_value'],
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
                ]);
            }

            if (isset($data['referenced_vouchers'])) {
                $invoice->syncReferences($data['referenced_vouchers']);
            }

            return $invoice->load(['lines', 'supplier', 'employee', 'references']);
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

            $totalPurchaseExpense = isset($data['purchase_expense'])
                ? $this->money($data['purchase_expense'])
                : $this->maxMoney($this->persistedMoney($invoice, 'purchase_expense'), $sumExpenses);

            if (DecimalMoney::compare($totalStockValue, DecimalMoney::ZERO) === 0) {
                $totalStockValue = DecimalMoney::add(DecimalMoney::subtract($subTotal, $sumLineDiscounts), $totalPurchaseExpense);
            }

            $discountAmount = array_key_exists('discount_amount', $data) && DecimalMoney::compare($this->money($data['discount_amount']), DecimalMoney::ZERO) > 0
                ? $this->money($data['discount_amount'])
                : (isset($data['lines']) ? $sumLineDiscounts : $this->persistedMoney($invoice, 'discount_amount'));
            $totalAmount = DecimalMoney::add(DecimalMoney::subtract($subTotal, $discountAmount), $taxAmount);
            $voucherType = $data['voucher_type'] ?? $invoice->voucher_type ?? 'domestic_inward';

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
                'supplier_name' => $data['supplier_name'] ?? $invoice->supplier_name,
                'supplier_address' => $data['supplier_address'] ?? $invoice->supplier_address,
                'deliverer_name' => $data['deliverer_name'] ?? $invoice->deliverer_name,
                'employee_id' => $data['employee_id'] ?? $invoice->employee_id,
                'employee_name' => $data['employee_name'] ?? $invoice->employee_name,
                'invoice_number' => $data['invoice_number'] ?? $invoice->invoice_number,
                'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
                'accounting_date' => $data['accounting_date'] ?? $data['invoice_date'] ?? $invoice->accounting_date,
                'due_date' => $data['due_date'] ?? $invoice->due_date,
                'voucher_type' => $voucherType,
                'payment_method' => $data['payment_method'] ?? $invoice->payment_method,
                'invoice_symbol' => $data['invoice_symbol'] ?? $invoice->invoice_symbol,
                'invoice_code' => $data['invoice_code'] ?? $invoice->invoice_code,
                'description' => $data['description'] ?? $invoice->description,
                'attached_docs' => $data['attached_docs'] ?? $invoice->attached_docs,
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
                $defaultDebit = in_array($voucherType, ['domestic_direct', 'import_direct']) ? '642' : '1561';

                foreach ($data['lines'] as $line) {
                    $amounts = $this->lineAmounts($line);

                    $invoice->lines()->create([
                        'item_id' => $line['item_id'] ?? null,
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
                        'stock_value' => $amounts['stock_value'],
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
                    ]);
                }
            }

            if (isset($data['referenced_vouchers'])) {
                $invoice->syncReferences($data['referenced_vouchers']);
            }

            return $invoice->load(['lines', 'supplier', 'employee', 'references']);
        });
    }

    public function post($id): PurchaseInvoice
    {
        return DB::transaction(function () use ($id) {
            // Lock the source before authorization and journal creation so a
            // concurrent material edit cannot race with the posting write.
            $invoice = $this->scopeToActorCompany(PurchaseInvoice::with('lines'))
                ->lockForUpdate()
                ->findOrFail($id);
            $authorization = $this->postingAuthorizer->authorize('purchase.invoices.post', (int) $invoice->company_id);
            $this->periodGuard->assertOpen($invoice->company_id, $invoice->accounting_date ?? $invoice->invoice_date, 'ghi sổ hóa đơn mua hàng');
            if ($invoice->is_posted) {
                throw new \Exception('Invoice is already posted');
            }
            $this->assertPostableSource($invoice);
            $this->assertPostedForeignCurrencyEvidence($invoice);

            // The policy contract is a mandatory precondition for this
            // canonical high-risk posting path when its controlled rollout is
            // enabled. It intentionally does not derive account codes: those
            // mappings still require an approved posting-rule implementation.
            $policy = ($this->requiresAccountingPolicy() || $this->requiresDimensionPosting() || $this->requiresAccountMappingPosting())
                ? $this->accountingPolicyResolver->requireForVoucher(
                    (int) $invoice->company_id,
                    ($invoice->accounting_date ?? $invoice->invoice_date ?? now())->toDateString(),
                    SystemVoucherType::PURCHASE_INVOICE,
                )
                : null;
            $dimensions = $this->requiresDimensionPosting()
                ? $this->dimensionGate->requireSatisfied($invoice, $policy)
                : null;
            $accountMappings = $this->requiresAccountMappingPosting()
                ? $this->accountMappingGate->requireSatisfied($invoice, $policy)
                : null;
            $glLines = [];

            // Payable account based on payment method / status
            $payableAccount = '331';
            if ($invoice->payment_method === 'cash' || $invoice->status === 'Paid') {
                $payableAccount = '1111';
            } elseif ($invoice->payment_method === 'bank') {
                $payableAccount = '1121';
            }
            if ($accountMappings !== null) {
                $payableAccount = PurchaseInvoiceAccountMappingPostingGate::accountFor($accountMappings, 'settlement_credit', [
                    'entry' => 'settlement_credit', 'payment_method' => (string) $invoice->payment_method,
                    'payment_status' => (string) $invoice->status, 'source_account_code' => $payableAccount,
                ]);
            }

            // Credit Total Amount to Payable / Payment account
            $glLines[] = [
                'account_code' => $payableAccount,
                'description' => $invoice->description ?? 'Thanh toán tiền mua hàng',
                'debit_amount' => 0,
                'credit_amount' => $this->persistedMoney($invoice, 'total_amount'),
            ];

            // Debit Goods / Expense and Tax
            foreach ($invoice->lines as $line) {
                $debitAcc = $line->debit_account ?: (in_array($invoice->voucher_type, ['domestic_direct', 'import_direct']) ? '642' : '1561');
                if ($accountMappings !== null) {
                    $debitAcc = PurchaseInvoiceAccountMappingPostingGate::accountFor($accountMappings, 'purchase_debit', [
                        'entry' => 'purchase_debit', 'voucher_type' => (string) $invoice->voucher_type,
                        'source_account_code' => (string) $debitAcc,
                    ]);
                }
                $netDebitAmount = DecimalMoney::subtract(
                    $this->persistedMoney($line, 'amount'),
                    $this->persistedMoney($line, 'discount_amount')
                );

                $glLines[] = [
                    'account_code' => $debitAcc,
                    'description' => $line->description ?? 'Mua hàng',
                    'debit_amount' => $netDebitAmount,
                    'credit_amount' => 0,
                ];

                // Debit VAT Tax
                if (DecimalMoney::compare($this->persistedMoney($line, 'tax_amount'), DecimalMoney::ZERO) > 0) {
                    $glLines[] = [
                    'account_code' => $accountMappings === null ? ($line->tax_account ?: '1331') : PurchaseInvoiceAccountMappingPostingGate::accountFor($accountMappings, 'input_vat', [
                        'entry' => 'input_vat', 'source_account_code' => (string) ($line->tax_account ?: '1331'),
                    ]),
                        'description' => 'Thuế GTGT đầu vào',
                        'debit_amount' => $this->persistedMoney($line, 'tax_amount'),
                        'credit_amount' => 0,
                    ];
                }

                // Import Tax (if any)
                if (DecimalMoney::compare($this->persistedMoney($line, 'import_tax_amount'), DecimalMoney::ZERO) > 0) {
                    $glLines[] = [
                        'account_code' => $debitAcc,
                        'description' => 'Thuế nhập khẩu',
                        'debit_amount' => $this->persistedMoney($line, 'import_tax_amount'),
                        'credit_amount' => 0,
                    ];
                    $glLines[] = [
                        'account_code' => $accountMappings === null ? '3333' : PurchaseInvoiceAccountMappingPostingGate::accountFor($accountMappings, 'import_tax_payable', [
                            'entry' => 'import_tax_payable', 'source_account_code' => '3333',
                        ]),
                        'description' => 'Thuế nhập khẩu phải nộp',
                        'debit_amount' => 0,
                        'credit_amount' => $this->persistedMoney($line, 'import_tax_amount'),
                    ];
                }
            }

            $nonZeroGlLines = array_values(array_filter($glLines, static function (array $line): bool {
                return DecimalMoney::compare($line['debit_amount'] ?? DecimalMoney::ZERO, DecimalMoney::ZERO) !== 0
                    || DecimalMoney::compare($line['credit_amount'] ?? DecimalMoney::ZERO, DecimalMoney::ZERO) !== 0;
            }));

            // A fully discounted/free-sample purchase can be a legitimate source
            // document with no monetary GL impact. Do not manufacture a zero JE.
            if ($nonZeroGlLines === []) {
                $before = $invoice->toArray();
                $invoice->journal_entry_id = null;
                $invoice->is_posted = true;
                $invoice->status = 'posted';
                $invoice->save();

                $this->auditService->record(
                    $invoice,
                    'purchase_invoice.posted_without_journal',
                    $before,
                    $invoice->fresh()->toArray(),
                    null,
                    [
                        'reason' => 'zero_net_monetary_posting',
                        'journal_entry_created' => false,
                    ] + $this->policyAuditMetadata($policy)
                );

                $this->recordPostingAuthorizationLineage($invoice, $authorization, null);
                $this->recordDimensionLineage($invoice, $dimensions, null);
                $this->recordAccountMappingLineage($invoice, $accountMappings, null);

                return $invoice;
            }

            // Create Journal Entry
            $je = $this->journalEntryService->createPosted([
                'company_id' => $invoice->company_id,
                'voucher_type' => 'purchase_invoice',
                'voucher_number' => 'GL-PU-'.$invoice->invoice_number,
                'voucher_date' => $invoice->invoice_date,
                'posting_date' => $invoice->accounting_date ?? now()->toDateString(),
                'description' => $invoice->description,
                'total_amount' => 0,
                'status' => 'posted',
                'source_document_type' => PurchaseInvoice::class,
                'source_document_id' => $invoice->id,
                'lines' => $nonZeroGlLines,
            ]);

            $invoice->journal_entry_id = $je->id;
            $invoice->is_posted = true;
            $invoice->status = 'posted';
            $invoice->save();

            // The observer already records the source's lifecycle transition.
            // Store policy lineage as its own immutable audit event instead of
            // duplicating that event or overloading attached-document fields.
            if ($policy !== null && $this->requiresAccountingPolicy()) {
                $this->auditService->record(
                    $invoice,
                    'purchase_invoice.policy_applied',
                    [],
                    [],
                    null,
                    ['journal_entry_id' => $je->id] + $this->policyAuditMetadata($policy),
                );
            }
            $this->recordPostingAuthorizationLineage($invoice, $authorization, $je->id);
            $this->recordDimensionLineage($invoice, $dimensions, $je->id);
            $this->recordAccountMappingLineage($invoice, $accountMappings, $je->id);

            return $invoice;
        });
    }

    public function void($id, string $auditEvent = 'voided'): PurchaseInvoice
    {
        return DB::transaction(function () use ($id, $auditEvent) {
            $invoice = $this->scopeToActorCompany(PurchaseInvoice::query())->findOrFail($id);
            $this->periodGuard->assertOpen($invoice->company_id, $invoice->accounting_date ?? $invoice->invoice_date, 'bỏ ghi sổ/hủy hóa đơn mua hàng');
            if (! $invoice->is_posted) {
                throw new \Exception('Invoice is not posted yet');
            }
            $this->assertHasNoPostedSettlementAllocationsForTarget((int) $invoice->company_id, 'purchase_invoice', (int) $invoice->id);
            $this->dependentDocumentGuard->assertNone((int) $invoice->company_id, PurchaseInvoice::class, (int) $invoice->id);

            if ($invoice->journal_entry_id) {
                $this->journalEntryService->void($invoice->journal_entry_id, (int) $invoice->company_id);
            }

            $invoice->is_posted = false;
            $invoice->status = 'draft';
            CommercialSourceAuditContext::mark($invoice, $auditEvent);
            $invoice->save();

            return $invoice;
        });
    }

    public function unpost($id): PurchaseInvoice
    {
        return $this->void($id, 'unposted');
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

    private function assertPostableSource(PurchaseInvoice $invoice): void
    {
        $status = strtolower(trim((string) $invoice->status));
        if (in_array($status, ['voided', 'cancelled', 'canceled'], true)) {
            throw new ConflictHttpException('Không thể ghi sổ hóa đơn đã hủy. Hãy nhân bản chứng từ để ghi sổ lại.');
        }

        if ($status === 'posted') {
            throw new ConflictHttpException('Hóa đơn đã ở trạng thái ghi sổ nhưng thiếu cờ ghi sổ hợp lệ.');
        }
    }

    public function generateNextCode(int $companyId): string
    {
        $companyId = $this->requireCompanyId(['company_id' => $companyId]);
        $year = now()->format('Y');
        $prefix = 'HDMH-'.$year.'-';
        $latest = PurchaseInvoice::where('company_id', $companyId)
            ->where('invoice_number', 'like', $prefix.'%')
            ->orderBy('id', 'desc')
            ->value('invoice_number');

        if ($latest && preg_match('/'.preg_quote($prefix, '/').'(\d+)/', $latest, $m)) {
            $nextSeq = str_pad((int) $m[1] + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $count = PurchaseInvoice::where('company_id', $companyId)->count() + 1;
            $nextSeq = str_pad($count, 4, '0', STR_PAD_LEFT);
        }

        return $prefix.$nextSeq;
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
        $quantity = $this->money($line['quantity'] ?? 1);
        $unitPrice = $this->money($line['unit_price'] ?? 0);
        $amount = array_key_exists('amount', $line)
            ? $this->money($line['amount'])
            : $this->multiplyMoney($quantity, $unitPrice);
        $discount = array_key_exists('discount_amount', $line)
            ? $this->money($line['discount_amount'])
            : $this->percentageOf($amount, $line['discount_rate'] ?? 0);
        $net = DecimalMoney::subtract($amount, $discount);
        $tax = array_key_exists('tax_amount', $line)
            ? $this->money($line['tax_amount'])
            : $this->percentageOf($net, $line['tax_rate'] ?? 0);
        $expense = $this->money($line['purchase_expense'] ?? 0);
        $stockValue = array_key_exists('stock_value', $line)
            ? $this->money($line['stock_value'])
            : DecimalMoney::add($net, $expense);

        return compact('quantity', 'unitPrice', 'amount', 'discount', 'tax', 'expense', 'stockValue') + [
            'unit_price' => $unitPrice,
            'stock_value' => $stockValue,
        ];
    }

    private function persistedMoney(object $model, string $attribute): string
    {
        return $this->money($model->getRawOriginal($attribute) ?? DecimalMoney::ZERO);
    }

    private function maxMoney(string $left, string $right): string
    {
        return DecimalMoney::compare($left, $right) >= 0 ? $left : $right;
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

    private function assertPostedForeignCurrencyEvidence(PurchaseInvoice $invoice): void
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

    private function requiresAccountingPolicy(): bool
    {
        return (bool) config('accounting.enforce_purchase_invoice_posting_policy', true);
    }

    private function requiresDimensionPosting(): bool
    {
        return (bool) config('accounting.enforce_purchase_invoice_posting_dimensions', true);
    }

    private function requiresAccountMappingPosting(): bool
    {
        return (bool) config('accounting.enforce_purchase_invoice_posting_account_mappings', true);
    }

    /** @param array<string,mixed>|null $accountMappings */
    private function recordAccountMappingLineage(PurchaseInvoice $invoice, ?array $accountMappings, ?int $journalEntryId): void
    {
        if ($accountMappings === null) return;

        $this->auditService->record(
            $invoice,
            'purchase_invoice.account_mappings_applied',
            [],
            [],
            null,
            ['journal_entry_id' => $journalEntryId, 'account_mapping_gate' => 'enforced', 'account_mappings' => $accountMappings],
        );
    }

    /** @param array<string,mixed>|null $dimensions */
    private function recordDimensionLineage(PurchaseInvoice $invoice, ?array $dimensions, ?int $journalEntryId): void
    {
        if ($dimensions === null) {
            return;
        }

        $this->auditService->record(
            $invoice,
            'purchase_invoice.dimensions_applied',
            [],
            [],
            null,
            ['journal_entry_id' => $journalEntryId, 'dimension_gate' => 'enforced', 'dimensions' => $dimensions],
        );
    }

    /** @param array<string,mixed> $authorization */
    private function recordPostingAuthorizationLineage(PurchaseInvoice $invoice, array $authorization, ?int $journalEntryId): void
    {
        $this->auditService->record(
            $invoice,
            'purchase_invoice.posting_authorization_applied',
            [],
            [],
            null,
            ['journal_entry_id' => $journalEntryId] + $authorization,
        );
    }

    /** @param array<string, mixed>|null $policy @return array<string, mixed> */
    private function policyAuditMetadata(?array $policy): array
    {
        if ($policy === null) {
            return ['accounting_policy_gate' => 'not_enforced'];
        }

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

    private function exactEvidenceAmount(string $raw, int $scale): string
    {
        if ($scale < 0 || $scale > 2 || ! preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $raw) || strlen(explode('.', $raw)[1] ?? '') > $scale) throw new \LogicException('Foreign-currency invoice evidence must use an exact supported amount and scale.');
        return DecimalMoney::normalize($scale === 0 ? $raw.'.00' : $raw);
    }
}
