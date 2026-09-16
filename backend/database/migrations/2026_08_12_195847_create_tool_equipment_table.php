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
        Schema::create('tool_equipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('tool_code', 50)->unique();
            $table->string('tool_name');
            $table->date('purchase_date');
            $table->bigInteger('original_cost')->default(0);
            $table->integer('allocation_months')->default(0);
            $table->bigInteger('monthly_allocation')->default(0);
            $table->bigInteger('accumulated_allocation')->default(0);
            $table->bigInteger('remaining_value')->default(0);
            $table->string('tool_account', 20)->default('242'); // Prepaid expenses
            $table->string('expense_account', 20)->default('6423'); // Tool expense
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tool_equipment');
    }
};
