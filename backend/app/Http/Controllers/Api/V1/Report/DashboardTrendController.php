<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Services\DashboardTrendService;
use App\Services\FinancialReportContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardTrendController extends Controller
{
    public function __construct(
        private readonly DashboardTrendService $trendService,
        private readonly FinancialReportContextResolver $contextResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $context = $this->contextResolver->resolve($request);
        $rows = $this->trendService->build($context->companyId, $context->fromDate, $context->toDate);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'from_date' => $context->fromDate,
                'to_date' => $context->toDate,
                'fiscal_year_id' => $context->fiscalYear->id,
                'source' => 'posted_general_ledger',
                'complete' => collect($rows)->every(static fn (array $row): bool => $row['profit'] !== null),
            ],
        ]);
    }
}
