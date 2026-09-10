<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('management_report_definitions', function (Blueprint $table): void {
            // D-04 facts are optional at the shared definition level. Stock
            // v2 explicitly requires them; AP/AR retains its approved
            // source/calculation-only definition path.
            $table->json('amount_contract')->nullable()->after('calculation_contract');
        });
    }

    public function down(): void
    {
        Schema::table('management_report_definitions', function (Blueprint $table): void {
            $table->dropColumn('amount_contract');
        });
    }
};
