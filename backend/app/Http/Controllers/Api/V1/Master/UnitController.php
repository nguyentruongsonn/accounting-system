<?php

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Services\MasterDataAuditService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function __construct(private readonly MasterDataAuditService $masterAudit) {}

    public function index(Request $request)
    {
        TenantContext::companyId($request);

        return response()->json(Unit::where('is_active', true)->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $request->validate([
            'name' => 'required',
            'code' => ['nullable', Rule::unique('units', 'code')->where(fn ($query) => $query->where('company_id', $companyId))],
        ]);

        $code = $request->code ?: $request->name;

        $existing = Unit::where('company_id', $companyId)->where('code', $code)->first();
        $unit = $existing ?? $this->masterAudit->create(new Unit, [
            'company_id' => $companyId,
            'code' => $code,
            'name' => $request->name,
            'description' => $request->description,
            'is_active' => true,
        ], 'unit.created');

        return response()->json($unit, 201);
    }

    public function show(Request $request, int $id)
    {
        TenantContext::companyId($request);

        return response()->json(Unit::findOrFail($id));
    }

    public function update(Request $request, int $id)
    {
        $companyId = TenantContext::companyId($request);
        $unit = Unit::findOrFail($id);
        $data = $request->validate([
            'code' => ['sometimes', 'string', Rule::unique('units', 'code')->where(fn ($query) => $query->where('company_id', $companyId))->ignore($id)],
            'name' => 'sometimes|string',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);
        $unit = $this->masterAudit->update($unit, $data, 'unit.updated');

        return response()->json($unit);
    }

    public function destroy(Request $request, int $id)
    {
        TenantContext::companyId($request);
        $this->masterAudit->delete(Unit::findOrFail($id), 'unit.deleted');

        return response()->json(null, 204);
    }
}
