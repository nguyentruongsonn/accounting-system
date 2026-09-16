<?php

namespace App\Services;

use App\Models\Item;

class ItemService
{
    public function getByCompany(int $companyId)
    {
        $companyId = $this->requireCompanyId($companyId);

        return Item::where('company_id', $companyId)->get();
    }

    public function create(array $data): Item
    {
        $data['company_id'] = $this->requireCompanyId($data['company_id'] ?? null);

        return Item::create($data);
    }

    public function getById(int $id, ?int $companyId = null): Item
    {
        $companyId = $this->requireCompanyId($companyId);

        return Item::where('company_id', $companyId)->findOrFail($id);
    }

    public function update(int $id, array $data, ?int $companyId = null): Item
    {
        $companyId = $this->requireCompanyId($companyId ?? ($data['company_id'] ?? null));
        if (isset($data['company_id']) && (int) $data['company_id'] !== $companyId) {
            throw \Illuminate\Validation\ValidationException::withMessages(['company_id' => 'The requested company does not belong to the authenticated user.']);
        }
        unset($data['company_id']);
        $item = Item::where('company_id', $companyId)->findOrFail($id);
        $item->update($data);

        return $item;
    }

    public function delete(int $id, ?int $companyId = null): void
    {
        $companyId = $this->requireCompanyId($companyId);
        Item::where('company_id', $companyId)->findOrFail($id)->delete();
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
