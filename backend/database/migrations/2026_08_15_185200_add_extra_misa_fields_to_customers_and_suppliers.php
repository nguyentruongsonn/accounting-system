<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('dvqhns_code')->nullable()->after('tax_code');
            $table->string('einvoice_contact_name')->nullable()->after('contact_person_email');
            $table->string('einvoice_contact_email')->nullable()->after('einvoice_contact_name');
            $table->string('einvoice_contact_phone')->nullable()->after('einvoice_contact_email');
            $table->string('passport_number')->nullable()->after('identity_card_place');
            $table->string('country')->default('Việt Nam')->nullable()->after('address');
            $table->string('province')->nullable()->after('country');
            $table->string('district')->nullable()->after('province');
            $table->string('ward')->nullable()->after('district');
            $table->boolean('same_as_main_address')->default(false)->after('ward');
            $table->string('custom_field_1')->nullable()->after('note');
            $table->string('custom_field_2')->nullable()->after('custom_field_1');
            $table->string('custom_field_3')->nullable()->after('custom_field_2');
            $table->string('custom_field_4')->nullable()->after('custom_field_3');
            $table->string('custom_field_5')->nullable()->after('custom_field_4');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('dvqhns_code')->nullable()->after('tax_code');
            $table->string('einvoice_contact_name')->nullable()->after('contact_person_email');
            $table->string('einvoice_contact_email')->nullable()->after('einvoice_contact_name');
            $table->string('einvoice_contact_phone')->nullable()->after('einvoice_contact_email');
            $table->string('passport_number')->nullable()->after('identity_card_place');
            $table->string('country')->default('Việt Nam')->nullable()->after('address');
            $table->string('province')->nullable()->after('country');
            $table->string('district')->nullable()->after('province');
            $table->string('ward')->nullable()->after('district');
            $table->boolean('same_as_main_address')->default(false)->after('ward');
            $table->string('custom_field_1')->nullable()->after('note');
            $table->string('custom_field_2')->nullable()->after('custom_field_1');
            $table->string('custom_field_3')->nullable()->after('custom_field_2');
            $table->string('custom_field_4')->nullable()->after('custom_field_3');
            $table->string('custom_field_5')->nullable()->after('custom_field_4');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'dvqhns_code', 'einvoice_contact_name', 'einvoice_contact_email', 'einvoice_contact_phone',
                'passport_number', 'country', 'province', 'district', 'ward', 'same_as_main_address',
                'custom_field_1', 'custom_field_2', 'custom_field_3', 'custom_field_4', 'custom_field_5'
            ]);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn([
                'dvqhns_code', 'einvoice_contact_name', 'einvoice_contact_email', 'einvoice_contact_phone',
                'passport_number', 'country', 'province', 'district', 'ward', 'same_as_main_address',
                'custom_field_1', 'custom_field_2', 'custom_field_3', 'custom_field_4', 'custom_field_5'
            ]);
        });
    }
};
