<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_allocations', function (Blueprint $table): void {
            $table->string('allocation_direction', 16)->default('reduction')->after('allocation_kind');
            $table->foreignId('reverses_allocation_id')
                ->nullable()
                ->after('allocation_direction')
                ->constrained('settlement_allocations')
                ->restrictOnDelete();
            $table->unique(['reverses_allocation_id'], 'settlement_allocations_one_reversal_unique');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_allocations', function (Blueprint $table): void {
            $table->dropUnique('settlement_allocations_one_reversal_unique');
            $table->dropConstrainedForeignId('reverses_allocation_id');
            $table->dropColumn('allocation_direction');
        });
    }
};
