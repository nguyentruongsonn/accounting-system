<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ReportRun;
use App\Support\FinancialReportContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records the fact that an authorised user obtained a financial report.
 *
 * This is deliberately a passive audit: a reporting read/export must remain
 * available if the audit sink is temporarily unavailable.  Posting and close
 * audits are transactional controls; report-access audit is a forensic
 * control and is therefore fail-open by policy.
 */
class ReportIssuanceAuditService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly ReportPackageControlService $packageControls,
    ) {}

    /**
     * @param  array<string, scalar|null>  $filters
     * @param  array<string, scalar|null>  $regimeMetadata
     */
    public function record(
        Request $request,
        int $companyId,
        string $report,
        string $delivery,
        array $filters,
        array $regimeMetadata = [],
        ?FinancialReportContext $context = null,
        mixed $output = null,
    ): void {
        try {
            $company = Company::query()->findOrFail($companyId);
            $requestId = $this->requestId($request);
            $runReceipt = null;

            if ($context !== null) {
                $runReceipt = $this->recordRun($request, $requestId, $report, $delivery, $filters, $context, $output);
            }

            $this->auditService->record(
                $company,
                "report.{$report}.{$delivery}",
                [],
                [],
                $requestId,
                [
                    'audit_schema' => 'report-issuance.v1',
                    'request_id' => $requestId,
                    'report' => $report,
                    'delivery' => $delivery,
                    'filters' => $filters,
                    // Never place report rows, account balances, voucher lines,
                    // or other generated financial output in the audit record.
                    'period' => [
                        'from_date' => $filters['from_date'] ?? null,
                        'to_date' => $filters['to_date'] ?? null,
                        'fiscal_year_id' => $filters['fiscal_year_id'] ?? null,
                    ],
                    'regime' => $regimeMetadata,
                    'report_run' => $runReceipt === null ? null : [
                        // The audit log contains only identity/control data.
                        // The controlled snapshot is stored in report_runs.
                        'snapshot_schema' => 'report-run.v1',
                        ...$runReceipt,
                    ],
                ]
            );
        } catch (Throwable $exception) {
            Log::warning('Passive report issuance audit failed.', [
                'report' => $report,
                'delivery' => $delivery,
                'company_id' => $companyId,
                'user_id' => $request->user()?->getAuthIdentifier(),
                'exception_class' => $exception::class,
            ]);
        }
    }

    /**
     * Audit access to immutable control evidence without copying report data
     * or control details into the operational audit log.
     */
    public function recordControlEvidenceView(Request $request, ReportRun $run): void
    {
        try {
            $this->auditService->record(
                $run,
                'report.run.controls.view',
                [],
                [],
                $this->requestId($request),
                [
                    'audit_schema' => 'report-run-control-evidence-access.v1',
                    'report_run_uuid' => $run->uuid,
                    'report' => $run->report,
                    'non_certifying' => true,
                    'not_a_close_gate' => true,
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('Passive report control evidence access audit failed.', [
                'company_id' => $run->company_id,
                'report_run_uuid' => $run->uuid,
                'exception_class' => $exception::class,
            ]);
        }
    }

    /**
     * Record a canonical logical-output snapshot.  The underlying Excel/PDF
     * libraries can add non-deterministic bytes, so a file checksum would not
     * faithfully identify the accounting result. output_hash instead hashes
     * the canonical report data before it is rendered for a delivery channel.
     *
     * @param  array<string, scalar|null>  $filters
     */
    /** @return array{uuid: string, control_hash: string, output_identity: string} */
    private function recordRun(
        Request $request,
        string $requestId,
        string $report,
        string $delivery,
        array $filters,
        FinancialReportContext $context,
        mixed $output,
    ): array {
        $period = [
            'fiscal_year_id' => $context->fiscalYear->id,
            'fiscal_year_start' => $context->fiscalYear->start_date->toDateString(),
            'fiscal_year_end' => $context->fiscalYear->end_date->toDateString(),
            'from_date' => $context->fromDate,
            'to_date' => $context->toDate,
        ];
        $canonicalFilters = $this->canonicalize($filters);
        $canonicalRegime = $this->canonicalize($context->regimeMetadata);
        $canonicalOutput = $this->canonicalize($output);
        $controls = $this->packageControls->evaluate($report, $canonicalOutput);
        $control = [
            'schema' => 'report-run.v1',
            'company_id' => $context->companyId,
            'report' => $report,
            'delivery' => $delivery,
            'filters' => $canonicalFilters,
            'period' => $period,
            'regime' => $canonicalRegime,
            'output_contract' => 'canonical-logical-json.v1',
            'definition_version' => null,
            'appendix_iv_certified' => false,
        ];
        $controlHash = $this->hash($control);
        $outputHash = $this->hash($canonicalOutput);

        $uuid = (string) Str::uuid();
        ReportRun::withoutGlobalScope('company')->create([
            'uuid' => $uuid,
            'company_id' => $context->companyId,
            'fiscal_year_id' => $context->fiscalYear->id,
            'issued_by' => $request->user()?->getAuthIdentifier(),
            'correlation_id' => $requestId,
            'report' => $report,
            'delivery' => $delivery,
            'snapshot_schema' => 'report-run.v1',
            'filters' => $canonicalFilters,
            'period' => $period,
            'regime' => $canonicalRegime,
            'control_hash' => $controlHash,
            'output_hash' => $outputHash,
            'output_identity' => 'sha256:'.$outputHash,
            'snapshot' => [
                'schema' => 'canonical-logical-json.v1',
                'definition_version' => null,
                'appendix_iv_certified' => false,
                'data' => $canonicalOutput,
                // Informational arithmetic controls are tied to the exact
                // output snapshot. They are neither a certification nor a
                // period-close decision.
                'package_controls' => $controls,
            ],
            'issued_at' => now(),
        ]);

        return [
            'uuid' => $uuid,
            'control_hash' => $controlHash,
            'output_identity' => 'sha256:'.$outputHash,
        ];
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Convert framework/DTO values to JSON and recursively sort object keys.
     * List order remains unchanged because row order is part of a report's
     * logical output. Numeric-looking decimal strings remain strings.
     */
    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            return $this->canonicalize($value->jsonSerialize());
        }

        if ($value instanceof \Traversable) {
            return $this->canonicalize(iterator_to_array($value));
        }

        if (is_object($value)) {
            return $this->canonicalize(get_object_vars($value));
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[(string) $key] = $this->canonicalize($item);
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function requestId(Request $request): string
    {
        $candidate = (string) $request->header('X-Request-ID', '');

        return Str::isUuid($candidate) ? $candidate : (string) Str::uuid();
    }
}
