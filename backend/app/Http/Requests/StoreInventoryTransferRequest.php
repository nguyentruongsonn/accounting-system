<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreInventoryTransferRequest extends TenantAccountingRequest
{
    public function rules(): array
    {
        $required = $this->requiredForCreate();

        return [
            'company_id' => ['required', 'integer', Rule::in([$this->companyId()])],
            'transfer_number' => [$required, 'string', 'max:50'],
            'transfer_date' => [$required, 'date'],
            'from_warehouse_id' => [$required, 'integer', $this->tenantExists('warehouses')],
            'to_warehouse_id' => [$required, 'integer', $this->tenantExists('warehouses')],
            'description' => ['nullable', 'string'],
            'lines' => [$required, 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', $this->tenantExists('items')],
            'lines.*.unit' => ['nullable', 'string', 'max:100'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.description' => ['nullable', 'string'],
        ];
    }
}
