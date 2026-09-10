<?php

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Models\VoucherTypeSetting;
use App\Services\MasterDataAuditService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VoucherTypeSettingController extends Controller
{
    public function __construct(private readonly MasterDataAuditService $masterAudit) {}

    /**
     * GET /api/v1/master/voucher-type-settings
     * Lấy danh sách tài khoản ngầm định, tùy chọn filter theo voucher_type
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $voucherType = $request->get('voucher_type');
        // Operational voucher forms consume active settings only. The internal
        // catalogue editor may explicitly request inactive rows so an owner
        // can review/reactivate an existing setting instead of losing it from
        // the management surface.
        $includeInactive = filter_var($request->get('include_inactive', false), FILTER_VALIDATE_BOOLEAN);

        $query = VoucherTypeSetting::byCompany($companyId);
        if (! $includeInactive) {
            $query->active();
        }

        if ($voucherType) {
            $query->byVoucherType($voucherType);
        }

        $settings = $query->orderBy('created_at', 'asc')->get();

        // Nếu không có data custom, trả về system defaults theo voucher_type
        if ($settings->isEmpty() && $voucherType && isset(VoucherTypeSetting::$SYSTEM_DEFAULTS[$voucherType])) {
            $defaults = collect(VoucherTypeSetting::$SYSTEM_DEFAULTS[$voucherType])->map(function ($item) use ($voucherType) {
                return array_merge($item, [
                    'id' => null,
                    'voucher_type' => $voucherType,
                    'is_system' => true,
                    'is_active' => true,
                ]);
            });

            return response()->json([
                'data' => $defaults,
                'meta' => ['total' => $defaults->count(), 'is_system_defaults' => true],
                'voucher_types' => VoucherTypeSetting::$VOUCHER_TYPES,
            ]);
        }

        return response()->json([
            'data' => $settings,
            'meta' => ['total' => $settings->count()],
            'voucher_types' => VoucherTypeSetting::$VOUCHER_TYPES,
        ]);
    }

    /**
     * POST /api/v1/master/voucher-type-settings
     * Tạo mới tài khoản ngầm định
     */
    public function store(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $accountRule = fn () => Rule::exists('chart_of_accounts', 'code')
            ->where(fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('is_parent', false)
                ->whereNull('deleted_at'));
        $validated = $request->validate([
            'voucher_type' => ['required', 'in:thu_tien_mat,thu_tien_gui,chi_tien_mat,chi_tien_gui,chung_tu_khac'],
            'name' => ['required', 'string', 'max:255'],
            'debit_account' => ['nullable', 'string', 'max:20', $accountRule()],
            'credit_account' => ['nullable', 'string', 'max:20', $accountRule()],
            'filter_debit' => ['nullable', 'string', 'max:100'],
            'filter_credit' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        $validated['company_id'] = $companyId;
        $validated['created_by'] = auth()->id();

        $setting = $this->masterAudit->create(new VoucherTypeSetting, $validated, 'voucher_type_setting.created');

        return response()->json(['data' => $setting, 'message' => 'Tạo thành công'], 201);
    }

    /**
     * GET /api/v1/master/voucher-type-settings/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $setting = VoucherTypeSetting::where('company_id', $companyId)->findOrFail($id);

        return response()->json(['data' => $setting]);
    }

    /**
     * PUT /api/v1/master/voucher-type-settings/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $setting = VoucherTypeSetting::where('company_id', $companyId)->findOrFail($id);
        $accountRule = fn () => Rule::exists('chart_of_accounts', 'code')
            ->where(fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('is_parent', false)
                ->whereNull('deleted_at'));

        $validated = $request->validate([
            'voucher_type' => ['sometimes', 'in:thu_tien_mat,thu_tien_gui,chi_tien_mat,chi_tien_gui,chung_tu_khac'],
            'name' => ['sometimes', 'string', 'max:255'],
            'debit_account' => ['nullable', 'string', 'max:20', $accountRule()],
            'credit_account' => ['nullable', 'string', 'max:20', $accountRule()],
            'filter_debit' => ['nullable', 'string', 'max:100'],
            'filter_credit' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        $validated['updated_by'] = auth()->id();
        $setting = $this->masterAudit->update($setting, $validated, 'voucher_type_setting.updated');

        return response()->json(['data' => $setting, 'message' => 'Cập nhật thành công']);
    }

    /**
     * DELETE /api/v1/master/voucher-type-settings/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $setting = VoucherTypeSetting::where('company_id', $companyId)->findOrFail($id);
        $this->masterAudit->delete($setting, 'voucher_type_setting.deleted');

        return response()->json(['message' => 'Xóa thành công']);
    }

    /**
     * GET /api/v1/master/voucher-type-settings/types
     * Trả về danh sách Loại chứng từ (5 loại chuẩn MISA AMIS)
     */
    public function types(Request $request): JsonResponse
    {
        TenantContext::companyId($request);

        return response()->json([
            'data' => collect(VoucherTypeSetting::$VOUCHER_TYPES)->map(function ($label, $value) {
                return ['value' => $value, 'label' => $label];
            })->values(),
        ]);
    }
}
