<?php

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\MasterDataAuditService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function __construct(private readonly MasterDataAuditService $masterAudit) {}

    public function index(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $employees = Employee::where('company_id', $companyId)->orderBy('code')->get();

        return response()->json(['data' => $employees]);
    }

    public function store(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $request->merge([
            'company_id' => $companyId,
            'status' => $request->input('status', 'active'),
        ]);

        $validated = $request->validate([
            'company_id' => ['required', 'integer', Rule::in([$companyId])],
            'code' => ['required', 'string', 'max:50', Rule::unique('employees', 'code')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'))],
            'name' => 'required|string|max:255',
            'is_customer' => 'nullable|boolean',
            'is_supplier' => 'nullable|boolean',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            'gender' => 'nullable|string|max:20',
            'birth_date' => 'nullable|date',
            'id_card_number' => 'nullable|string|max:50',
            'id_card_date' => 'nullable|date',
            'id_card_place' => 'nullable|string|max:255',
            'passport_number' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'email' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'landline_phone' => 'nullable|string|max:50',
            'account_email' => 'nullable|string|max:255',
            'account_phone' => 'nullable|string|max:50',
            'base_salary' => 'nullable|numeric',
            'contract_salary' => 'nullable|numeric',
            'salary_coefficient' => 'nullable|numeric',
            'insurance_salary' => 'nullable|numeric',
            'tax_code' => 'nullable|string|max:50',
            'contract_type' => 'nullable|string|max:255',
            'dependents_count' => 'nullable|integer',
            'personal_deduction' => 'nullable|numeric',
            'bank_accounts' => 'nullable|array',
            'dependents' => 'nullable|array',
            'status' => 'required|string',
        ]);

        $employee = $this->masterAudit->create(new Employee, $validated, 'employee.created');

        return response()->json(['data' => $employee], 201);
    }

    public function show(Request $request, $id)
    {
        TenantContext::companyId($request);
        $employee = Employee::findOrFail($id);

        return response()->json(['data' => $employee]);
    }

    public function update(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        $employee = Employee::findOrFail($id);

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('employees', 'code')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'))->ignore($id)],
            'name' => 'sometimes|string|max:255',
            'is_customer' => 'nullable|boolean',
            'is_supplier' => 'nullable|boolean',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            'gender' => 'nullable|string|max:20',
            'birth_date' => 'nullable|date',
            'id_card_number' => 'nullable|string|max:50',
            'id_card_date' => 'nullable|date',
            'id_card_place' => 'nullable|string|max:255',
            'passport_number' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'email' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'landline_phone' => 'nullable|string|max:50',
            'account_email' => 'nullable|string|max:255',
            'account_phone' => 'nullable|string|max:50',
            'base_salary' => 'nullable|numeric',
            'contract_salary' => 'nullable|numeric',
            'salary_coefficient' => 'nullable|numeric',
            'insurance_salary' => 'nullable|numeric',
            'tax_code' => 'nullable|string|max:50',
            'contract_type' => 'nullable|string|max:255',
            'dependents_count' => 'nullable|integer',
            'personal_deduction' => 'nullable|numeric',
            'bank_accounts' => 'nullable|array',
            'dependents' => 'nullable|array',
            'status' => 'sometimes|string',
        ]);

        $employee = $this->masterAudit->update($employee, $validated, 'employee.updated');

        return response()->json(['data' => $employee]);
    }

    public function destroy(Request $request, $id)
    {
        TenantContext::companyId($request);
        $employee = Employee::findOrFail($id);
        $this->masterAudit->delete($employee, 'employee.deleted');

        return response()->json(['message' => 'Employee deleted successfully']);
    }
}
