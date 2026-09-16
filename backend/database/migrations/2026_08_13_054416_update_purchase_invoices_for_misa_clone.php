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
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->date('accounting_date')->nullable()->after('invoice_date');
            $table->string('supplier_name')->nullable()->after('supplier_id');
            $table->string('supplier_address')->nullable()->after('supplier_name');
            $table->string('deliverer_name')->nullable()->after('supplier_address');
            $table->string('attached_docs')->nullable()->after('description');
            $table->string('currency', 3)->default('VND')->after('attached_docs');
            $table->decimal('exchange_rate', 15, 2)->default(1)->after('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'accounting_date',
                'supplier_name',
                'supplier_address',
                'deliverer_name',
                'attached_docs',
                'currency',
                'exchange_rate'
            ]);
        });
    }
};
