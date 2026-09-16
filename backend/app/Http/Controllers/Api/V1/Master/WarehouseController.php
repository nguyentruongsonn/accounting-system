<?php

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Services\MasterDataAuditService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    public function __construct(private readonly MasterDataAuditService $masterAudit) {}

    public function index(Request $request)
    {
        $companyId = TenantContext::companyId($request);

        return response()->json(Warehouse::where('company_id', $companyId)->where('is_active', true)->orderBy('code')->get());
    }

    public function store(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $request->validate([
            'code' => ['required', Rule::unique('warehouses', 'code')->where(fn ($query) => $query->where('company_id', $companyId))],
            'name' => 'required',
            'default_account' => ['nullable', Rule::exists('chart_of_accounts', 'code')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'))],
        ]);

        $warehouse = $this->masterAudit->create(new Warehouse, [
            'company_id' => $companyId,
            'code' => $request->code,
            'name' => $request->name,
            'default_account' => $request->default_account ?? '156',
            'address' => $request->address,
            'manager_name' => $request->manager_name,
            'description' => $request->description,
            'is_active' => true,
        ], 'warehouse.created');

        return response()->json($warehouse, 201);
    }

    public function show(Request $request, int $id)
    {
        TenantContext::companyId($request);

        return response()->json(Warehouse::findOrFail($id));
    }

    public function update(Request $request, int $id)
    {
        $companyId = TenantContext::companyId($request);
        $warehouse = Warehouse::findOrFail($id);
        $data = $request->validate([
            'code' => ['sometimes', 'string', Rule::unique('warehouses', 'code')->where(fn ($query) => $query->where('company_id', $companyId))->ignore($id)],
            'name' => 'sometimes|string',
            'default_account' => ['nullable', Rule::exists('chart_of_accounts', 'code')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'))],
            'address' => 'nullable|string',
            'manager_name' => 'nullable|string',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);
        $warehouse = $this->masterAudit->update($warehouse, $data, 'warehouse.updated');

        return response()->json($warehouse);
    }

    public function destroy(Request $request, int $id)
    {
        TenantContext::companyId($request);
        $this->masterAudit->delete(Warehouse::findOrFail($id), 'warehouse.deleted');

        return response()->json(null, 204);
    }
}
