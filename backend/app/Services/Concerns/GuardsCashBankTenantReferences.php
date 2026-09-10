<?php

namespace App\Services\Concerns;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Enforces the same tenant boundary at service entry points that HTTP requests
 * enforce at their validation boundary.  Service callers (jobs, CLI, tests)
 * must not be able to persist a contact or settlement document from another
 * company simply because they bypass a FormRequest.
 */
trait GuardsCashBankTenantReferences
{
    /** @param class-string<Model> $invoiceModel */
    private function assertCashBankTenantReferences(array $data, int $companyId, string $invoiceModel): void
    {
        if (array_key_exists('contact_id', $data)) {
            $this->assertCashBankContactBelongsToCompany(
                $data['contact_id'],
                $data['contact_type'] ?? null,
                $companyId,
                'contact_id',
            );
        }

        if (array_key_exists('employee_id', $data)) {
            $this->assertCashBankContactBelongsToCompany($data['employee_id'], 'employee', $companyId, 'employee_id');
        }

        foreach ($data['lines'] ?? [] as $index => $line) {
            if (array_key_exists('line_contact_id', $line)) {
                $this->assertCashBankContactBelongsToCompany(
                    $line['line_contact_id'],
                    $line['line_contact_type'] ?? null,
                    $companyId,
                    "lines.$index.line_contact_id",
                );
            }

            if (array_key_exists('invoice_id', $line)) {
                $this->assertCashBankInvoiceBelongsToCompany(
                    $line['invoice_id'],
                    $companyId,
                    $invoiceModel,
                    "lines.$index.invoice_id",
                );
            }
        }
    }

    private function resolveCashBankTenantContactId(mixed $contactId, ?string $contactType, int $companyId, string $field): ?int
    {
        if ($contactId === null || $contactId === '') {
            return null;
        }

        $model = $this->cashBankTenantContactQuery($contactId, $contactType, $companyId, $field)->first();

        return $model?->getKey();
    }

    /**
     * Normalize service-level contact codes to the integer foreign-key value
     * used by cash/bank vouchers. HTTP FormRequests may receive either a code
     * or an id; direct jobs/services must produce the same persisted contract.
     *
     * @return array<string, mixed>
     */
    private function normalizeCashBankTenantReferences(array $data, int $companyId): array
    {
        if (array_key_exists('contact_id', $data)) {
            $data['contact_id'] = $this->resolveCashBankTenantContactId(
                $data['contact_id'],
                $data['contact_type'] ?? null,
                $companyId,
                'contact_id',
            );
        }

        foreach ($data['lines'] ?? [] as $index => $line) {
            if (array_key_exists('line_contact_id', $line)) {
                $data['lines'][$index]['line_contact_id'] = $this->resolveCashBankTenantContactId(
                    $line['line_contact_id'],
                    $line['line_contact_type'] ?? null,
                    $companyId,
                    "lines.$index.line_contact_id",
                );
            }
        }

        return $data;
    }

    private function assertCashBankContactBelongsToCompany(mixed $contactId, ?string $contactType, int $companyId, string $field): void
    {
        if ($contactId === null || $contactId === '') {
            return;
        }

        if (! $this->cashBankTenantContactQuery($contactId, $contactType, $companyId, $field)->exists()) {
            throw ValidationException::withMessages([
                $field => 'The selected contact does not belong to the active company.',
            ]);
        }
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Model> */
    private function cashBankTenantContactQuery(mixed $contactId, ?string $contactType, int $companyId, string $field)
    {
        $type = strtolower((string) $contactType);
        $models = match ($type) {
            'customer' => [Customer::class],
            'supplier' => [Supplier::class],
            'employee' => [Employee::class],
            '' => [Customer::class, Supplier::class, Employee::class],
            default => throw ValidationException::withMessages([
                $field => 'The selected contact type is not supported.',
            ]),
        };

        $matchingQueries = [];
        foreach ($models as $model) {
            $query = $model::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereNull('deleted_at');
            is_numeric($contactId)
                ? $query->whereKey((int) $contactId)
                : $query->where('code', (string) $contactId);

            if ($query->exists()) {
                $matchingQueries[] = $query;
            }
        }

        if (count($matchingQueries) > 1) {
            throw ValidationException::withMessages([
                $field => 'The contact type is required when the reference matches more than one contact type.',
            ]);
        }

        if ($matchingQueries !== []) {
            return $matchingQueries[0];
        }

        // Return a guaranteed-empty query of a real model so callers can use
        // exists()/first() without a special branch.
        return Customer::withoutGlobalScopes()->whereRaw('1 = 0');
    }

    /** @param class-string<Model> $invoiceModel */
    private function assertCashBankInvoiceBelongsToCompany(mixed $invoiceId, int $companyId, string $invoiceModel, string $field): void
    {
        if ($invoiceId === null || $invoiceId === '') {
            return;
        }

        if (! is_numeric($invoiceId) || ! $invoiceModel::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereKey((int) $invoiceId)
            ->exists()) {
            throw ValidationException::withMessages([
                $field => 'The selected invoice does not belong to the active company.',
            ]);
        }
    }
}
