<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Models\ChartOfAccount;
use App\Models\InventoryMovementEvent;
use App\Models\PurchaseInvoice;
use App\Models\Warehouse;
use App\Services\Concerns\GuardsPostedSettlementAllocations;
use App\Support\CommercialSourceAuditContext;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the purchase-invoice posting lifecycle.
 *
 * The public PurchaseInvoiceService remains the compatibility façade used by
 * existing controllers. All journal, inventory and reversal side effects are
 * kept in one transaction here so callers cannot partially post a document.
 */
final class PurchaseInvoicePostingService
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
        private readonly AccountingPostingMode $postingMode,
        private readonly AccountingPeriodGuard $periodGuard,
        private readonly PostedDependentDocumentGuard $dependentDocumentGuard,
        private readonly InventoryValuationRunInvalidator $valuationRunInvalidator,
        private readonly PurchaseExpenseAllocationService $purchaseExpenseAllocationService,
    ) {
        $this->journalEntryService = $journalEntryService;
    }

    public function post(int|string $id): PurchaseInvoice
    {
        return DB::transaction(function () use ($id) {
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
            $directPosting = $this->postingMode->isDirectPostingEnabled();

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

            if ($invoice->payment_method === 'cash' || $invoice->status === 'Paid') {
                $payableAccount = '1111';
            } elseif ($invoice->payment_method === 'bank') {
                $payableAccount = '1121';
            } else {
                $lineCredit = (string) ($invoice->lines->first()?->credit_account ?? '');
                $payableAccount = ($lineCredit !== '' && $lineCredit !== '331')
                    ? $lineCredit
                    : $this->resolveDefaultPayableAccount((int) $invoice->company_id);
            }
            if ($accountMappings !== null) {
                $payableAccount = PurchaseInvoiceAccountMappingPostingGate::accountFor($accountMappings, 'settlement_credit', [
                    'entry' => 'settlement_credit',
                    'payment_method' => (string) $invoice->payment_method,
                    'payment_status' => (string) $invoice->status,
                    'source_account_code' => $payableAccount,
                ]);
            }

            $glLines[] = [
                'account_code' => $payableAccount,
                'description' => $invoice->description ?? 'Thanh toán tiền mua hàng',
                'debit_amount' => 0,
                'credit_amount' => $this->persistedMoney($invoice, 'total_amount'),
            ];

            foreach ($invoice->lines as $line) {
                $debitAcc = $line->debit_account ?: (in_array($invoice->voucher_type, ['domestic_direct', 'import_direct']) ? '642' : '1561');
                if ($accountMappings !== null) {
                    $debitAcc = PurchaseInvoiceAccountMappingPostingGate::accountFor($accountMappings, 'purchase_debit', [
                        'entry' => 'purchase_debit',
                        'voucher_type' => (string) $invoice->voucher_type,
                        'source_account_code' => (string) $debitAcc,
                    ]);
                }
                $netDebitAmount = DecimalMoney::subtract(
                    $this->persistedMoney($line, 'amount'),
                    $this->persistedMoney($line, 'discount_amount'),
                );

                $glLines[] = [
                    'account_code' => $debitAcc,
                    'description' => $line->description ?? 'Mua hàng',
                    'debit_amount' => $netDebitAmount,
                    'credit_amount' => 0,
                ];

                if (DecimalMoney::compare($this->persistedMoney($line, 'tax_amount'), DecimalMoney::ZERO) > 0) {
                    $glLines[] = [
                        'account_code' => $accountMappings === null ? ($line->tax_account ?: '1331') : PurchaseInvoiceAccountMappingPostingGate::accountFor($accountMappings, 'input_vat', [
                            'entry' => 'input_vat',
                            'source_account_code' => (string) ($line->tax_account ?: '1331'),
                        ]),
                        'description' => 'Thuế GTGT đầu vào',
                        'debit_amount' => $this->persistedMoney($line, 'tax_amount'),
                        'credit_amount' => 0,
                    ];
                }

                if (DecimalMoney::compare($this->persistedMoney($line, 'import_tax_amount'), DecimalMoney::ZERO) > 0) {
                    $glLines[] = [
                        'account_code' => $debitAcc,
                        'description' => 'Thuế nhập khẩu',
                        'debit_amount' => $this->persistedMoney($line, 'import_tax_amount'),
                        'credit_amount' => 0,
                    ];
                    $glLines[] = [
                        'account_code' => $accountMappings === null ? '3333' : PurchaseInvoiceAccountMappingPostingGate::accountFor($accountMappings, 'import_tax_payable', [
                            'entry' => 'import_tax_payable',
                            'source_account_code' => '3333',
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

            if ($nonZeroGlLines === []) {
                $before = $invoice->toArray();
                $invoice->journal_entry_id = null;
                $invoice->is_posted = true;
                $invoice->status = 'posted';
                $invoice->save();
                $this->recordInventoryMovements($invoice);

                $this->auditService->record(
                    $invoice,
                    'purchase_invoice.posted_without_journal',
                    $before,
                    $invoice->fresh()->toArray(),
                    null,
                    [
                        'reason' => 'zero_net_monetary_posting',
                        'journal_entry_created' => false,
                        'posting_mode' => $directPosting ? 'direct' : 'strict',
                    ] + $this->policyAuditMetadata($policy),
                );
                $this->recordPostingAuthorizationLineage($invoice, $authorization, null);
                $this->recordDimensionLineage($invoice, $dimensions, null);
                $this->recordAccountMappingLineage($invoice, $accountMappings, null);

                return $invoice;
            }

            $journalEntry = $this->journalEntryService->createPosted([
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

            $invoice->journal_entry_id = $journalEntry->id;
            $invoice->is_posted = true;
            $invoice->status = 'posted';
            $invoice->save();
            $this->recordInventoryMovements($invoice);

            if ($policy !== null && $this->requiresAccountingPolicy()) {
                $this->auditService->record(
                    $invoice,
                    'purchase_invoice.policy_applied',
                    [],
                    [],
                    null,
                    ['journal_entry_id' => $journalEntry->id] + $this->policyAuditMetadata($policy),
                );
            }
            $this->recordPostingAuthorizationLineage($invoice, $authorization, $journalEntry->id);
            $this->recordDimensionLineage($invoice, $dimensions, $journalEntry->id);
            $this->recordAccountMappingLineage($invoice, $accountMappings, $journalEntry->id);
            if ($directPosting) {
                $this->auditService->record(
                    $invoice,
                    'purchase_invoice.direct_posting_mode_applied',
                    [],
                    [],
                    null,
                    ['journal_entry_id' => $journalEntry->id, 'posting_mode' => 'direct'],
                );
            }

            return $invoice;
        });
    }

    public function void(int|string $id, string $auditEvent = 'voided'): PurchaseInvoice
    {
        return DB::transaction(function () use ($id, $auditEvent) {
            $invoice = $this->scopeToActorCompany(PurchaseInvoice::with('lines.item'))->findOrFail($id);
            $this->periodGuard->assertOpen($invoice->company_id, $invoice->accounting_date ?? $invoice->invoice_date, 'bỏ ghi sổ/hủy hóa đơn mua hàng');
            if (! $invoice->is_posted) {
                throw new \Exception('Invoice is not posted yet');
            }
            $this->assertHasNoPostedSettlementAllocationsForTarget((int) $invoice->company_id, 'purchase_invoice', (int) $invoice->id);
            $this->dependentDocumentGuard->assertNone((int) $invoice->company_id, PurchaseInvoice::class, (int) $invoice->id);

            if ($invoice->journal_entry_id) {
                $this->journalEntryService->void($invoice->journal_entry_id, (int) $invoice->company_id);
            }
            $this->purchaseExpenseAllocationService->removeForSource($invoice, $auditEvent);
            $this->reverseInventoryMovements($invoice);

            $invoice->is_posted = false;
            $invoice->status = 'draft';
            CommercialSourceAuditContext::mark($invoice, $auditEvent);
            $invoice->save();

            return $invoice;
        });
    }

    public function unpost(int|string $id): PurchaseInvoice
    {
        return $this->void($id, 'unposted');
    }

    private function recordInventoryMovements(PurchaseInvoice $invoice): void
    {
        foreach ($invoice->lines as $index => $line) {
            $item = $line->item;
            $itemType = strtolower(trim((string) ($item?->type ?? '')));
            if ($line->item_id === null || in_array($itemType, ['service', 'dịch vụ'], true)) {
                continue;
            }

            $warehouseId = (int) ($line->warehouse_id ?: $item?->warehouse_id ?: 0);
            if ($warehouseId < 1) {
                $companyWarehouses = Warehouse::query()
                    ->where('company_id', $invoice->company_id)
                    ->orderBy('id')
                    ->pluck('id');
                $warehouseId = ($companyWarehouses->count() === 1 || $this->postingMode->isDirectPostingEnabled())
                    ? (int) $companyWarehouses->first()
                    : 0;
                if ($warehouseId >= 1 && empty($line->warehouse_id)) {
                    $line->warehouse_id = $warehouseId;
                    $line->save();
                }
            }
            if ($warehouseId < 1) {
                throw ValidationException::withMessages([
                    "lines.{$index}.warehouse_id" => 'Hóa đơn mua hàng phải xác định kho nhập cho từng dòng hàng.',
                ]);
            }
            if (! Warehouse::query()->where('company_id', $invoice->company_id)->whereKey($warehouseId)->exists()) {
                throw ValidationException::withMessages([
                    "lines.{$index}.warehouse_id" => 'Kho nhập không thuộc doanh nghiệp của hóa đơn.',
                ]);
            }

            $quantity = $this->persistedMoney($line, 'quantity');
            if (DecimalMoney::compare($quantity, DecimalMoney::ZERO) <= 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}.quantity" => 'Số lượng nhập kho phải lớn hơn 0.',
                ]);
            }

            $stockValue = $this->persistedMoney($line, 'stock_value');
            if (DecimalMoney::compare($stockValue, DecimalMoney::ZERO) === 0) {
                $stockValue = DecimalMoney::add(
                    DecimalMoney::subtract($this->persistedMoney($line, 'amount'), $this->persistedMoney($line, 'discount_amount')),
                    $this->persistedMoney($line, 'purchase_expense'),
                );
            }

            $postingCycle = ((int) InventoryMovementEvent::query()
                ->where('company_id', $invoice->company_id)
                ->where('source_type', PurchaseInvoice::class)
                ->where('source_id', $invoice->id)
                ->where('source_line_id', $line->id)
                ->where('warehouse_id', $warehouseId)
                ->where('movement_type', 'purchase_invoice_in')
                ->max('posting_cycle')) + 1;

            InventoryMovementEvent::create([
                'company_id' => $invoice->company_id,
                'movement_date' => ($invoice->accounting_date ?? $invoice->invoice_date)->toDateString(),
                'warehouse_id' => $warehouseId,
                'item_id' => $line->item_id,
                'movement_type' => 'purchase_invoice_in',
                'posting_cycle' => $postingCycle,
                'quantity_delta' => $quantity,
                'amount_delta' => $stockValue,
                'source_type' => PurchaseInvoice::class,
                'source_id' => $invoice->id,
                'source_line_id' => $line->id,
            ]);
            $this->valuationRunInvalidator->invalidateForInventoryMovement(
                (int) $invoice->company_id,
                ($invoice->accounting_date ?? $invoice->invoice_date)->toDateString(),
                'purchase_invoice_posted',
                $warehouseId,
                (int) $line->item_id,
            );
        }
    }

    private function reverseInventoryMovements(PurchaseInvoice $invoice): void
    {
        $events = InventoryMovementEvent::query()
            ->where('company_id', $invoice->company_id)
            ->where('source_type', PurchaseInvoice::class)
            ->where('source_id', $invoice->id)
            ->where('movement_type', 'purchase_invoice_in')
            ->orderBy('id')
            ->get();

        foreach ($events as $event) {
            $alreadyReversed = InventoryMovementEvent::query()
                ->where('company_id', $invoice->company_id)
                ->where('source_type', PurchaseInvoice::class)
                ->where('source_id', $invoice->id)
                ->where('source_line_id', $event->source_line_id)
                ->where('warehouse_id', $event->warehouse_id)
                ->where('movement_type', 'purchase_invoice_in_reversal')
                ->where('posting_cycle', $event->posting_cycle)
                ->exists();
            if ($alreadyReversed) {
                continue;
            }

            InventoryMovementEvent::create([
                'company_id' => $invoice->company_id,
                'movement_date' => ($invoice->accounting_date ?? $invoice->invoice_date)->toDateString(),
                'warehouse_id' => $event->warehouse_id,
                'item_id' => $event->item_id,
                'movement_type' => 'purchase_invoice_in_reversal',
                'posting_cycle' => $event->posting_cycle,
                'quantity_delta' => DecimalMoney::negate((string) $event->quantity_delta),
                'amount_delta' => DecimalMoney::negate((string) $event->amount_delta),
                'source_type' => PurchaseInvoice::class,
                'source_id' => $invoice->id,
                'source_line_id' => $event->source_line_id,
            ]);
            $this->valuationRunInvalidator->invalidateForInventoryMovement(
                (int) $invoice->company_id,
                ($invoice->accounting_date ?? $invoice->invoice_date)->toDateString(),
                'purchase_invoice_reversed',
                (int) $event->warehouse_id,
                (int) $event->item_id,
            );
        }
    }

    private function assertPostableSource(PurchaseInvoice $invoice): void
    {
        $status = strtolower(trim((string) $invoice->status));
        if (in_array($status, ['voided', 'cancelled', 'canceled'], true)) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Không thể ghi sổ hóa đơn đã hủy. Hãy nhân bản chứng từ để ghi sổ lại.');
        }
        if ($status === 'posted') {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Hóa đơn đã ở trạng thái ghi sổ nhưng thiếu cờ ghi sổ hợp lệ.');
        }
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

    private function money(mixed $value): string
    {
        return DecimalMoney::normalize($value);
    }

    private function persistedMoney(object $model, string $attribute): string
    {
        return $this->money($model->getRawOriginal($attribute) ?? DecimalMoney::ZERO);
    }

    private function assertPostedForeignCurrencyEvidence(PurchaseInvoice $invoice): void
    {
        if (strtoupper((string) $invoice->currency) === 'VND') {
            return;
        }
        foreach (['functional_currency_code', 'functional_total_amount_raw', 'functional_total_amount_scale', 'original_total_amount_raw', 'original_total_amount_scale'] as $field) {
            if ($invoice->getAttribute($field) === null) {
                throw new \LogicException('Foreign-currency invoices require complete dual-currency evidence before posting.');
            }
        }
        if (strtoupper((string) $invoice->functional_currency_code) !== 'VND') {
            throw new \LogicException('Foreign-currency invoice functional currency must be VND.');
        }
        $functional = $this->exactEvidenceAmount((string) $invoice->functional_total_amount_raw, (int) $invoice->functional_total_amount_scale);
        $this->exactEvidenceAmount((string) $invoice->original_total_amount_raw, (int) $invoice->original_total_amount_scale);
        if (DecimalMoney::compare($functional, $this->persistedMoney($invoice, 'total_amount')) !== 0) {
            throw new \LogicException('Foreign-currency invoice functional evidence must equal the posted functional total.');
        }
    }

    private function requiresAccountingPolicy(): bool
    {
        return ! $this->postingMode->isDirectPostingEnabled()
            && (bool) config('accounting.enforce_purchase_invoice_posting_policy', true);
    }

    private function requiresDimensionPosting(): bool
    {
        return ! $this->postingMode->isDirectPostingEnabled()
            && (bool) config('accounting.enforce_purchase_invoice_posting_dimensions', true);
    }

    private function requiresAccountMappingPosting(): bool
    {
        return ! $this->postingMode->isDirectPostingEnabled()
            && (bool) config('accounting.enforce_purchase_invoice_posting_account_mappings', true);
    }

    /** @param array<string,mixed>|null $accountMappings */
    private function recordAccountMappingLineage(PurchaseInvoice $invoice, ?array $accountMappings, ?int $journalEntryId): void
    {
        if ($accountMappings === null) {
            return;
        }
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

    /** @param array<string,mixed>|null $policy @return array<string,mixed> */
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

    private function resolveDefaultPayableAccount(int $companyId): string
    {
        $has3311 = ChartOfAccount::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('code', '3311')
            ->where('is_active', true)
            ->where('is_parent', false)
            ->exists();

        return $has3311 ? '3311' : '331';
    }

    private function exactEvidenceAmount(string $raw, int $scale): string
    {
        if ($scale < 0 || $scale > 2 || ! preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $raw) || strlen(explode('.', $raw)[1] ?? '') > $scale) {
            throw new \LogicException('Foreign-currency invoice evidence must use an exact supported amount and scale.');
        }

        return DecimalMoney::normalize($scale === 0 ? $raw.'.00' : $raw);
    }
}
