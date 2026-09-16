<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_receipt_lines')) {
            Schema::table('inventory_receipt_lines', function (Blueprint $table): void {
                $table->string('debit_account', 20)->nullable()->change();
                $table->string('credit_account', 20)->nullable()->change();
            });
        }

        if (Schema::hasTable('inventory_issue_lines')) {
            Schema::table('inventory_issue_lines', function (Blueprint $table): void {
                $table->string('debit_account', 20)->nullable()->change();
                $table->string('credit_account', 20)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Drafts may legitimately remain unresolved; restoring NOT NULL would
        // make existing adjustment drafts impossible to read or edit.
    }
};
