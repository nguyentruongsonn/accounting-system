<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_advance_settlements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('settlement_number', 50);
            $table->date('settlement_date');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('employee_name', 255);
            $table->string('department', 255)->nullable();
            $table->decimal('advance_amount', 20, 2);
            $table->decimal('actual_spent', 20, 2);
            $table->decimal('refund_amount', 20, 2)->default(0);
            $table->decimal('extra_amount', 20, 2)->default(0);
            $table->text('reason');
            $table->string('status', 30)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'settlement_number'], 'cash_advance_settlements_company_number_unique');
            $table->index(['company_id', 'settlement_date']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_advance_settlements');
    }
};
