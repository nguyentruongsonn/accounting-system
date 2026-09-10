<?php

namespace App\Http\Controllers\Api\V1\GL;

use App\Http\Controllers\Controller;
use App\Services\PeriodClosingService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PeriodClosingController extends Controller
{
    protected PeriodClosingService $service;

    public function __construct(PeriodClosingService $service)
    {
        $this->service = $service;
    }

    public function preview(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $fromDate = $request->input('from_date', date('Y-m-01'));
        $toDate = $request->input('to_date', date('Y-m-t'));

        $preview = $this->service->preview($companyId, $fromDate, $toDate);

        return response()->json([
            'success' => true,
            'data' => $preview,
            ...$preview,
        ]);
    }

    public function execute(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $validated = $request->validate([
            // close_reason is a control/audit reason, distinct from the
            // voucher description. Keep reason as a backwards-compatible
            // input alias for existing clients.
            'close_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $closeReason = trim((string) ($validated['close_reason'] ?? $validated['reason'] ?? ''));
        if ($closeReason === '') {
            throw ValidationException::withMessages([
                'close_reason' => 'Phải nêu lý do khóa kỳ kế toán.',
            ]);
        }

        $data = $request->only([
            'period_id',
            'from_date',
            'to_date',
            'voucher_date',
            'posting_date',
            'voucher_number',
            'description',
            'close_reason',
            'reason',
            // Kept only so the service can explicitly reject a tampered payload.
            'lines',
        ]);
        // Pass the normalized canonical value to the service so direct and
        // HTTP callers persist exactly the same audit metadata.
        $data['close_reason'] = $closeReason;

        $closingVoucher = $this->service->executeForCompany($companyId, $data);

        return response()->json([
            'success' => true,
            'message' => 'Đã tự động kết chuyển lãi lỗ và ghi sổ cái thành công!',
            'data' => $closingVoucher,
        ], 200);
    }
}
