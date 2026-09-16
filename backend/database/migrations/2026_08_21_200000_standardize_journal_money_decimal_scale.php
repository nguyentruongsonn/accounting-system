<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->decimal('total_amount', 18, 2)->default(0)->change();
        });

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->decimal('debit_amount', 18, 2)->default(0)->change();
            $table->decimal('credit_amount', 18, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        // The legacy BIGINT schema cannot represent cents. Refuse rollback
        // before altering either table so a deployment can never silently
        // round or truncate posted accounting evidence.
        if ($this->hasFractionalValues('journal_entries', 'total_amount')
            || $this->hasFractionalValues('journal_entry_lines', 'debit_amount')
            || $this->hasFractionalValues('journal_entry_lines', 'credit_amount')) {
            throw new RuntimeException(
                'Cannot roll back journal money columns to BIGINT while fractional monetary values exist.'
            );
        }

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->bigInteger('total_amount')->default(0)->change();
        });

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->bigInteger('debit_amount')->default(0)->change();
            $table->bigInteger('credit_amount')->default(0)->change();
        });
    }

    private function hasFractionalValues(string $table, string $column): bool
    {
        // ROUND(value, 0) is supported by both SQLite and MySQL/MariaDB and
        // avoids routing DECIMAL values through PHP/IEEE-754 floats.
        return DB::table($table)
            ->whereRaw("{$column} <> ROUND({$column}, 0)")
            ->exists();
    }
};
