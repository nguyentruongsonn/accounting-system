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
        Schema::create('inventory_issue_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_issue_id')->constrained('inventory_issues')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->integer('quantity')->default(1);
            $table->bigInteger('unit_price')->default(0); // This will be calculated by COGS Service
            $table->bigInteger('amount')->default(0);
            $table->string('debit_account', 20)->default('632');
            $table->string('credit_account', 20)->default('156');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_issue_lines');
    }
};
