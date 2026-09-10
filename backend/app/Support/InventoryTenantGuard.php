<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryTenantGuard
{
    public static function companyId(array $data): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        $resolvedCompanyId = $data['company_id'] ?? $actorCompanyId;
        if ($resolvedCompanyId === null || (int) $resolvedCompanyId <= 0) {
            throw ValidationException::withMessages([
                'company_id' => 'An authenticated company context is required.',
            ]);
        }
        if ($actorCompanyId !== null && (int) $resolvedCompanyId !== (int) $actorCompanyId) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return (int) $resolvedCompanyId;
    }

    public static function assertDocumentReferences(array $data, int $companyId): void
    {
        self::assertContact(
            $data['contact_id'] ?? null,
            $data['contact_type'] ?? null,
            $companyId,
            'contact_id',
        );
        self::assertOwned('warehouses', $data['warehouse_id'] ?? null, $companyId, 'warehouse_id');
        self::assertOwned('employees', $data['employee_id'] ?? null, $companyId, 'employee_id', true);

        foreach ($data['lines'] ?? [] as $index => $line) {
            self::assertOwned('items', $line['item_id'] ?? null, $companyId, "lines.$index.item_id", false, true);
            self::assertOwned('warehouses', $line['warehouse_id'] ?? null, $companyId, "lines.$index.warehouse_id");
            self::assertOwnedByCode('warehouses', $line['warehouse_code'] ?? null, $companyId, "lines.$index.warehouse_code");
            self::assertAccount($line['debit_account'] ?? null, $companyId, "lines.$index.debit_account");
            self::assertAccount($line['credit_account'] ?? null, $companyId, "lines.$index.credit_account");
        }
    }

    /**
     * Inventory documents persist contact_id as an integer key while the
     * request/service contract also accepts tenant contact codes. Normalize
     * after validation so direct service callers cannot persist a code as a
     * coerced zero/incorrect key.
     *
     * @return array<string, mixed>
     */
    public static function normalizeDocumentReferences(array $data, int $companyId): array
    {
        if (array_key_exists('contact_id', $data)) {
            $data['contact_id'] = self::resolveContactId(
                $data['contact_id'],
                $data['contact_type'] ?? null,
                $companyId,
                'contact_id',
            );
        }

        return $data;
    }

    private static function assertContact(mixed $contactId, ?string $contactType, int $companyId, string $attribute): void
    {
        if ($contactId === null || $contactId === '') {
            return;
        }

        $queries = self::contactQueries($contactId, $contactType, $companyId, $attribute);
        $matches = 0;
        foreach ($queries as $query) {
            if ($query->exists()) {
                $matches++;
            }
        }

        if ($matches === 0) {
            throw ValidationException::withMessages([
                $attribute => 'The selected contact does not belong to the active company.',
            ]);
        }
        if ($matches > 1) {
            throw ValidationException::withMessages([
                $attribute => 'The contact type is required when the reference matches more than one contact type.',
            ]);
        }
    }

    private static function resolveContactId(mixed $contactId, ?string $contactType, int $companyId, string $attribute): ?int
    {
        if ($contactId === null || $contactId === '') {
            return null;
        }

        $queries = self::contactQueries($contactId, $contactType, $companyId, $attribute);
        $matches = [];
        foreach ($queries as $query) {
            $model = $query->first();
            if ($model !== null) {
                $matches[] = $model;
            }
        }

        if ($matches === []) {
            throw ValidationException::withMessages([
                $attribute => 'The selected contact does not belong to the active company.',
            ]);
        }
        if (count($matches) > 1) {
            throw ValidationException::withMessages([
                $attribute => 'The contact type is required when the reference matches more than one contact type.',
            ]);
        }

        return (int) $matches[0]->id;
    }

    /** @return array<int, \Illuminate\Database\Query\Builder> */
    private static function contactQueries(mixed $contactId, ?string $contactType, int $companyId, string $attribute): array
    {
        $tables = match (strtolower((string) $contactType)) {
            'customer' => ['customers'],
            'supplier' => ['suppliers'],
            'employee' => ['employees'],
            '' => ['customers', 'suppliers', 'employees'],
            default => throw ValidationException::withMessages([
                $attribute => 'The selected contact type is not supported.',
            ]),
        };

        return array_map(static function (string $table) use ($contactId, $companyId): \Illuminate\Database\Query\Builder {
            $query = DB::table($table)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at');
            is_numeric($contactId)
                ? $query->where('id', (int) $contactId)
                : $query->where('code', (string) $contactId);

            return $query;
        }, $tables);
    }

    private static function assertOwned(
        string $table,
        mixed $id,
        int $companyId,
        string $attribute,
        bool $softDeletes = false,
        bool $required = false
    ): void {
        if ($id === null || $id === '') {
            if ($required) {
                throw ValidationException::withMessages([$attribute => 'The selected resource is required.']);
            }

            return;
        }

        $query = DB::table($table)->where('company_id', $companyId)->where('id', $id);
        if ($softDeletes) {
            $query->whereNull('deleted_at');
        }
        if (! $query->exists()) {
            throw ValidationException::withMessages([$attribute => 'The selected resource does not belong to the active company.']);
        }
    }

    private static function assertOwnedByCode(string $table, mixed $code, int $companyId, string $attribute): void
    {
        if ($code === null || $code === '') {
            return;
        }
        if (! DB::table($table)->where('company_id', $companyId)->where('code', $code)->exists()) {
            throw ValidationException::withMessages([$attribute => 'The selected resource does not belong to the active company.']);
        }
    }

    private static function assertAccount(mixed $code, int $companyId, string $attribute): void
    {
        if ($code === null || $code === '') {
            return;
        }
        if (! DB::table('chart_of_accounts')
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->whereNull('deleted_at')
            ->exists()) {
            throw ValidationException::withMessages([$attribute => 'The selected account does not belong to the active company.']);
        }
    }
}
