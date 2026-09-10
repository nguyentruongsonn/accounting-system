<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cash_payment_lines', 'cash_receipt_lines', 'bank_payment_lines', 'bank_receipt_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('original_currency_code', 3)->nullable();
                $table->string('original_amount_raw', 80)->nullable();
                $table->unsignedTinyInteger('original_amount_scale')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['cash_payment_lines', 'cash_receipt_lines', 'bank_payment_lines', 'bank_receipt_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['original_currency_code', 'original_amount_raw', 'original_amount_scale']);
            });
        }
    }
};
