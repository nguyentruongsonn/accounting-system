<?php

namespace App\Http\Requests;

use App\Enums\SystemVoucherType;
use App\Models\InventoryStockCount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

abstract class TenantAccountingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->company_id !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->user()?->company_id !== null) {
            $this->merge(['company_id' => (int) $this->user()->company_id]);
        }
    }

    protected function companyId(): int
    {
        return (int) $this->user()?->company_id;
    }

    protected function requiredForCreate(): string
    {
        return $this->isMethod('post') ? 'required' : 'sometimes';
    }

    protected function tenantExists(string $table, string $column = 'id', bool $softDeletes = false): mixed
    {
        return Rule::exists($table, $column)->where(function ($query) use ($softDeletes) {
            $query->where('company_id', $this->companyId());
            if ($softDeletes) {
                $query->whereNull('deleted_at');
            }
        });
    }

    protected function tenantAccountExists(): mixed
    {
        return $this->tenantExists('chart_of_accounts', 'code', true);
    }

    protected function tenantExistsByIdOrCode(string $table, bool $softDeletes = false): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($table, $softDeletes): void {
            if ($value === null || $value === '') {
                return;
            }

            $query = DB::table($table)->where('company_id', $this->companyId());
            is_numeric($value)
                ? $query->where('id', (int) $value)
                : $query->where('code', (string) $value);
            if ($softDeletes) {
                $query->whereNull('deleted_at');
            }

            if (! $query->exists()) {
                $fail('The selected resource does not belong to the active company.');
            }
        };
    }

    protected function tenantContactExists(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            $contactType = strtolower((string) $this->input('contact_type'));
            $table = match ($contactType) {
                'customer' => 'customers',
                'supplier' => 'suppliers',
                'employee' => 'employees',
                '' => null,
                default => null,
            };

            if ($table === null) {
                if ($contactType !== '') {
                    $fail('The selected contact type is not supported.');

                    return;
                }

                foreach (['suppliers', 'customers', 'employees'] as $candidateTable) {
                    $candidate = DB::table($candidateTable)
                        ->where('company_id', $this->companyId())
                        ->whereNull('deleted_at');
                    is_numeric($value)
                        ? $candidate->where('id', (int) $value)
                        : $candidate->where('code', (string) $value);

                    if ($candidate->exists()) {
                        return;
                    }
                }

                $fail('The selected contact does not belong to the active company.');

                return;
            }

            $query = DB::table($table)->where('company_id', $this->companyId());
            if (is_numeric($value)) {
                $query->where('id', (int) $value);
            } else {
                $query->where('code', (string) $value);
            }
            if (in_array($table, ['customers', 'suppliers', 'employees'], true)) {
                $query->whereNull('deleted_at');
            }

            if (! $query->exists()) {
                $fail('The selected contact does not belong to the active company.');
            }
        };
    }

    protected function tenantVoucherReferenceExists(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $targetId = $value['target_id'] ?? $value['real_id'] ?? $value['id'] ?? null;
            if (! is_numeric($targetId)) {
                return;
            }

            $targetType = $value['target_type'] ?? $value['model'] ?? null;
            $resolvedType = is_string($targetType)
                ? SystemVoucherType::resolveType($targetType)
                    ?? SystemVoucherType::resolveType(class_basename($targetType))
                : null;
            $modelClass = $resolvedType?->modelClass();
            if ($modelClass === null && is_string($targetType)) {
                $candidate = str_contains($targetType, '\\') ? $targetType : 'App\\Models\\'.$targetType;
                if ($candidate === InventoryStockCount::class) {
                    $modelClass = $candidate;
                }
            }

            if ($modelClass === null) {
                $fail('A supported voucher target type is required when a target ID is provided.');

                return;
            }

            $target = $modelClass::query()->withoutGlobalScopes()->whereKey((int) $targetId)->first();
            if ($target !== null && (int) $target->getAttribute('company_id') !== $this->companyId()) {
                $fail('The referenced voucher does not belong to the active company.');
            }
        };
    }
}
