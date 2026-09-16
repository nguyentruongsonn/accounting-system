<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balance_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->date('effective_date');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique('company_id', 'opening_balance_company_unique');
        });

        Schema::create('opening_balance_account_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('opening_balance_packages')->cascadeOnDelete();
            $table->string('account_code', 20);
            $table->decimal('debit_amount', 18, 2)->default(0);
            $table->decimal('credit_amount', 18, 2)->default(0);
            $table->timestamps();
            $table->unique(['package_id', 'account_code'], 'opening_balance_account_unique');
        });

        Schema::create('opening_balance_party_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('opening_balance_packages')->cascadeOnDelete();
            $table->string('party_type', 20);
            $table->unsignedBigInteger('party_id');
            $table->string('account_code', 20);
            $table->string('document_number', 100)->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('debit_amount', 18, 2)->default(0);
            $table->decimal('credit_amount', 18, 2)->default(0);
            $table->timestamps();
            $table->index(['package_id', 'party_type', 'party_id'], 'opening_balance_party_lookup');
        });

        Schema::create('opening_balance_inventory_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('opening_balance_packages')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('account_code', 20);
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('total_value', 18, 2);
            $table->timestamps();
            $table->unique(['package_id', 'item_id', 'warehouse_id'], 'opening_balance_inventory_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_inventory_lines');
        Schema::dropIfExists('opening_balance_party_lines');
        Schema::dropIfExists('opening_balance_account_lines');
        Schema::dropIfExists('opening_balance_packages');
    }
};
