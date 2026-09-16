<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Services\FinancialReportService;

trait ReportTrait
{
    protected function getAccountBalances(int $companyId)
    {
        $service = app(FinancialReportService::class);

        return $service->getAccountBalances($companyId);
    }

    protected function getEndingBalance($balanceMap, $accountCode, $nature = 'debit')
    {
        $service = app(FinancialReportService::class);

        return $service->getEndingBalance($balanceMap, $accountCode, $nature);
    }
}
