<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreInventoryStockCountRequest extends TenantAccountingRequest
{
    public function rules(): array
    {
        $required = $this->requiredForCreate();

        return [
            'company_id' => ['required', 'integer', Rule::in([$this->companyId()])],
            'count_number' => [$required, 'string', 'max:50'],
            'count_date' => [$required, 'date'],
            'warehouse_id' => [$required, 'integer', $this->tenantExists('warehouses')],
            'description' => ['nullable', 'string'],
            'lines' => [$required, 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', $this->tenantExists('items')],
            'lines.*.unit' => ['nullable', 'string', 'max:100'],
            'lines.*.counted_quantity' => ['required', 'numeric', 'gte:0'],
            'lines.*.description' => ['nullable', 'string'],
        ];
    }
}
