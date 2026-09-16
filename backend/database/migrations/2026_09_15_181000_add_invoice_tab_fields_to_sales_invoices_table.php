<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_invoices')) {
            return;
        }

        Schema::table('sales_invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_invoices', 'payment_terms')) {
                $table->string('payment_terms', 255)->nullable()->after('due_date');
            }
            if (! Schema::hasColumn('sales_invoices', 'due_days')) {
                $table->unsignedInteger('due_days')->nullable()->after('payment_terms');
            }
            if (! Schema::hasColumn('sales_invoices', 'invoice_form_number')) {
                $table->string('invoice_form_number', 50)->nullable()->after('invoice_code');
            }
            if (! Schema::hasColumn('sales_invoices', 'payment_method_display')) {
                $table->string('payment_method_display', 50)->nullable()->after('payment_method');
            }
            if (! Schema::hasColumn('sales_invoices', 'bank_account')) {
                $table->string('bank_account', 100)->nullable()->after('payment_method_display');
            }
            if (! Schema::hasColumn('sales_invoices', 'budget_relation_code')) {
                $table->string('budget_relation_code', 50)->nullable()->after('bank_account');
            }
            if (! Schema::hasColumn('sales_invoices', 'id_card_number')) {
                $table->string('id_card_number', 50)->nullable()->after('budget_relation_code');
            }
            if (! Schema::hasColumn('sales_invoices', 'passport_number')) {
                $table->string('passport_number', 50)->nullable()->after('id_card_number');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_invoices')) {
            return;
        }

        Schema::table('sales_invoices', function (Blueprint $table): void {
            $columns = array_filter([
                Schema::hasColumn('sales_invoices', 'payment_terms') ? 'payment_terms' : null,
                Schema::hasColumn('sales_invoices', 'due_days') ? 'due_days' : null,
                Schema::hasColumn('sales_invoices', 'invoice_form_number') ? 'invoice_form_number' : null,
                Schema::hasColumn('sales_invoices', 'payment_method_display') ? 'payment_method_display' : null,
                Schema::hasColumn('sales_invoices', 'bank_account') ? 'bank_account' : null,
                Schema::hasColumn('sales_invoices', 'budget_relation_code') ? 'budget_relation_code' : null,
                Schema::hasColumn('sales_invoices', 'id_card_number') ? 'id_card_number' : null,
                Schema::hasColumn('sales_invoices', 'passport_number') ? 'passport_number' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
