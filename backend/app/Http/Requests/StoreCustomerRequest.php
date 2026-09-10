<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreCustomerRequest extends TenantAccountingRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->merge([
            'is_active' => $this->input('is_active', true),
        ]);
    }

    public function rules()
    {
        $id = $this->route('customer') ?? $this->route('id');
        $required = $this->requiredForCreate();

        return [
            'company_id' => ['required', 'integer', Rule::in([$this->companyId()])],
            'code' => [$required, 'string', Rule::unique('customers', 'code')->where(fn ($query) => $query->where('company_id', $this->companyId()))->ignore($id)],
            'name' => [$required, 'string'],
            'customer_type' => 'nullable|string|in:org,personal',
            'is_customer' => 'nullable|boolean',
            'is_supplier' => 'nullable|boolean',
            'is_employee' => 'nullable|boolean',
            'is_internal' => 'nullable|boolean',
            'tax_code' => 'nullable|string',
            'dvqhns_code' => 'nullable|string',
            'identity_card_number' => 'nullable|string',
            'identity_card_date' => 'nullable|date',
            'identity_card_place' => 'nullable|string',
            'passport_number' => 'nullable|string',
            'gender' => 'nullable|string',
            'birth_date' => 'nullable|date',
            'address' => 'nullable|string',
            'country' => 'nullable|string',
            'province' => 'nullable|string',
            'district' => 'nullable|string',
            'ward' => 'nullable|string',
            'same_as_main_address' => 'nullable|boolean',
            'phone' => 'nullable|string',
            'mobile_phone' => 'nullable|string',
            'landline_phone' => 'nullable|string',
            'email' => 'nullable|string',
            'contact_group' => 'nullable|string',
            'assigned_employee_id' => ['nullable', $this->tenantExistsByIdOrCode('employees', true)],
            'website' => 'nullable|string',
            'legal_representative' => 'nullable|string',
            'contact_person_salutation' => 'nullable|string',
            'contact_person_name' => 'nullable|string',
            'contact_person_title' => 'nullable|string',
            'contact_person_phone' => 'nullable|string',
            'contact_person_email' => 'nullable|string',
            'einvoice_contact_name' => 'nullable|string',
            'einvoice_contact_email' => 'nullable|string',
            'einvoice_contact_phone' => 'nullable|string',
            'default_account' => ['nullable', 'string', $this->tenantAccountExists()],
            'payment_term' => ['nullable', 'string', $this->tenantExists('payment_terms', 'code')],
            'due_days' => 'nullable|integer',
            'debt_limit' => 'nullable|numeric',
            'debt_account' => ['nullable', 'string', $this->tenantAccountExists()],
            'bank_accounts' => 'nullable|array',
            'delivery_addresses' => 'nullable|array',
            'note' => 'nullable|string',
            'custom_field_1' => 'nullable|string',
            'custom_field_2' => 'nullable|string',
            'custom_field_3' => 'nullable|string',
            'custom_field_4' => 'nullable|string',
            'custom_field_5' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ];
    }
}
