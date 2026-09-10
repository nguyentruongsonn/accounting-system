<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Tenant ownership checks for direct commercial-invoice service callers.
 *
 * HTTP FormRequests are not the only entry point: jobs, CLI commands and
 * internal services can invoke invoice services directly. Keep the check
 * here so those paths cannot attach another company's customer/supplier.
 */
final class CommercialTenantReferenceGuard
{
    public static function assertSalesInvoice(array $data, int $companyId): void
    {
        self::assertOwned('customers', $data['customer_id'] ?? null, $companyId, 'customer_id', true);
        self::assertOwned('employees', $data['employee_id'] ?? null, $companyId, 'employee_id');
        self::assertLines($data['lines'] ?? [], $companyId, 'sales');
    }

    public static function assertPurchaseInvoice(array $data, int $companyId): void
    {
        self::assertOwned('suppliers', $data['supplier_id'] ?? null, $companyId, 'supplier_id', true);
        self::assertOwned('employees', $data['employee_id'] ?? null, $companyId, 'employee_id');
        self::assertLines($data['lines'] ?? [], $companyId, 'purchase');
    }

    private static function assertLines(array $lines, int $companyId, string $domain): void
    {
        foreach ($lines as $index => $line) {
            if (! is_array($line)) continue;
            $orderTable = $domain === 'sales' ? 'sales_orders' : 'purchase_orders';
            self::assertOwned($orderTable, $line['order_id'] ?? null, $companyId, "lines.{$index}.order_id");
            if ($domain === 'sales' && ($line['contract_id'] ?? null) !== null && ($line['contract_id'] ?? '') !== '') {
                throw ValidationException::withMessages([
                    "lines.{$index}.contract_id" => 'Sales contract references are unavailable until a tenant-scoped sales contract domain exists.',
                ]);
            }
            if ($domain === 'purchase') {
                self::assertOwned('purchase_contracts', $line['contract_id'] ?? null, $companyId, "lines.{$index}.contract_id");
            }
        }
    }

    private static function assertOwned(
        string $table,
        mixed $id,
        int $companyId,
        string $attribute,
        bool $required = false,
    ): void {
        if ($id === null || $id === '') {
            if ($required) {
                throw ValidationException::withMessages([
                    $attribute => 'The selected reference is required.',
                ]);
            }

            return;
        }

        $query = DB::table($table)->where('company_id', $companyId)->where('id', (int) $id);
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        if (! is_numeric($id) || ! $query->exists()) {
            throw ValidationException::withMessages([
                $attribute => 'The selected reference does not belong to the active company.',
            ]);
        }
    }
}
