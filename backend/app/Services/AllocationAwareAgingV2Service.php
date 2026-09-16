<?php

namespace App\Services;

use App\Exceptions\ReportDefinitionUnavailableException;
use App\Models\ManagementReportDefinition;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Executes the deliberately narrow, allocation-line based AP/AR aging
 * definition.  This is not an adapter for the legacy status-based reports:
 * an invoice remains open only to the extent that posted settlement lines,
 * dated on or before the requested cutoff, have not cleared it.
 *
 * The class accepts one source/calculation contract only.  A signed and
 * published definition is still not executable when it describes a different
 * source, date basis, or set of buckets; silently interpreting an unknown
 * contract would make the report accounting evidence unreliable.
 */
final class AllocationAwareAgingV2Service
{
    public function __construct(
        private readonly ManagementReportDefinitionContractHasher $hasher,
        private readonly ApArAgingV2ReadinessService $readiness,
    ) {}

    /** @var list<array{key:string,min_days:int|null,max_days:int|null}> */
    private const BUCKETS = [
        ['key' => 'current', 'min_days' => null, 'max_days' => 0],
        ['key' => 'days_1_30', 'min_days' => 1, 'max_days' => 30],
        ['key' => 'days_31_60', 'min_days' => 31, 'max_days' => 60],
        ['key' => 'days_over_60', 'min_days' => 61, 'max_days' => null],
    ];

    /**
     * @return list<array<string, int|string>>
     *
     * @throws ReportDefinitionUnavailableException
     */
    public function accountsPayable(
        ManagementReportDefinition $definition,
        int $companyId,
        CarbonImmutable|string $asOfDate,
    ): array {
        return $this->execute($definition, $companyId, $asOfDate, 'ap');
    }

    /**
     * @return list<array<string, int|string>>
     *
     * @throws ReportDefinitionUnavailableException
     */
    public function accountsReceivable(
        ManagementReportDefinition $definition,
        int $companyId,
        CarbonImmutable|string $asOfDate,
    ): array {
        return $this->execute($definition, $companyId, $asOfDate, 'ar');
    }

    /**
     * Canonical source contract that an owner may sign and publish for this
     * implementation. It describes the canonical settlement evidence table,
     * never the legacy payment-line `invoice_id` columns: AP and AR invoice
     * IDs are independent sequences and can collide.
     */
    public static function supportedSourceContract(string $ledger): array
    {
        $isAp = $ledger === 'ap';

        return [
            'schema' => 'allocation-line-aging-source.v2',
            'invoice' => [
                'table' => $isAp ? 'purchase_invoices' : 'sales_invoices',
                'company_key' => 'company_id',
                'posted_field' => 'is_posted',
                'status_field' => 'status',
                'excluded_statuses' => ['voided', 'cancelled', 'canceled'],
                'cutoff_date_field' => 'accounting_date',
                'due_date_field' => 'due_date',
                'null_due_date' => 'invoice_cutoff_date',
                'party_key' => $isAp ? 'supplier_id' : 'customer_id',
                'party_table' => $isAp ? 'suppliers' : 'customers',
                'party_code_field' => 'code',
                'party_name_field' => 'name',
                'total_amount_field' => 'total_amount',
            ],
            'allocations' => [
                'table' => 'settlement_allocations',
                'company_key' => 'company_id',
                'posted_field' => 'status',
                'posted_value' => 'posted',
                'effective_date_field' => 'effective_date',
                'target_type_field' => 'target_document_type',
                'target_id_field' => 'target_document_id',
                'target_type' => $isAp ? 'purchase_invoice' : 'sales_invoice',
                'source_type_field' => 'source_document_type',
                'source_types' => $isAp
                    ? ['cash_payment', 'bank_payment', 'purchase_return', 'purchase_discount', 'ap_debt_adjustment']
                    : ['cash_receipt', 'bank_receipt', 'sales_return', 'sales_discount', 'ar_debt_adjustment'],
                // Payments and receipts spend a concrete payment line. Debt
                // reductions such as returns and discounts are themselves
                // posted source documents and deliberately have no payment
                // line. Keeping these sets separate prevents the query from
                // discarding valid adjustment evidence.
                'line_required_source_types' => $isAp
                    ? ['cash_payment', 'bank_payment']
                    : ['cash_receipt', 'bank_receipt'],
                'document_amount_source_types' => $isAp
                    ? ['purchase_return', 'purchase_discount', 'ap_debt_adjustment']
                    : ['sales_return', 'sales_discount', 'ar_debt_adjustment'],
                'amount_field' => 'amount_raw',
                'amount_scale_field' => 'amount_scale',
            ],
            // Returns, discounts, credit notes and write-offs cannot be
            // inferred from an invoice status or an untyped amount. Until
            // typed, posted and source-referenced adjustment feeds are added
            // to this contract, the engine remains unavailable.
            'adjustment_completeness' => [
                'status' => 'partial',
                'available_sources' => ['returns', 'discounts', 'credit_notes', 'write_offs', 'reversals'],
                'required_sources' => ['returns', 'discounts', 'credit_notes', 'write_offs', 'reversals', 'fx_revaluation'],
                'requirement' => 'typed_posted_referenced_owner_approved',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function supportedCalculationContract(): array
    {
        return [
            'schema' => 'allocation-line-aging-calculation.v2',
            'outstanding' => 'invoice_total_less_posted_allocations',
            'cutoff_inclusion' => 'inclusive',
            'aging_date' => 'due_date',
            'currency_precision' => 2,
            'negative_outstanding' => 'report_credit_balance_separately',
            'buckets' => self::BUCKETS,
            'adjustment_completeness' => 'typed_posted_referenced_owner_approved',
        ];
    }

    public static function resolveInvoiceAgingDate(?string $dueDate, ?string $invoiceCutoffDate): ?string
    {
        return $dueDate ?? $invoiceCutoffDate;
    }

    /** @return list<array<string, int|string>> */
    private function execute(
        ManagementReportDefinition $definition,
        int $companyId,
        CarbonImmutable|string $asOfDate,
        string $ledger,
    ): array {
        $reportKey = $ledger === 'ap' ? 'accounts_payable_aging.v2' : 'accounts_receivable_aging.v2';
        $this->assertSupportedDefinition($definition, $companyId, $ledger, $reportKey);
        $this->readiness->assertExecutable($ledger);
        $cutoff = $asOfDate instanceof CarbonImmutable
            ? $asOfDate->startOfDay()
            : CarbonImmutable::createFromFormat('!Y-m-d', $asOfDate)->startOfDay();

        $source = self::supportedSourceContract($ledger);
        $invoice = $source['invoice'];
        $invoices = DB::table($invoice['table'].' as i')
            ->leftJoin($invoice['party_table'].' as p', function ($join) use ($invoice): void {
                $join->on('p.id', '=', 'i.'.$invoice['party_key'])
                    ->on('p.company_id', '=', 'i.company_id');
            })
            ->where('i.company_id', $companyId)
            ->where('i.is_posted', true)
            ->where(function ($query) use ($invoice): void {
                $query->whereNull('i.'.$invoice['status_field'])
                    ->orWhereNotIn('i.'.$invoice['status_field'], $invoice['excluded_statuses']);
            })
            ->whereNotNull('i.accounting_date')
            ->whereDate('i.accounting_date', '<=', $cutoff->toDateString())
            ->orderBy('i.id')
            ->select([
                'i.id',
                'i.'.$invoice['party_key'].' as party_id',
                'p.id as resolved_party_id',
                'p.code as party_code',
                'p.name as party_name',
                'i.total_amount',
                'i.accounting_date',
                'i.due_date',
            ])
            ->get();

        if ($invoices->isEmpty()) {
            // Source completeness is a report-level prerequisite, not a
            // property of the particular cutoff result. An empty invoice set
            // must not turn an ineligible definition into a successful empty
            // report.
            $this->assertAllocationAndAdjustmentCompleteness($source['allocations']);

            return [];
        }

        // A party record from another tenant must never be used simply
        // because its primary key happens to match an invoice foreign key.
        // A missing/cross-tenant party makes the source evidence incomplete,
        // so fail the entire run instead of publishing an unattributed row.
        foreach ($invoices as $row) {
            if ($row->party_id === null || $row->resolved_party_id === null) {
                throw new ReportDefinitionUnavailableException($reportKey);
            }
        }

        $this->assertAllocationAndAdjustmentCompleteness($source['allocations']);

        $allocationByInvoice = $this->postedAllocationsByInvoice(
            $source['allocations'],
            $companyId,
            $invoices->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            $cutoff->toDateString(),
        );

        $parties = [];
        foreach ($invoices as $row) {
            $invoiceAmount = DecimalMoney::normalize((string) $row->total_amount);
            $allocatedAmount = $allocationByInvoice[(int) $row->id] ?? DecimalMoney::ZERO;
            $outstandingAmount = DecimalMoney::subtract($invoiceAmount, $allocatedAmount);

            $partyId = (int) $row->party_id;
            if (! isset($parties[$partyId])) {
                $parties[$partyId] = [
                    $ledger === 'ap' ? 'supplier_id' : 'customer_id' => $partyId,
                    $ledger === 'ap' ? 'supplier_code' : 'customer_code' => (string) ($row->party_code ?? ''),
                    $ledger === 'ap' ? 'supplier_name' : 'customer_name' => (string) ($row->party_name ?? ''),
                    'total_due_amount' => DecimalMoney::ZERO,
                    'current_amount' => DecimalMoney::ZERO,
                    'days_1_30_amount' => DecimalMoney::ZERO,
                    'days_31_60_amount' => DecimalMoney::ZERO,
                    'days_over_60_amount' => DecimalMoney::ZERO,
                    'credit_balance_amount' => DecimalMoney::ZERO,
                ];
            }

            // Credit balances are deliberately not netted against other
            // invoices or placed in an aging bucket. The signed contract
            // requires them as a separate reconciliation amount, so an
            // over-allocation remains visible instead of disappearing.
            if (DecimalMoney::compare($outstandingAmount, DecimalMoney::ZERO) < 0) {
                $parties[$partyId]['credit_balance_amount'] = DecimalMoney::add(
                    $parties[$partyId]['credit_balance_amount'],
                    DecimalMoney::abs($outstandingAmount),
                );

                continue;
            }

            if ($outstandingAmount === DecimalMoney::ZERO) {
                continue;
            }

            $bucket = $this->bucketFor(
                self::resolveInvoiceAgingDate($row->due_date, $row->accounting_date),
                $cutoff,
            );
            $parties[$partyId]['total_due_amount'] = DecimalMoney::add($parties[$partyId]['total_due_amount'], $outstandingAmount);
            $parties[$partyId][$bucket.'_amount'] = DecimalMoney::add($parties[$partyId][$bucket.'_amount'], $outstandingAmount);
        }

        return array_values(array_map(function (array $party): array {
            foreach (['total_due', 'current', 'days_1_30', 'days_31_60', 'days_over_60', 'credit_balance'] as $field) {
                $party[$field] = $party[$field.'_amount'];
                unset($party[$field.'_amount']);
            }

            return $party;
        }, $parties));
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<int>  $invoiceIds
     * @return array<int, string>
     */
    private function postedAllocationsByInvoice(array $source, int $companyId, array $invoiceIds, string $cutoff): array
    {
        $amounts = [];
        $rows = DB::table($source['table'])
            ->where($source['company_key'], $companyId)
            ->where($source['posted_field'], $source['posted_value'])
            ->whereDate($source['effective_date_field'], '<=', $cutoff)
            ->where($source['target_type_field'], $source['target_type'])
            ->whereIn($source['source_type_field'], $source['source_types'])
            ->whereIn($source['target_id_field'], $invoiceIds)
            ->select([
                $source['target_id_field'].' as invoice_id',
                $source['source_type_field'].' as source_type',
                'source_line_type',
                'source_line_id',
                $source['amount_field'].' as amount_raw',
                $source['amount_scale_field'].' as amount_scale',
                'allocation_direction',
            ])
            ->get();

        foreach ($rows as $row) {
            $sourceType = (string) $row->source_type;
            $requiresLine = in_array($sourceType, $source['line_required_source_types'], true);
            $isDocumentAmount = in_array($sourceType, $source['document_amount_source_types'], true);
            if (($requiresLine && ($row->source_line_type === null || $row->source_line_id === null))
                || ($isDocumentAmount && ($row->source_line_type !== null || $row->source_line_id !== null))) {
                throw new ReportDefinitionUnavailableException('settlement_allocations.v1');
            }
            $invoiceId = (int) $row->invoice_id;
            $amount = $this->canonicalAllocationAmount((string) $row->amount_raw, (int) $row->amount_scale);
            if (($row->allocation_direction ?? 'reduction') === 'reversal') {
                $amount = DecimalMoney::subtract(DecimalMoney::ZERO, $amount);
            }
            $amounts[$invoiceId] = DecimalMoney::add(
                $amounts[$invoiceId] ?? DecimalMoney::ZERO,
                $amount,
            );
        }

        return $amounts;
    }

    /**
     * The canonical table is typed and line-referenced. Its presence is still
     * insufficient while adjustment feeds are incomplete, so this guard
     * deliberately remains fail-closed.
     */
    private function assertAllocationAndAdjustmentCompleteness(array $source): void
    {
        if (! Schema::hasColumns($source['table'], [
            'company_id', 'source_document_type', 'source_line_type', 'source_line_id',
            'target_document_type', 'target_document_id', 'amount_raw', 'amount_scale',
            'allocation_direction', 'effective_date', 'status',
        ])) {
            throw new ReportDefinitionUnavailableException(
                $source['target_type'] === 'purchase_invoice'
                    ? 'accounts_payable_aging.v2'
                    : 'accounts_receivable_aging.v2',
            );
        }

        // Detailed business/cutoff/reconciliation readiness is evaluated
        // before any invoice or allocation is read. Keep this structural
        // guard here as defense in depth for direct service invocation.
    }

    private function canonicalAllocationAmount(string $amount, int $scale): string
    {
        return match ($scale) {
            0 => DecimalMoney::normalize($amount.'.00'),
            2 => DecimalMoney::normalize($amount),
            default => throw new ReportDefinitionUnavailableException('settlement_allocations.v1'),
        };
    }

    private function assertSupportedDefinition(
        ManagementReportDefinition $definition,
        int $companyId,
        string $ledger,
        string $reportKey,
    ): void {
        if ((int) $definition->company_id !== $companyId
            || $definition->report_key !== $reportKey
            || $definition->status !== 'published'
            || $definition->signed_by === null
            || $definition->signed_at === null
            || $definition->published_at === null
            || ! is_string($definition->contract_hash)
            || $definition->contract_hash === ''
            || $definition->source_contract !== self::supportedSourceContract($ledger)
            || $definition->calculation_contract !== self::supportedCalculationContract()
            || ! hash_equals($definition->contract_hash, $this->hasher->hash(
                $definition->report_key,
                $definition->definition_version,
                $definition->source_contract,
                $definition->calculation_contract,
                $definition->amount_contract,
            ))) {
            throw new ReportDefinitionUnavailableException($reportKey);
        }
    }

    private function bucketFor(mixed $dueDate, CarbonImmutable $cutoff): string
    {
        $due = CarbonImmutable::parse((string) $dueDate)->startOfDay();
        $daysPastDue = $due->gte($cutoff) ? 0 : $due->diffInDays($cutoff);

        if ($daysPastDue <= 0) {
            return 'current';
        }
        if ($daysPastDue <= 30) {
            return 'days_1_30';
        }
        if ($daysPastDue <= 60) {
            return 'days_31_60';
        }

        return 'days_over_60';
    }

}
