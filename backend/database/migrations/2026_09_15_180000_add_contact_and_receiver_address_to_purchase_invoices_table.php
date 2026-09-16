<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_invoices')) {
            return;
        }

        Schema::table('purchase_invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_invoices', 'contact_name')) {
                $table->string('contact_name', 255)->nullable()->after('supplier_address');
            }
            if (! Schema::hasColumn('purchase_invoices', 'receiver_address')) {
                $table->string('receiver_address', 500)->nullable()->after('receiver_name');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchase_invoices')) {
            return;
        }

        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $columns = array_filter([
                Schema::hasColumn('purchase_invoices', 'contact_name') ? 'contact_name' : null,
                Schema::hasColumn('purchase_invoices', 'receiver_address') ? 'receiver_address' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
