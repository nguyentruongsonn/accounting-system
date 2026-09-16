<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreItemRequest extends TenantAccountingRequest
{
    public function rules()
    {
        $required = $this->requiredForCreate();
        $itemId = $this->route('item');

        return [
            'company_id' => ['required', 'integer', Rule::in([$this->companyId()])],
            'type' => 'nullable|string',
            'item_type' => 'nullable|string',
            'code' => [
                $required,
                'string',
                Rule::unique('items', 'code')->where(fn ($query) => $query->where('company_id', $this->companyId()))->ignore($itemId),
            ],
            'name' => [$required, 'string'],
            'category_code' => ['nullable', 'string', $this->tenantExists('item_categories', 'code')],
            'category_name' => 'nullable|string',
            'unit' => 'nullable|string',
            'image_url' => 'nullable|string',
            'warranty_period' => 'nullable|integer',
            'warranty_unit' => 'nullable|string',
            'warehouse_location' => 'nullable|string',
            'minimum_stock' => 'nullable|numeric',
            'origin' => 'nullable|string',
            'description' => 'nullable|string',
            'purchase_description' => 'nullable|string',
            'sale_description' => 'nullable|string',
            'special_feature_type' => 'nullable|string',
            'default_warehouse' => ['nullable', 'string', $this->tenantExists('warehouses', 'code')],
            'inventory_account' => ['nullable', 'string', $this->tenantAccountExists()],
            'revenue_account' => ['nullable', 'string', $this->tenantAccountExists()],
            'discount_account' => ['nullable', 'string', $this->tenantAccountExists()],
            'rebate_account' => ['nullable', 'string', $this->tenantAccountExists()],
            'return_account' => ['nullable', 'string', $this->tenantAccountExists()],
            'cost_account' => ['nullable', 'string', $this->tenantAccountExists()],
            'purchase_discount_rate' => 'nullable|numeric',
            'fixed_purchase_price' => 'nullable|numeric',
            'latest_purchase_price' => 'nullable|numeric',
            'cost_price' => 'nullable|numeric',
            'selling_price' => 'nullable|numeric',
            'sale_price' => 'nullable|numeric',
            'vat_rate' => 'nullable',
            'import_tax_rate' => 'nullable|numeric',
            'export_tax_rate' => 'nullable|numeric',
            'unit_conversions' => 'nullable|array',
            'tier_discounts' => 'nullable|array',
            'combo_details' => 'nullable|array',
            'quantity_formula' => 'nullable|string',
            'custom_fields' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ];
    }
}
