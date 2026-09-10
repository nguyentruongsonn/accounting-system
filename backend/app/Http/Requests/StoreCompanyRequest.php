<?php

namespace App\Http\Requests;

class StoreCompanyRequest extends TenantAccountingRequest
{
    public function rules()
    {
        return [
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:50',
            'tax_code' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'website' => 'nullable|string|max:255',
            'director_name' => 'nullable|string|max:255',
            'chief_accountant_name' => 'nullable|string|max:255',
        ];
    }
}
