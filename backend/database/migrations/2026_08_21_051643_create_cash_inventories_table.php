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
        Schema::create('cash_inventories', function (Blueprint $table) {
            $table->id();
            $table->string('audit_number', 50)->unique();
            $table->date('audit_date');
            $table->string('purpose', 255)->nullable();
            $table->string('currency', 10)->default('VND');
            $table->decimal('book_balance', 15, 2)->default(0);
            $table->decimal('actual_balance', 15, 2)->default(0);
            $table->decimal('difference', 15, 2)->default(0);
            $table->string('status', 50)->default('Draft');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_inventories');
    }
};
