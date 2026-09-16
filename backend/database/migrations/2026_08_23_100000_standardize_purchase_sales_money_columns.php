<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Legacy source tables used BIGINT for monetary values. That silently
     * truncates valid decimal amounts (for example VND 0.10 in a precision
     * regression test) on MySQL. Canonical posting requires fixed-scale
     * decimals at the source boundary as well as in journal lines.
     */
    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ([
            'purchase_invoices' => ['sub_total', 'tax_amount', 'total_amount'],
            'purchase_invoice_lines' => ['unit_price', 'amount', 'discount_amount', 'tax_amount'],
            'sales_invoices' => ['sub_total', 'tax_amount', 'total_amount'],
            'sales_invoice_lines' => ['unit_price', 'amount', 'discount_amount', 'tax_amount'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` DECIMAL(20,2) NOT NULL DEFAULT 0");
            }
        }
    }

    public function down(): void
    {
        // Reverting fixed-scale money to integer storage is lossy and is not
        // an acceptable production rollback. Use a forward migration instead.
    }
};
