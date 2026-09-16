<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounting_policy_versions', 'created_by')) {
            Schema::table('accounting_policy_versions', function (Blueprint $table): void {
                $table->foreignId('created_by')->nullable()->after('company_id')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('accounting_policy_versions', 'created_by')) {
            Schema::table('accounting_policy_versions', function (Blueprint $table): void {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            });
        }
    }
};
