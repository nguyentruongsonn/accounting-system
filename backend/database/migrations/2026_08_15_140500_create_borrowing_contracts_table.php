<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borrowing_contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->default(1);
            $table->string('contract_number')->unique();
            $table->string('credit_contract')->nullable();
            $table->string('lender_name');
            $table->string('purpose')->nullable();
            $table->string('debit_account')->default('3411');
            $table->string('interest_account')->default('635');
            $table->decimal('amount', 18, 2)->default(0);
            $table->integer('term')->default(12);
            $table->string('term_unit')->default('Tháng');
            $table->date('disbursement_date');
            $table->date('maturity_date');
            $table->string('disbursement_method')->default('Chuyển khoản vào tài khoản DN');
            $table->string('recipient_account')->nullable();
            $table->string('recipient_bank')->nullable();
            $table->decimal('interest_rate', 5, 2)->default(8.5);
            $table->string('interest_period')->default('Hàng tháng');
            $table->decimal('paid_principal', 18, 2)->default(0);
            $table->decimal('remaining_principal', 18, 2)->default(0);
            $table->string('status')->default('Đang vay');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrowing_contracts');
    }
};
