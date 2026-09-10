<?php

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyRequest;
use App\Models\Company;
use App\Services\CompanyService;
use App\Services\MasterDataAuditService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    protected CompanyService $service;

    public function __construct(CompanyService $service, private readonly MasterDataAuditService $masterAudit)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $company = Company::findOrFail(TenantContext::companyId($request));

        return response()->json($company);
    }

    public function update(StoreCompanyRequest $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        abort_unless((int) $id === $companyId, 404);
        $company = $this->masterAudit->update(Company::findOrFail((int) $id), $request->validated(), 'company.settings_updated');

        return response()->json([
            'message' => 'Cập nhật thông tin công ty thành công',
            'data' => $company,
        ]);
    }
}
