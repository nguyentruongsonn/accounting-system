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
        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained()->cascadeOnDelete();
            $table->string('employee_name');
            $table->string('department')->nullable();
            $table->bigInteger('basic_salary')->default(0);
            $table->bigInteger('allowance')->default(0);
            $table->bigInteger('deduction')->default(0);
            $table->bigInteger('net_salary')->default(0);
            $table->string('debit_account', 20)->default('6421'); // Chi phi nhan vien
            $table->string('credit_account', 20)->default('3341'); // Phai tra nguoi lao dong
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
    }
};
