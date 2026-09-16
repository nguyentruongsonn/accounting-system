<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreItemRequest;
use App\Models\Item;
use App\Services\ItemService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    protected ItemService $service;

    public function __construct(ItemService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $items = Item::where('company_id', $companyId)->orderBy('id', 'desc')->get();

        return response()->json($items);
    }

    public function store(StoreItemRequest $request)
    {
        $data = $request->validated();
        $data['company_id'] = TenantContext::companyId($request);
        if (empty($data['type'])) {
            $data['type'] = $data['item_type'] ?? 'goods';
        }
        $item = $this->service->create($data);

        return response()->json($item, 201);
    }

    public function show(Request $request, int $id)
    {
        $companyId = TenantContext::companyId($request);

        return response()->json($this->service->getById($id, $companyId));
    }

    public function update(StoreItemRequest $request, int $id)
    {
        $companyId = TenantContext::companyId($request);
        $data = $request->validated();
        unset($data['company_id']);

        return response()->json($this->service->update($id, $data, $companyId));
    }

    public function destroy(Request $request, int $id)
    {
        $companyId = TenantContext::companyId($request);
        $this->service->delete($id, $companyId);

        return response()->json(null, 204);
    }

    public function nextCode(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $count = Item::where('company_id', $companyId)->count() + 1;
        $code = 'VT'.str_pad($count, 5, '0', STR_PAD_LEFT);

        return response()->json([
            'code' => $code,
            'next_code' => $code,
            'data' => ['code' => $code, 'next_code' => $code],
        ]);
    }
}
