<?php

namespace App\Http\Controllers\Api\V1\Report\Concerns;

use App\Services\ReportIssuanceAuditService;
use App\Support\FinancialReportContext;
use Illuminate\Http\Request;

trait AuditsReportIssuance
{
    /**
     * @param  array<string, scalar|null>  $filters
     */
    private function auditReportIssuance(
        Request $request,
        ReportIssuanceAuditService $reportAudit,
        int $companyId,
        string $report,
        string $delivery,
        array $filters,
        FinancialReportContext $context,
        mixed $output,
    ): void {
        // Preserve the exact context that selected the report data.  A second
        // resolution could choose a different profile if configuration changed
        // between report calculation and audit recording.
        $reportAudit->record(
            $request,
            $companyId,
            $report,
            $delivery,
            $filters,
            $context->regimeMetadata,
            $context,
            $output,
        );
    }

    private function reportDelivery(Request $request): string
    {
        return match ($request->query('export')) {
            'excel' => 'export_excel',
            'pdf' => 'export_pdf',
            default => 'view',
        };
    }
}
