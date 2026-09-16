<?php

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Models\Customer;
use App\Services\CustomerService;
use App\Services\MasterDataAuditService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    protected CustomerService $service;

    public function __construct(CustomerService $service, private readonly MasterDataAuditService $masterAudit)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $customers = $this->service->paginate(20, $companyId);

        return response()->json($customers);
    }

    public function store(StoreCustomerRequest $request)
    {
        $data = $request->validated();
        $data['company_id'] = TenantContext::companyId($request);
        $customer = $this->masterAudit->create(new Customer, $data, 'customer.created');

        return response()->json($customer, 201);
    }

    public function show(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        $customer = $this->service->getById((int) $id, $companyId);

        return response()->json($customer);
    }

    public function update(StoreCustomerRequest $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        $data = $request->validated();
        unset($data['company_id']);
        $customer = $this->masterAudit->update($this->service->getById((int) $id, $companyId), $data, 'customer.updated');

        return response()->json($customer);
    }

    public function destroy(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        $this->masterAudit->delete($this->service->getById((int) $id, $companyId), 'customer.deleted');

        return response()->json(null, 204);
    }
}
