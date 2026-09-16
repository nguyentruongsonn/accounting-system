<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('periods', function (Blueprint $table): void {
            if (! Schema::hasColumn('periods', 'reopened_at')) {
                $table->timestamp('reopened_at')->nullable()->after('is_closed');
            }
            if (! Schema::hasColumn('periods', 'reopened_by')) {
                $table->foreignId('reopened_by')->nullable()->after('reopened_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('periods', 'reopen_reason')) {
                $table->text('reopen_reason')->nullable()->after('reopened_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('periods', function (Blueprint $table): void {
            foreach (['reopened_by', 'reopen_reason', 'reopened_at'] as $column) {
                if (Schema::hasColumn('periods', $column)) {
                    if ($column === 'reopened_by') {
                        $table->dropForeign(['reopened_by']);
                    }
                    $table->dropColumn($column);
                }
            }
        });
    }
};
