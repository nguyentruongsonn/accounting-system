<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_received_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('invoice_template', 50)->nullable();
            $table->string('invoice_series', 50);
            $table->string('invoice_number', 50);
            $table->date('invoice_date');
            $table->decimal('subtotal', 20, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 20, 2)->default(0);
            $table->decimal('total_amount', 20, 2)->default(0);
            $table->string('status', 20)->default('unmapped');
            $table->foreignId('purchase_invoice_id')->nullable()->constrained('purchase_invoices')->nullOnDelete();
            $table->string('voucher_ref', 80)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'invoice_series', 'invoice_number'], 'purchase_received_invoice_number_unique');
            $table->index(['company_id', 'invoice_date'], 'purchase_received_invoice_date_index');
            $table->index(['company_id', 'status'], 'purchase_received_invoice_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_received_invoices');
    }
};
