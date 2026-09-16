<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SQLite does not support MODIFY COLUMN — skip since the original
        // migration already declares the full enum including revenue_deduction.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE chart_of_accounts MODIFY COLUMN type ENUM('asset','liability','equity','revenue','expense','revenue_deduction') NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE chart_of_accounts MODIFY COLUMN type ENUM('asset','liability','equity','revenue','expense') NOT NULL");
        }
    }
};
