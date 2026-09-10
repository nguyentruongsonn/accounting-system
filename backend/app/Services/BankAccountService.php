<?php

namespace App\Services;

use App\Models\BankAccount;
use Illuminate\Validation\ValidationException;

class BankAccountService
{
    public function getByCompany(int $companyId)
    {
        $companyId = $this->requireCompanyId($companyId);

        return BankAccount::where('company_id', $companyId)->get();
    }

    public function getById(int $id, ?int $companyId = null): BankAccount
    {
        $companyId = $this->requireCompanyId($companyId);

        return BankAccount::where('company_id', $companyId)->findOrFail($id);
    }

    public function create(array $data): BankAccount
    {
        $data['company_id'] = $this->requireCompanyId($data['company_id'] ?? null);

        return BankAccount::create($data);
    }

    public function update(int $id, array $data, ?int $companyId = null): BankAccount
    {
        $companyId = $this->requireCompanyId($companyId ?? ($data['company_id'] ?? null));
        if (isset($data['company_id']) && (int) $data['company_id'] !== $companyId) {
            throw ValidationException::withMessages(['company_id' => 'The requested company does not belong to the authenticated user.']);
        }
        unset($data['company_id']);
        $account = BankAccount::where('company_id', $companyId)->findOrFail($id);
        $account->update($data);

        return $account;
    }

    public function delete(int $id, ?int $companyId = null): void
    {
        $companyId = $this->requireCompanyId($companyId);
        $account = BankAccount::where('company_id', $companyId)->findOrFail($id);
        $account->delete();
    }

    private function requireCompanyId(?int $companyId): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        $resolvedCompanyId = $companyId ?? $actorCompanyId;

        if ($resolvedCompanyId === null || $resolvedCompanyId <= 0) {
            throw ValidationException::withMessages([
                'company_id' => 'An authenticated company context is required.',
            ]);
        }
        if ($actorCompanyId !== null && (int) $actorCompanyId !== (int) $resolvedCompanyId) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return (int) $resolvedCompanyId;
    }
}
