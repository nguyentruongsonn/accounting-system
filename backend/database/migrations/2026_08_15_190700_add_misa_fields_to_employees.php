<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('is_customer')->default(false)->after('name');
            $table->boolean('is_supplier')->default(false)->after('is_customer');
            $table->string('gender')->default('Nam')->after('position');
            $table->date('birth_date')->nullable()->after('gender');
            $table->string('id_card_number')->nullable()->after('birth_date');
            $table->date('id_card_date')->nullable()->after('id_card_number');
            $table->string('id_card_place')->nullable()->after('id_card_date');
            $table->string('passport_number')->nullable()->after('id_card_place');
            $table->string('address')->nullable()->after('passport_number');
            $table->string('email')->nullable()->after('address');
            $table->string('phone')->nullable()->after('email');
            $table->string('landline_phone')->nullable()->after('phone');
            $table->string('account_email')->nullable()->after('landline_phone');
            $table->string('account_phone')->nullable()->after('account_email');
            $table->decimal('contract_salary', 15, 2)->default(0)->after('base_salary');
            $table->decimal('salary_coefficient', 5, 2)->default(0)->after('contract_salary');
            $table->decimal('insurance_salary', 15, 2)->default(0)->after('salary_coefficient');
            $table->string('tax_code')->nullable()->after('insurance_salary');
            $table->string('contract_type')->default('Cư trú và có HĐLĐ từ 3 tháng trở lên')->after('tax_code');
            $table->integer('dependents_count')->default(0)->after('contract_type');
            $table->decimal('personal_deduction', 15, 2)->default(11000000)->after('dependents_count');
            $table->json('bank_accounts')->nullable()->after('personal_deduction');
            $table->json('dependents')->nullable()->after('bank_accounts');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'is_customer', 'is_supplier', 'gender', 'birth_date',
                'id_card_number', 'id_card_date', 'id_card_place', 'passport_number',
                'address', 'email', 'phone', 'landline_phone', 'account_email', 'account_phone',
                'contract_salary', 'salary_coefficient', 'insurance_salary', 'tax_code',
                'contract_type', 'dependents_count', 'personal_deduction', 'bank_accounts', 'dependents'
            ]);
        });
    }
};
