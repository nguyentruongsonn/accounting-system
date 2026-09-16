<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Exports\GeneralLedgerExport;
use App\Http\Controllers\Api\V1\Report\Concerns\AuditsReportIssuance;
use App\Http\Controllers\Controller;
use App\Services\FinancialReportContextResolver;
use App\Services\FinancialReportService;
use App\Services\ReportIssuanceAuditService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class GeneralLedgerController extends Controller
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
        $context = $this->contextResolver->resolve($request, 'general_ledger');
        $companyId = $context->companyId;
        $accountCode = $request->input('account_code');
        $fromDate = $context->fromDate;
        $toDate = $context->toDate;

        if (! $accountCode) {
            return response()->json(['message' => 'Account code is required'], 400);
        }

        $result = $this->service->getGeneralLedger($companyId, $accountCode, $fromDate, $toDate);
        $openingBalance = $this->service->getGeneralLedgerOpeningBalance($companyId, $accountCode, $fromDate);
        $this->auditReportIssuance($request, $this->reportAudit, $companyId, 'general_ledger', $this->reportDelivery($request), [
            'account_code' => $accountCode,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'fiscal_year_id' => $context->fiscalYear->id,
            'opening_balance' => $openingBalance,
        ], $context, $result);

        if ($request->query('export') === 'excel') {
            return Excel::download(
                new GeneralLedgerExport($result, $openingBalance, $accountCode, $fromDate, $toDate),
                'general_ledger.xlsx',
            );
        }

        if ($request->query('export') === 'pdf') {
            return Pdf::loadView('reports.pdf.general_ledger', [
                'data' => $result,
                'openingBalance' => $openingBalance,
                'accountCode' => $accountCode,
                'fromDate' => $fromDate,
                'toDate' => $toDate,
            ])->download('general_ledger.pdf');
        }

        $meta = [
            'company_id' => $companyId,
            'account_code' => $accountCode,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'fiscal_year_id' => $context->fiscalYear->id,
            'opening_balance' => $openingBalance,
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
