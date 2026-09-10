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
        Schema::table('purchase_invoice_lines', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete()->after('purchase_invoice_id');
            $table->decimal('discount_rate', 5, 2)->default(0)->after('amount');
            $table->bigInteger('discount_amount')->default(0)->after('discount_rate');
            $table->string('tax_account', 20)->nullable()->after('tax_amount');
            $table->string('invoice_symbol', 50)->nullable()->after('tax_account');
            $table->string('invoice_number', 50)->nullable()->after('invoice_symbol');
            $table->date('invoice_date')->nullable()->after('invoice_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_invoice_lines', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
            $table->dropColumn([
                'item_id',
                'discount_rate',
                'discount_amount',
                'tax_account',
                'invoice_symbol',
                'invoice_number',
                'invoice_date'
            ]);
        });
    }
};
