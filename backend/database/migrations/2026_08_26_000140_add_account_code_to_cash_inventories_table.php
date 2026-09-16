<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_inventories', function (Blueprint $table): void {
            // Nullable for legacy rows; new API writes require an active leaf
            // account so a book balance can be traced to posted JE lines.
            $table->string('account_code', 20)->nullable()->after('currency');
            $table->index(['company_id', 'account_code', 'audit_date'], 'cash_inventory_company_account_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cash_inventories', function (Blueprint $table): void {
            $table->dropIndex('cash_inventory_company_account_date_idx');
            $table->dropColumn('account_code');
        });
    }
};
