<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_contracts') || Schema::hasColumn('purchase_contracts', 'payment_terms')) {
            return;
        }

        Schema::table('purchase_contracts', function (Blueprint $table): void {
            $table->string('payment_terms', 255)->nullable()->after('payment_deadline');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_contracts') && Schema::hasColumn('purchase_contracts', 'payment_terms')) {
            Schema::table('purchase_contracts', function (Blueprint $table): void {
                $table->dropColumn('payment_terms');
            });
        }
    }
};
