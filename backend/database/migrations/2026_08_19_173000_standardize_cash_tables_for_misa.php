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
        // 1. Cập nhật bảng cash_receipts
        Schema::table('cash_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('cash_receipts', 'voucher_type')) {
                $table->string('voucher_type', 150)->nullable()->after('company_id');
            }
        });

        // 2. Cập nhật bảng cash_payments
        Schema::table('cash_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('cash_payments', 'voucher_type')) {
                $table->string('voucher_type', 150)->nullable()->after('company_id');
            }
            if (! Schema::hasColumn('cash_payments', 'employee_id')) {
                $table->string('employee_id', 100)->nullable()->after('receiver_address');
            }
            if (! Schema::hasColumn('cash_payments', 'employee_name')) {
                $table->string('employee_name', 255)->nullable()->after('employee_id');
            }
            if (! Schema::hasColumn('cash_payments', 'referenced_vouchers')) {
                $table->json('referenced_vouchers')->nullable()->after('reason');
            }
        });

        // 3. Cập nhật bảng cash_payment_lines
        Schema::table('cash_payment_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('cash_payment_lines', 'operation')) {
                $table->string('operation', 100)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('cash_payment_lines', 'loan_contract')) {
                $table->string('loan_contract', 100)->nullable()->after('operation');
            }
            if (! Schema::hasColumn('cash_payment_lines', 'line_contact_id')) {
                $table->string('line_contact_id', 100)->nullable()->after('loan_contract');
            }
            if (! Schema::hasColumn('cash_payment_lines', 'line_contact_name')) {
                $table->string('line_contact_name', 255)->nullable()->after('line_contact_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_receipts', function (Blueprint $table) {
            $table->dropColumn(['voucher_type']);
        });

        Schema::table('cash_payments', function (Blueprint $table) {
            $table->dropColumn(['voucher_type', 'employee_id', 'employee_name', 'referenced_vouchers']);
        });

        Schema::table('cash_payment_lines', function (Blueprint $table) {
            $table->dropColumn(['operation', 'loan_contract', 'line_contact_id', 'line_contact_name']);
        });
    }
};
