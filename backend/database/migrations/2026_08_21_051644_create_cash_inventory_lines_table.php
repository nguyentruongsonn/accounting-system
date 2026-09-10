<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cash_inventory_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained('cash_inventories')->onDelete('cascade');
            $table->integer('denomination');
            $table->integer('quantity');
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_inventory_lines');
    }
};
