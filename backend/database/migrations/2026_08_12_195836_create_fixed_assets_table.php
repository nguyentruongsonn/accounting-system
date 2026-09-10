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
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('asset_code', 50)->unique();
            $table->string('asset_name');
            $table->date('purchase_date');
            $table->bigInteger('original_cost')->default(0);
            $table->integer('useful_life_months')->default(0);
            $table->bigInteger('monthly_depreciation')->default(0);
            $table->bigInteger('accumulated_depreciation')->default(0);
            $table->bigInteger('net_value')->default(0);
            $table->string('asset_account', 20)->default('211');
            $table->string('depreciation_account', 20)->default('2141');
            $table->string('expense_account', 20)->default('6424');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
