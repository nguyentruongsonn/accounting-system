<?php

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Models\PaymentTerm;
use App\Services\MasterDataAuditService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentTermController extends Controller
{
    public function __construct(private readonly MasterDataAuditService $masterAudit) {}

    public function index(Request $request)
    {
        TenantContext::companyId($request);

        return response()->json(PaymentTerm::where('is_active', true)->orderBy('due_days')->get());
    }

    public function store(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $request->validate([
            'code' => ['required', Rule::unique('payment_terms', 'code')->where(fn ($query) => $query->where('company_id', $companyId))],
            'name' => 'required',
            'due_days' => 'required|integer',
        ]);

        $term = $this->masterAudit->create(new PaymentTerm, [
            'company_id' => $companyId,
            'code' => $request->code,
            'name' => $request->name,
            'due_days' => $request->due_days,
            'discount_days' => $request->discount_days ?? 0,
            'discount_rate' => $request->discount_rate ?? 0,
            'description' => $request->description,
            'is_active' => true,
        ], 'payment_term.created');

        return response()->json($term, 201);
    }

    public function show(Request $request, int $id)
    {
        $companyId = TenantContext::companyId($request);

        return response()->json(PaymentTerm::where('company_id', $companyId)->findOrFail($id));
    }

    public function update(Request $request, int $id)
    {
        $companyId = TenantContext::companyId($request);
        $term = PaymentTerm::where('company_id', $companyId)->findOrFail($id);
        $data = $request->validate([
            'code' => ['sometimes', 'string', Rule::unique('payment_terms', 'code')->where(fn ($query) => $query->where('company_id', $companyId))->ignore($id)],
            'name' => 'sometimes|string',
            'due_days' => 'sometimes|integer',
            'discount_days' => 'nullable|integer',
            'discount_rate' => 'nullable|numeric',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);
        $term = $this->masterAudit->update($term, $data, 'payment_term.updated');

        return response()->json($term);
    }

    public function destroy(Request $request, int $id)
    {
        $companyId = TenantContext::companyId($request);
        $this->masterAudit->delete(PaymentTerm::where('company_id', $companyId)->findOrFail($id), 'payment_term.deleted');

        return response()->json(null, 204);
    }
}
