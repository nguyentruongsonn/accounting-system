<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Exports\GeneralJournalExport;
use App\Http\Controllers\Api\V1\Report\Concerns\AuditsReportIssuance;
use App\Http\Controllers\Controller;
use App\Services\FinancialReportContextResolver;
use App\Services\FinancialReportService;
use App\Services\ReportIssuanceAuditService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class GeneralJournalController extends Controller
{
    use AuditsReportIssuance;

    protected FinancialReportService $service;

    public function __construct(
        FinancialReportService $service,
        private readonly ReportIssuanceAuditService $reportAudit,
        private readonly FinancialReportContextResolver $contextResolver,
    ) {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $context = $this->contextResolver->resolve($request, 'general_journal');
        $companyId = $context->companyId;
        $fromDate = $context->fromDate;
        $toDate = $context->toDate;

        $result = $this->service->getGeneralJournal($companyId, $fromDate, $toDate);
        $this->auditReportIssuance($request, $this->reportAudit, $companyId, 'general_journal', $this->reportDelivery($request), [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'fiscal_year_id' => $context->fiscalYear->id,
        ], $context, $result);

        if ($request->query('export') === 'excel') {
            return Excel::download(
                new GeneralJournalExport($result, $fromDate, $toDate),
                'general_journal.xlsx',
            );
        }

        if ($request->query('export') === 'pdf') {
            return Pdf::loadView('reports.pdf.general_journal', [
                'data' => $result,
                'fromDate' => $fromDate,
                'toDate' => $toDate,
            ])->download('general_journal.pdf');
        }

        $meta = [
            'company_id' => $companyId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'fiscal_year_id' => $context->fiscalYear->id,
        ];
        if ($request->boolean('include_metadata')) {
            $meta = [
                ...$meta,
                ...$context->regimeMetadata,
            ];
        }

        return response()->json([
            'data' => $result,
            'meta' => $meta,
        ]);
    }
}
