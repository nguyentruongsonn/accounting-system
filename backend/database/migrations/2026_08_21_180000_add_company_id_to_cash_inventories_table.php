<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_inventories', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->restrictOnDelete();
        });

        // A legacy row can be attributed safely only when the database has one
        // and only one company. Multi-company/empty installations retain NULL;
        // application tenant queries deliberately never expose those rows.
        $legacyCompanyIds = DB::table('companies')->orderBy('id')->limit(2)->pluck('id');
        if ($legacyCompanyIds->count() === 1) {
            DB::table('cash_inventories')
                ->whereNull('company_id')
                ->update(['company_id' => $legacyCompanyIds->first()]);
        }

        Schema::table('cash_inventories', function (Blueprint $table) {
            $table->dropUnique('cash_inventories_audit_number_unique');
            $table->unique(['company_id', 'audit_number'], 'cash_inventories_company_audit_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cash_inventories', function (Blueprint $table) {
            $table->dropUnique('cash_inventories_company_audit_unique');
            $table->dropConstrainedForeignId('company_id');
            $table->unique('audit_number');
        });
    }
};
