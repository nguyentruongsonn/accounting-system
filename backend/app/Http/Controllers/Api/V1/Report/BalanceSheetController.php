<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Exports\BalanceSheetExport;
use App\Http\Controllers\Api\V1\Report\Concerns\AuditsReportIssuance;
use App\Http\Controllers\Controller;
use App\Services\FinancialReportContextResolver;
use App\Services\FinancialReportService;
use App\Services\ReportIssuanceAuditService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class BalanceSheetController extends Controller
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
        $toDate = $context->toDate;
        $fromDate = $context->fromDate;
        $result = $this->service->getBalanceSheet($companyId, $toDate, $fromDate);
        $this->auditReportIssuance($request, $this->reportAudit, $companyId, 'balance_sheet', $this->reportDelivery($request), [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'fiscal_year_id' => $context->fiscalYear->id,
        ], $context, $result);

        if ($request->query('export') === 'excel') {
            return Excel::download(
                new BalanceSheetExport($result),
                'balance_sheet.xlsx'
            );
        }

        if ($request->query('export') === 'pdf') {
            $pdf = Pdf::loadView('reports.pdf.balance_sheet', ['data' => $result]);

            return $pdf->download('balance_sheet.pdf');
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
