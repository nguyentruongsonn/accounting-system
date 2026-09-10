<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('transfer_number', 50);
            $table->date('transfer_date');
            $table->unsignedBigInteger('from_warehouse_id');
            $table->unsignedBigInteger('to_warehouse_id');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('is_posted')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'transfer_number']);
            $table->index(['company_id', 'transfer_date']);
            $table->index(['company_id', 'from_warehouse_id']);
            $table->index(['company_id', 'to_warehouse_id']);
        });

        Schema::create('inventory_transfer_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_transfer_id')
                ->constrained('inventory_transfers')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->string('unit', 100)->nullable();
            $table->decimal('quantity', 20, 6);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_lines');
        Schema::dropIfExists('inventory_transfers');
    }
};
