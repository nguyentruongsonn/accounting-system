<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OpeningBalancePackage;
use App\Services\OpeningBalanceService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class OpeningBalanceController extends Controller
{
    public function __construct(private readonly OpeningBalanceService $service) {}

    public function index(Request $request)
    {
        $date = $request->query('effective_date');
        if ($date !== null) {
            $request->validate(['effective_date' => ['date_format:Y-m-d']]);
        }

        return response()->json(['data' => $this->service->list(TenantContext::companyId($request), $date)]);
    }

    public function show(Request $request, int $id)
    {
        $package = OpeningBalancePackage::withoutGlobalScope('company')
            ->where('company_id', TenantContext::companyId($request))
            ->with(['accountLines', 'partyLines', 'inventoryLines'])->findOrFail($id);

        return response()->json(['data' => $package]);
    }

    public function store(Request $request)
    {
        $package = $this->service->create(
            TenantContext::companyId($request), (int) $request->user()->id, $this->validated($request, true),
        );

        return response()->json(['data' => $package], 201);
    }

    public function update(Request $request, int $id)
    {
        $package = $this->service->update(
            TenantContext::companyId($request), $id, $this->validated($request, false),
        );

        return response()->json(['data' => $package]);
    }

    public function confirm(Request $request, int $id)
    {
        $package = $this->service->confirm(
            TenantContext::companyId($request), $id, (int) $request->user()->id,
        );

        return response()->json(['data' => $package]);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $money = ['required', 'regex:/^(?:0|[1-9]\d{0,15})(?:\.\d{1,2})?$/'];

        return $request->validate([
            'effective_date' => [$creating ? 'required' : 'sometimes', 'date'],
            'account_lines' => ['required', 'array', 'min:1'],
            'account_lines.*.account_code' => ['required', 'string', 'max:20', 'distinct'],
            'account_lines.*.debit_amount' => $money,
            'account_lines.*.credit_amount' => $money,
            'party_lines' => ['sometimes', 'array'],
            'party_lines.*.party_type' => ['required', Rule::in(['customer', 'supplier'])],
            'party_lines.*.party_id' => ['required', 'integer', 'min:1'],
            'party_lines.*.account_code' => ['required', 'string', 'max:20'],
            'party_lines.*.document_number' => ['nullable', 'string', 'max:100'],
            'party_lines.*.due_date' => ['nullable', 'date'],
            'party_lines.*.debit_amount' => $money,
            'party_lines.*.credit_amount' => $money,
            'inventory_lines' => ['sometimes', 'array'],
            'inventory_lines.*.item_id' => ['required', 'integer', 'min:1'],
            'inventory_lines.*.warehouse_id' => ['required', 'integer', 'min:1'],
            'inventory_lines.*.account_code' => ['required', 'string', 'max:20'],
            'inventory_lines.*.quantity' => ['required', 'regex:/^(?:0|[1-9]\d{0,13})(?:\.\d{1,4})?$/', 'not_in:0,0.0,0.00,0.000,0.0000'],
            'inventory_lines.*.unit_cost' => ['required', 'regex:/^(?:0|[1-9]\d{0,13})(?:\.\d{1,4})?$/', 'not_in:0,0.0,0.00,0.000,0.0000'],
            'inventory_lines.*.total_value' => $money,
        ]);
    }
}
