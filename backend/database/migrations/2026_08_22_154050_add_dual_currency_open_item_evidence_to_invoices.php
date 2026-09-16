<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['purchase_invoices', 'sales_invoices'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                // Null means legacy/ambiguous source; it must not be inferred
                // as foreign-currency evidence by aging v2.
                $table->string('functional_currency_code', 3)->nullable();
                $table->string('functional_total_amount_raw', 80)->nullable();
                $table->unsignedTinyInteger('functional_total_amount_scale')->nullable();
                $table->string('original_total_amount_raw', 80)->nullable();
                $table->unsignedTinyInteger('original_total_amount_scale')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['purchase_invoices', 'sales_invoices'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['functional_currency_code', 'functional_total_amount_raw', 'functional_total_amount_scale', 'original_total_amount_raw', 'original_total_amount_scale']);
            });
        }
    }
};
