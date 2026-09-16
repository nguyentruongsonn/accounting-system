<?php

namespace App\Http\Controllers\Api\V1\Bank;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBankAccountRequest;
use App\Models\BankAccount;
use App\Services\BankAccountService;
use App\Services\MasterDataAuditService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class BankAccountController extends Controller
{
    protected BankAccountService $service;

    public function __construct(BankAccountService $service, private readonly MasterDataAuditService $masterAudit)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $accounts = $this->service->getByCompany($companyId);

        return response()->json($accounts);
    }

    public function store(StoreBankAccountRequest $request)
    {
        $data = $request->validated();
        $data['company_id'] = TenantContext::companyId($request);
        $account = $this->masterAudit->create(new BankAccount, $data, 'bank_account.created');

        return response()->json($account, 201);
    }

    public function show(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        $account = $this->service->getById((int) $id, $companyId);

        return response()->json($account);
    }

    public function update(StoreBankAccountRequest $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        $validated = $request->validated();
        unset($validated['company_id']);

        $account = $this->masterAudit->update($this->service->getById((int) $id, $companyId), $validated, 'bank_account.updated');

        return response()->json($account);
    }

    public function destroy(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        $this->masterAudit->delete($this->service->getById((int) $id, $companyId), 'bank_account.deleted');

        return response()->json(null, 204);
    }
}
