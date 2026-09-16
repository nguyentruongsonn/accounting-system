<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_received_invoices')) {
            return;
        }

        Schema::table('purchase_received_invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_received_invoices', 'purchase_order_id')) {
                $table->foreignId('purchase_order_id')->nullable()->after('purchase_invoice_id')->constrained('purchase_orders')->nullOnDelete();
            }
            if (! Schema::hasColumn('purchase_received_invoices', 'purchase_contract_id')) {
                $table->foreignId('purchase_contract_id')->nullable()->after('purchase_order_id')->constrained('purchase_contracts')->nullOnDelete();
            }
            if (! Schema::hasColumn('purchase_received_invoices', 'inventory_receipt_id')) {
                $table->foreignId('inventory_receipt_id')->nullable()->after('purchase_contract_id')->constrained('inventory_receipts')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchase_received_invoices')) {
            return;
        }

        Schema::table('purchase_received_invoices', function (Blueprint $table): void {
            foreach (['inventory_receipt_id', 'purchase_contract_id', 'purchase_order_id'] as $column) {
                if (Schema::hasColumn('purchase_received_invoices', $column)) {
                    $table->dropForeign([$column]);
                    $table->dropColumn($column);
                }
            }
        });
    }
};
