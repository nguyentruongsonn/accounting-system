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
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->string('account_code', 20);
            $table->text('description')->nullable();
            $table->bigInteger('debit_amount')->default(0);
            $table->bigInteger('credit_amount')->default(0);
            $table->nullableMorphs('sub_object'); // For tracking customer/supplier/employee etc.
            $table->timestamps();
            
            $table->index('account_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
    }
};
