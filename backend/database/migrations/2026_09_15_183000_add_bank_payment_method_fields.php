<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bank_payments')) {
            Schema::table('bank_payments', function (Blueprint $table): void {
                if (! Schema::hasColumn('bank_payments', 'payment_type')) {
                    $table->string('payment_type', 50)->nullable()->after('voucher_type');
                }
                if (! Schema::hasColumn('bank_payments', 'is_bulk_transfer')) {
                    $table->boolean('is_bulk_transfer')->default(false)->after('payment_type');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bank_payments')) {
            Schema::table('bank_payments', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    Schema::hasColumn('bank_payments', 'is_bulk_transfer') ? 'is_bulk_transfer' : null,
                    Schema::hasColumn('bank_payments', 'payment_type') ? 'payment_type' : null,
                ]));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
