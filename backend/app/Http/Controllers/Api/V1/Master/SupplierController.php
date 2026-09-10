<?php

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Models\Supplier;
use App\Services\MasterDataAuditService;
use App\Services\SupplierService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    protected SupplierService $service;

    public function __construct(SupplierService $service, private readonly MasterDataAuditService $masterAudit)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $suppliers = $this->service->paginate(20, $companyId);

        return response()->json($suppliers);
    }

    public function store(StoreSupplierRequest $request)
    {
        $data = $request->validated();
        $data['company_id'] = TenantContext::companyId($request);
        $supplier = $this->masterAudit->create(new Supplier, $data, 'supplier.created');

        return response()->json($supplier, 201);
    }

    public function show(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        $supplier = $this->service->getById((int) $id, $companyId);

        return response()->json($supplier);
    }

    public function update(StoreSupplierRequest $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        $data = $request->validated();
        unset($data['company_id']);
        $supplier = $this->masterAudit->update($this->service->getById((int) $id, $companyId), $data, 'supplier.updated');

        return response()->json($supplier);
    }

    public function destroy(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        $this->masterAudit->delete($this->service->getById((int) $id, $companyId), 'supplier.deleted');

        return response()->json(null, 204);
    }
}
