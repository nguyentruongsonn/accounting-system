<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tax account is not applicable on a zero-tax line. The production
     * draft gate requires it when tax is positive, but a NOT NULL/default
     * column would otherwise force the application to invent a VAT account
     * for exempt or non-taxable lines.
     */
    public function up(): void
    {
        foreach ([
            'sales_return_lines',
            'sales_discount_lines',
            'purchase_return_lines',
            'purchase_discount_lines',
        ] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'tax_account')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('tax_account', 20)->nullable()->default(null)->change();
            });
        }
    }

    public function down(): void
    {
        // This is a deliberate schema relaxation. Reverting to NOT NULL would
        // require selecting a statutory VAT account for existing null lines,
        // which is an accounting-owner decision and must not be automated.
    }
};
