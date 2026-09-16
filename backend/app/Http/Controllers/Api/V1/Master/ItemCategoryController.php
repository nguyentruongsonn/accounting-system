<?php

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Models\ItemCategory;
use App\Services\MasterDataAuditService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemCategoryController extends Controller
{
    public function __construct(private readonly MasterDataAuditService $masterAudit) {}

    public function index(Request $request)
    {
        $companyId = TenantContext::companyId($request);

        return response()->json(ItemCategory::where('company_id', $companyId)->where('is_active', true)->orderBy('code')->get());
    }

    public function store(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $request->validate([
            'code' => ['required', Rule::unique('item_categories', 'code')->where(fn ($query) => $query->where('company_id', $companyId))],
            'name' => 'required',
            'parent_code' => ['nullable', Rule::exists('item_categories', 'code')->where(fn ($query) => $query->where('company_id', $companyId))],
        ]);

        $category = $this->masterAudit->create(new ItemCategory, [
            'company_id' => $companyId,
            'code' => $request->code,
            'name' => $request->name,
            'parent_code' => $request->parent_code,
            'description' => $request->description,
            'is_active' => true,
        ], 'item_category.created');

        return response()->json($category, 201);
    }

    public function show(Request $request, int $id)
    {
        $companyId = TenantContext::companyId($request);

        return response()->json(ItemCategory::where('company_id', $companyId)->findOrFail($id));
    }

    public function update(Request $request, int $id)
    {
        $companyId = TenantContext::companyId($request);
        $category = ItemCategory::where('company_id', $companyId)->findOrFail($id);
        $data = $request->validate([
            'code' => ['sometimes', 'string', Rule::unique('item_categories', 'code')->where(fn ($query) => $query->where('company_id', $companyId))->ignore($id)],
            'name' => 'sometimes|string',
            'parent_code' => ['nullable', Rule::exists('item_categories', 'code')->where(fn ($query) => $query->where('company_id', $companyId))],
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);
        $category = $this->masterAudit->update($category, $data, 'item_category.updated');

        return response()->json($category);
    }

    public function destroy(Request $request, int $id)
    {
        $companyId = TenantContext::companyId($request);
        $this->masterAudit->delete(ItemCategory::where('company_id', $companyId)->findOrFail($id), 'item_category.deleted');

        return response()->json(null, 204);
    }
}
