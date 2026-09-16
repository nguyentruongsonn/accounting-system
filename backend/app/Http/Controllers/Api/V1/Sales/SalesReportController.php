<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Services\SalesManagementReportService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SalesReportController extends Controller
{
    public function __construct(private readonly SalesManagementReportService $service) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $input = $request->validate([
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d'],
            'customer_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
        ]);

        if (isset($input['from_date'], $input['to_date']) && $input['from_date'] > $input['to_date']) {
            return response()->json([
                'message' => 'The from_date must be before or equal to the to_date.',
                'errors' => [
                    'from_date' => ['The from_date must be before or equal to the to_date.'],
                    'to_date' => ['The to_date must be after or equal to the from_date.'],
                ],
            ], 422);
        }

        return response()->json($this->service->generate(
            $companyId,
            $input['from_date'] ?? null,
            $input['to_date'] ?? null,
            isset($input['customer_id']) ? (int) $input['customer_id'] : null,
        ));
    }
}
