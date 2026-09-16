<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Exports\TrialBalanceExport;
use App\Http\Controllers\Api\V1\Report\Concerns\AuditsReportIssuance;
use App\Http\Controllers\Controller;
use App\Services\FinancialReportContextResolver;
use App\Services\FinancialReportService;
use App\Services\ReportIssuanceAuditService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class TrialBalanceController extends Controller
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
        $context = $this->contextResolver->resolve($request);
        $companyId = $context->companyId;
        $fromDate = $context->fromDate;
        $toDate = $context->toDate;
        $result = $this->service->getTrialBalance($companyId, $fromDate, $toDate);
        $this->auditReportIssuance($request, $this->reportAudit, $companyId, 'trial_balance', $this->reportDelivery($request), [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'fiscal_year_id' => $context->fiscalYear->id,
        ], $context, $result);

        if ($request->query('export') === 'excel') {
            return Excel::download(
                new TrialBalanceExport($result->toArray()),
                'trial_balance.xlsx'
            );
        }

        if ($request->query('export') === 'pdf') {
            $pdf = Pdf::loadView('reports.pdf.trial_balance', ['data' => $result]);

            return $pdf->download('trial_balance.pdf');
        }

        if ($request->boolean('include_metadata')) {
            return response()->json([
                'data' => $result,
                'meta' => $context->regimeMetadata,
            ]);
        }

        return response()->json($result);
    }
}
