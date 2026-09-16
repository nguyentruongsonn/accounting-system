<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('customer_type')->default('org')->after('code'); // 'org' or 'personal'
            $table->boolean('is_customer')->default(true)->after('customer_type');
            $table->boolean('is_supplier')->default(false)->after('is_customer');
            $table->boolean('is_employee')->default(false)->after('is_supplier');
            $table->boolean('is_internal')->default(false)->after('is_employee');
            
            // Individual fields
            $table->string('identity_card_number')->nullable()->after('tax_code');
            $table->date('identity_card_date')->nullable()->after('identity_card_number');
            $table->string('identity_card_place')->nullable()->after('identity_card_date');
            $table->string('gender')->nullable()->after('identity_card_place');
            $table->date('birth_date')->nullable()->after('gender');
            $table->string('mobile_phone')->nullable()->after('phone');
            $table->string('landline_phone')->nullable()->after('mobile_phone');
            $table->string('contact_group')->nullable()->after('landline_phone');
            $table->string('assigned_employee_id')->nullable()->after('contact_group');

            // Organization & Contact fields
            $table->string('website')->nullable()->after('email');
            $table->string('legal_representative')->nullable()->after('website');
            $table->string('contact_person_salutation')->nullable()->after('legal_representative');
            $table->string('contact_person_name')->nullable()->after('contact_person_salutation');
            $table->string('contact_person_title')->nullable()->after('contact_person_name');
            $table->string('contact_person_phone')->nullable()->after('contact_person_title');
            $table->string('contact_person_email')->nullable()->after('contact_person_phone');

            // Terms & Debt
            $table->string('payment_term')->nullable()->after('default_account');
            $table->integer('due_days')->default(30)->after('payment_term');
            $table->decimal('debt_limit', 15, 2)->default(50000000)->after('due_days');
            $table->string('debt_account')->nullable()->after('debt_limit');

            // Bank & Delivery
            $table->json('bank_accounts')->nullable()->after('debt_account');
            $table->json('delivery_addresses')->nullable()->after('bank_accounts');
            $table->text('note')->nullable()->after('delivery_addresses');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('supplier_type')->default('org')->after('code'); // 'org' or 'personal'
            $table->boolean('is_customer')->default(false)->after('supplier_type');
            $table->boolean('is_supplier')->default(true)->after('is_customer');
            $table->boolean('is_employee')->default(false)->after('is_supplier');
            $table->boolean('is_internal')->default(false)->after('is_employee');
            
            // Individual fields
            $table->string('identity_card_number')->nullable()->after('tax_code');
            $table->date('identity_card_date')->nullable()->after('identity_card_number');
            $table->string('identity_card_place')->nullable()->after('identity_card_date');
            $table->string('gender')->nullable()->after('identity_card_place');
            $table->date('birth_date')->nullable()->after('gender');
            $table->string('mobile_phone')->nullable()->after('phone');
            $table->string('landline_phone')->nullable()->after('mobile_phone');
            $table->string('contact_group')->nullable()->after('landline_phone');
            $table->string('assigned_employee_id')->nullable()->after('contact_group');

            // Organization & Contact fields
            $table->string('website')->nullable()->after('email');
            $table->string('legal_representative')->nullable()->after('website');
            $table->string('contact_person_salutation')->nullable()->after('legal_representative');
            $table->string('contact_person_name')->nullable()->after('contact_person_salutation');
            $table->string('contact_person_title')->nullable()->after('contact_person_name');
            $table->string('contact_person_phone')->nullable()->after('contact_person_title');
            $table->string('contact_person_email')->nullable()->after('contact_person_phone');

            // Terms & Debt
            $table->string('payment_term')->nullable()->after('default_account');
            $table->integer('due_days')->default(30)->after('payment_term');
            $table->decimal('debt_limit', 15, 2)->default(50000000)->after('due_days');
            $table->string('debt_account')->nullable()->after('debt_limit');

            // Bank & Delivery
            $table->json('bank_accounts')->nullable()->after('debt_account');
            $table->json('delivery_addresses')->nullable()->after('bank_accounts');
            $table->text('note')->nullable()->after('delivery_addresses');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'customer_type', 'is_customer', 'is_supplier', 'is_employee', 'is_internal',
                'identity_card_number', 'identity_card_date', 'identity_card_place', 'gender', 'birth_date',
                'mobile_phone', 'landline_phone', 'contact_group', 'assigned_employee_id',
                'website', 'legal_representative', 'contact_person_salutation', 'contact_person_name',
                'contact_person_title', 'contact_person_phone', 'contact_person_email',
                'payment_term', 'due_days', 'debt_limit', 'debt_account', 'bank_accounts',
                'delivery_addresses', 'note'
            ]);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn([
                'supplier_type', 'is_customer', 'is_supplier', 'is_employee', 'is_internal',
                'identity_card_number', 'identity_card_date', 'identity_card_place', 'gender', 'birth_date',
                'mobile_phone', 'landline_phone', 'contact_group', 'assigned_employee_id',
                'website', 'legal_representative', 'contact_person_salutation', 'contact_person_name',
                'contact_person_title', 'contact_person_phone', 'contact_person_email',
                'payment_term', 'due_days', 'debt_limit', 'debt_account', 'bank_accounts',
                'delivery_addresses', 'note'
            ]);
        });
    }
};
