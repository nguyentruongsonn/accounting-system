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
        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('period')->nullable();       // month number 1-12
            $table->unsignedTinyInteger('period_number')->nullable(); // alias used by PeriodService
            $table->string('name')->nullable();                       // "Tháng 01/2026"
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->boolean('is_closed')->default(false);             // alias for status
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('periods');
    }
};
