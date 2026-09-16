<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_allocations', function (Blueprint $table): void {
            // Legacy amount_raw remains the functional amount for existing VND
            // evidence. Foreign-currency flows must retain both sides.
            $table->string('functional_currency_code', 3)->nullable()->after('currency_code');
            $table->string('functional_amount_raw', 80)->nullable()->after('functional_currency_code');
            $table->unsignedTinyInteger('functional_amount_scale')->nullable()->after('functional_amount_raw');
            $table->string('original_currency_code', 3)->nullable()->after('functional_amount_scale');
            $table->string('original_amount_raw', 80)->nullable()->after('original_currency_code');
            $table->unsignedTinyInteger('original_amount_scale')->nullable()->after('original_amount_raw');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_allocations', function (Blueprint $table): void {
            $table->dropColumn(['functional_currency_code', 'functional_amount_raw', 'functional_amount_scale', 'original_currency_code', 'original_amount_raw', 'original_amount_scale']);
        });
    }
};
