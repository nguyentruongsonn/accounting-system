<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->boolean('is_export_slip')->default(false);
            $table->string('payment_status', 50)->default('Unpaid');
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('payment_status', 50)->default('Unpaid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropForeign(['journal_entry_id']);
            $table->dropColumn(['journal_entry_id', 'is_export_slip', 'payment_status']);
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropForeign(['journal_entry_id']);
            $table->dropColumn(['journal_entry_id', 'payment_status']);
        });
    }
};
