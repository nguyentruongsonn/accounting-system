<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AccountingAuditTrailExplorer;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountingAuditTrailController extends Controller
{
    public function __construct(private readonly AccountingAuditTrailExplorer $explorer) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:120'], 'correlation_id' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:80'], 'entity_id' => ['nullable', 'string', 'max:80'],
            'from_date' => ['nullable', 'date_format:Y-m-d'], 'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->explorer->search(TenantContext::companyId($request), $filters));
    }

    public function show(Request $request, string $entityType, string $entityId): JsonResponse
    {
        return response()->json($this->explorer->trace(TenantContext::companyId($request), $entityType, $entityId));
    }
}
