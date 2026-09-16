<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Services\ManagementReportCapabilityService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagementReportCapabilityController extends Controller
{
    public function __construct(private readonly ManagementReportCapabilityService $capabilities) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->capabilities->manifest(
            TenantContext::companyId($request),
            fn (string $permission): bool => $request->user()?->can($permission) ?? false,
        ));
    }
}
