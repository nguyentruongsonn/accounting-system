<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_stock_counts')) {
            Schema::create('inventory_stock_counts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('count_number', 50);
                $table->date('count_date');
                $table->unsignedBigInteger('warehouse_id');
                $table->text('description')->nullable();
                $table->string('status', 20)->default('draft');
                $table->boolean('is_posted')->default(false);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'count_number']);
                $table->index(['company_id', 'count_date']);
                $table->index(['company_id', 'warehouse_id']);
            });
        }

        if (! Schema::hasTable('inventory_stock_count_lines')) {
            Schema::create('inventory_stock_count_lines', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('inventory_stock_count_id')
                    ->constrained('inventory_stock_counts')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('item_id');
                $table->string('unit', 100)->nullable();
                $table->decimal('counted_quantity', 20, 6);
                $table->text('description')->nullable();
                $table->timestamps();

                $table->unique(['inventory_stock_count_id', 'item_id'], 'stock_count_line_item_unique');
                $table->index(['item_id']);
            });
        } else {
            // The migration can be retried safely if an earlier MySQL run
            // created the tables before rejecting Laravel's long auto-name.
            Schema::table('inventory_stock_count_lines', function (Blueprint $table): void {
                $table->unique(['inventory_stock_count_id', 'item_id'], 'stock_count_line_item_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_count_lines');
        Schema::dropIfExists('inventory_stock_counts');
    }
};
