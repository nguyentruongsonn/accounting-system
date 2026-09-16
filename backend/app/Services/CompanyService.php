<?php

namespace App\Services;

use App\Models\Company;

class CompanyService
{
    public function getFirstOrCreate(): Company
    {
        $company = Company::first();
        if (!$company) {
            $company = Company::create([
                'code' => 'COMP01',
                'name' => 'Công ty TNHH Phần mềm Mới',
                'tax_code' => '0123456789',
                'address' => 'Hà Nội',
                'phone' => '024 1234 5678',
                'email' => 'contact@company.com'
            ]);
        }
        return $company;
    }

    public function update(int $id, array $data): Company
    {
        $company = Company::findOrFail($id);
        $company->update($data);
        return $company;
    }
}
