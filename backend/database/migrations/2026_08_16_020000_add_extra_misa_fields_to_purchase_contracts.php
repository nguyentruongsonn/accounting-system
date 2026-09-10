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
        if (Schema::hasTable('purchase_contracts')) {
            Schema::table('purchase_contracts', function (Blueprint $table) {
                if (!Schema::hasColumn('purchase_contracts', 'is_pre_software')) {
                    $table->boolean('is_pre_software')->default(false)->after('status');
                }
                if (!Schema::hasColumn('purchase_contracts', 'liquidation_amount')) {
                    $table->decimal('liquidation_amount', 18, 2)->default(0)->after('is_pre_software');
                }
                if (!Schema::hasColumn('purchase_contracts', 'shipping_address')) {
                    $table->text('shipping_address')->nullable()->after('liquidation_reason');
                }
                if (!Schema::hasColumn('purchase_contracts', 'reference')) {
                    $table->string('reference')->nullable()->after('contract_number');
                }
            });
        }

        if (Schema::hasTable('purchase_contract_payments')) {
            Schema::table('purchase_contract_payments', function (Blueprint $table) {
                if (!Schema::hasColumn('purchase_contract_payments', 'stage_name')) {
                    $table->string('stage_name')->nullable()->after('stage_number');
                }
                if (!Schema::hasColumn('purchase_contract_payments', 'prev_year_paid')) {
                    $table->decimal('prev_year_paid', 18, 2)->default(0)->after('paid_amount');
                }
                if (!Schema::hasColumn('purchase_contract_payments', 'voucher_ref')) {
                    $table->string('voucher_ref')->nullable()->after('notes');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
