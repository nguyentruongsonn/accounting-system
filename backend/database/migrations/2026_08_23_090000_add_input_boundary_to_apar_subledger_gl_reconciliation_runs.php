<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apar_subledger_gl_reconciliation_runs', function (Blueprint $table): void {
            $table->timestamp('input_cutoff_at')->nullable()->after('snapshot_hash');
            $table->json('input_boundary')->nullable()->after('input_cutoff_at');
        });
    }

    public function down(): void
    {
        Schema::table('apar_subledger_gl_reconciliation_runs', function (Blueprint $table): void {
            $table->dropColumn(['input_cutoff_at', 'input_boundary']);
        });
    }
};
