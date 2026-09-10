<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movement_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('movement_date');
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->string('movement_type', 32);
            $table->decimal('quantity_delta', 20, 4);
            $table->decimal('amount_delta', 18, 2)->nullable();
            $table->string('source_type', 160);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'item_id', 'warehouse_id', 'movement_date'], 'ime_company_item_wh_date_idx');
            $table->unique(['source_type', 'source_id', 'source_line_id', 'warehouse_id', 'movement_type'], 'ime_source_warehouse_type_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movement_events');
    }
};
