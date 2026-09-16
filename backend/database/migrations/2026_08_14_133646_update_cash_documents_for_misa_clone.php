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
        Schema::table('cash_receipts', function (Blueprint $table) {
            $table->string('contact_type')->nullable()->after('company_id'); // customer, supplier, employee
            $table->unsignedBigInteger('contact_id')->nullable()->after('contact_type');
            $table->string('contact_name')->nullable()->after('contact_id');
            $table->string('attached_docs')->nullable()->after('reason');
            $table->string('currency', 3)->default('VND')->after('attached_docs');
            $table->decimal('exchange_rate', 15, 2)->default(1)->after('currency');
        });

        Schema::table('cash_payments', function (Blueprint $table) {
            $table->string('contact_type')->nullable()->after('company_id');
            $table->unsignedBigInteger('contact_id')->nullable()->after('contact_type');
            $table->string('contact_name')->nullable()->after('contact_id');
            $table->string('attached_docs')->nullable()->after('reason');
            $table->string('currency', 3)->default('VND')->after('attached_docs');
            $table->decimal('exchange_rate', 15, 2)->default(1)->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('cash_receipts', function (Blueprint $table) {
            $table->dropColumn(['contact_type', 'contact_id', 'contact_name', 'attached_docs', 'currency', 'exchange_rate']);
        });

        Schema::table('cash_payments', function (Blueprint $table) {
            $table->dropColumn(['contact_type', 'contact_id', 'contact_name', 'attached_docs', 'currency', 'exchange_rate']);
        });
    }
};
