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
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->string('category_code')->nullable()->after('asset_name');
            $table->string('department_code')->nullable()->after('category_code');
            $table->integer('quantity')->default(1)->after('department_code');
            $table->date('start_depreciation_date')->nullable()->after('purchase_date');
            $table->decimal('depreciable_cost', 15, 2)->default(0)->after('original_cost');
            $table->string('voucher_number')->nullable()->after('company_id');
            $table->date('voucher_date')->nullable()->after('voucher_number');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropColumn([
                'category_code', 'department_code', 'quantity', 
                'start_depreciation_date', 'depreciable_cost', 
                'voucher_number', 'voucher_date'
            ]);
        });
    }
};
