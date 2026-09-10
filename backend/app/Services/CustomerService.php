<?php

namespace App\Services;

use App\Models\Customer;

class CustomerService
{
    public function paginate($perPage = 20, ?int $companyId = null)
    {
        $companyId = $this->requireCompanyId($companyId);

        return Customer::where('company_id', $companyId)->orderBy('code')->paginate($perPage);
    }

    public function getById(int $id, ?int $companyId = null): Customer
    {
        $companyId = $this->requireCompanyId($companyId);

        return Customer::where('company_id', $companyId)->findOrFail($id);
    }

    public function create(array $data): Customer
    {
        $data['company_id'] = $this->requireCompanyId($data['company_id'] ?? null);

        return Customer::create($data);
    }

    public function update(int $id, array $data, ?int $companyId = null): Customer
    {
        $companyId = $this->requireCompanyId($companyId ?? ($data['company_id'] ?? null));
        if (isset($data['company_id']) && (int) $data['company_id'] !== $companyId) {
            throw \Illuminate\Validation\ValidationException::withMessages(['company_id' => 'The requested company does not belong to the authenticated user.']);
        }
        unset($data['company_id']);
        $customer = Customer::where('company_id', $companyId)->findOrFail($id);
        $customer->update($data);
        return $customer;
    }

    public function delete(int $id, ?int $companyId = null): void
    {
        $companyId = $this->requireCompanyId($companyId);
        $customer = Customer::where('company_id', $companyId)->findOrFail($id);
        $customer->delete();
    }

    private function requireCompanyId(?int $companyId): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        $resolvedCompanyId = $companyId ?? $actorCompanyId;

        if ($resolvedCompanyId === null || $resolvedCompanyId <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'company_id' => 'An authenticated company context is required.',
            ]);
        }
        if ($actorCompanyId !== null && (int) $actorCompanyId !== (int) $resolvedCompanyId) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return (int) $resolvedCompanyId;
    }
}
