<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFixedAssetRequest;
use App\Services\FixedAssetService;
use App\Support\TenantContext;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class FixedAssetController extends Controller
{
    protected FixedAssetService $service;

    public function __construct(FixedAssetService $service)
    {
        $this->service = $service;
    }

    /**
     * Danh sách tài sản cố định
     */
    public function index(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $filters = array_merge($request->except('company_id'), [
            'company_id' => $companyId,
        ]);

        return response()->json($this->service->getAll($filters));
    }

    /**
     * Sinh mã tự động tiếp theo
     */
    public function nextCode(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $type = $request->input('type', 'TSCD');
        $code = $this->service->generateNextCode($companyId, $type);

        return response()->json(['code' => $code]);
    }

    /**
     * Chi tiết tài sản cố định
     */
    public function show(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        try {
            $asset = $this->service->getById($companyId, (int) $id);

            return response()->json($asset);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Resource not found.'], 404);
        } catch (Exception $e) {
            report($e);

            return response()->json(['error' => 'Unable to load the fixed asset.'], 500);
        }
    }

    /**
     * Tạo mới tài sản cố định
     */
    public function store(StoreFixedAssetRequest $request)
    {
        try {
            $data = $request->validated();
            $data['company_id'] = TenantContext::companyId($request);
            $asset = $this->service->createAsset($data);

            return response()->json($asset, 201);
        } catch (Exception $e) {
            return $this->lifecycleError($e);
        }
    }

    /**
     * Cập nhật tài sản cố định
     */
    public function update(Request $request, $id)
    {
        try {
            $companyId = TenantContext::companyId($request);
            $asset = $this->service->updateAsset($companyId, (int) $id, $request->except('company_id'));

            return response()->json($asset);
        } catch (Exception $e) {
            return $this->lifecycleError($e);
        }
    }

    /**
     * Xóa tài sản cố định
     */
    public function destroy(Request $request, $id)
    {
        try {
            $companyId = TenantContext::companyId($request);
            $this->service->deleteAsset($companyId, (int) $id);

            return response()->json(['message' => 'Đã xóa tài sản cố định thành công']);
        } catch (Exception $e) {
            return $this->lifecycleError($e);
        }
    }

    /**
     * Ghi sổ chứng từ ghi tăng TSCĐ
     */
    public function post(Request $request, $id)
    {
        try {
            $companyId = TenantContext::companyId($request);
            $asset = $this->service->postAsset($companyId, (int) $id);

            return response()->json(['message' => 'Ghi sổ thành công', 'data' => $asset]);
        } catch (Exception $e) {
            return $this->lifecycleError($e);
        }
    }

    /**
     * Bỏ ghi sổ chứng từ ghi tăng TSCĐ
     */
    public function unpost(Request $request, $id)
    {
        try {
            $companyId = TenantContext::companyId($request);
            $asset = $this->service->unpostAsset($companyId, (int) $id);

            return response()->json(['message' => 'Bỏ ghi sổ thành công', 'data' => $asset]);
        } catch (Exception $e) {
            return $this->lifecycleError($e);
        }
    }

    /**
     * Nhân bản tài sản cố định
     */
    public function duplicate(Request $request, $id)
    {
        try {
            $companyId = TenantContext::companyId($request);
            $asset = $this->service->duplicateAsset($companyId, (int) $id);

            return response()->json(['message' => 'Nhân bản tài sản thành công', 'data' => $asset], 201);
        } catch (Exception $e) {
            return $this->lifecycleError($e);
        }
    }

    /**
     * Danh sách các kỳ trích khấu hao
     */
    public function depreciationIndex(Request $request)
    {
        $companyId = TenantContext::companyId($request);

        return response()->json($this->service->getDepreciationPeriods((int) $companyId));
    }

    /**
     * Chi tiết kỳ trích khấu hao
     */
    public function depreciationShow(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        try {
            $log = $this->service->getDepreciationPeriodById($companyId, (int) $id);

            return response()->json($log);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Resource not found.'], 404);
        } catch (Exception $e) {
            report($e);

            return response()->json(['error' => 'Unable to load the depreciation period.'], 500);
        }
    }

    /**
     * Xem trước bảng tính khấu hao tháng
     */
    public function previewDepreciation(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $month = $request->input('month', now()->format('Y-m'));

        try {
            $preview = $this->service->previewMonthlyDepreciation((int) $companyId, $month);

            return response()->json($preview);
        } catch (Exception $e) {
            return $this->lifecycleError($e);
        }
    }

    /**
     * Chạy trích khấu hao tháng và sinh GL
     */
    public function runDepreciation(Request $request)
    {
        $request->validate([
            'month' => 'required|date_format:Y-m',
        ]);

        $companyId = TenantContext::companyId($request);
        $month = $request->input('month');
        $description = $request->input('description');

        try {
            $log = $this->service->runMonthlyDepreciation((int) $companyId, $month, $description);

            return response()->json(['message' => 'Chạy khấu hao thành công', 'data' => $log], 200);
        } catch (Exception $e) {
            return $this->lifecycleError($e);
        }
    }

    /**
     * Bỏ ghi sổ kỳ khấu hao
     */
    public function unpostDepreciation(Request $request, $id)
    {
        try {
            $companyId = TenantContext::companyId($request);
            $log = $this->service->unpostMonthlyDepreciation($companyId, (int) $id);

            return response()->json(['message' => 'Bỏ ghi sổ kỳ khấu hao thành công', 'data' => $log]);
        } catch (Exception $e) {
            return $this->lifecycleError($e);
        }
    }

    /**
     * Xóa kỳ khấu hao
     */
    public function destroyDepreciation(Request $request, $id)
    {
        try {
            $companyId = TenantContext::companyId($request);
            $this->service->deleteMonthlyDepreciation($companyId, (int) $id);

            return response()->json(['message' => 'Xóa kỳ khấu hao thành công']);
        } catch (Exception $e) {
            return $this->lifecycleError($e);
        }
    }

    /**
     * Ghi giảm / Thanh lý TSCĐ
     */
    public function dispose(Request $request, $id)
    {
        try {
            $companyId = TenantContext::companyId($request);
            $disposal = $this->service->disposeAsset($companyId, (int) $id, $request->except('company_id'));

            return response()->json(['message' => 'Thanh lý TSCĐ thành công', 'data' => $disposal], 201);
        } catch (Exception $e) {
            return $this->lifecycleError($e);
        }
    }

    /**
     * Đánh giá lại TSCĐ
     */
    public function revalue(Request $request, $id)
    {
        $request->validate([
            'new_original_cost' => 'required|numeric|min:0',
        ]);

        try {
            $companyId = TenantContext::companyId($request);
            $reval = $this->service->revalueAsset($companyId, (int) $id, $request->except('company_id'));

            return response()->json(['message' => 'Đánh giá lại TSCĐ thành công', 'data' => $reval], 201);
        } catch (Exception $e) {
            return $this->lifecycleError($e);
        }
    }

    /**
     * Danh sách chứng từ thanh lý
     */
    public function disposalIndex(Request $request)
    {
        $companyId = TenantContext::companyId($request);

        return response()->json($this->service->getDisposals((int) $companyId));
    }

    /**
     * Danh sách chứng từ đánh giá lại
     */
    public function revaluationIndex(Request $request)
    {
        $companyId = TenantContext::companyId($request);

        return response()->json($this->service->getRevaluations((int) $companyId));
    }

    /** Do not reveal or act on a lifecycle resource belonging to another tenant. */
    private function lifecycleError(Throwable $exception)
    {
        if ($exception instanceof ModelNotFoundException) {
            return response()->json(['error' => 'Resource not found.'], 404);
        }

        if ($exception instanceof ValidationException) {
            return response()->json([
                'error' => 'The given data was invalid.',
                'errors' => $exception->errors(),
            ], 422);
        }

        // Database/driver messages can contain SQL, table names, and connection details.
        // Keep the client contract generic while retaining the exception in server logs.
        if ($exception instanceof QueryException || $exception instanceof \PDOException) {
            report($exception);

            return response()->json(['error' => 'Unable to complete the fixed-asset request.'], 500);
        }

        // Service-level lifecycle guards intentionally expose actionable business messages
        // (for example, an already-posted asset). Unexpected exceptions are not allowed to
        // surface implementation details; report them and use a stable client message.
        $message = $exception->getMessage();
        $safePrefixes = [
            'Không thể ',
            'Tài sản ',
            'Kỳ khấu hao ',
            'Không có tài sản ',
        ];
        $isSafeBusinessMessage = $message !== '' && collect($safePrefixes)
            ->contains(fn (string $prefix): bool => str_starts_with($message, $prefix));

        if (! $isSafeBusinessMessage) {
            report($exception);

            return response()->json(['error' => 'Unable to complete the fixed-asset request.'], 500);
        }

        return response()->json(['error' => $message, 'message' => $message], 400);
    }
}
