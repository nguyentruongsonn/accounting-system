<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('reversal_of_id')->nullable()->unique()
                ->after('source_document_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversed_by_entry_id')->nullable()->unique()
                ->after('reversal_of_id')->constrained('journal_entries')->restrictOnDelete();
            $table->text('reversal_reason')->nullable()->after('reversed_by_entry_id');
            $table->foreignId('reversed_by')->nullable()->after('reversal_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')
                ->constrained('companies')->restrictOnDelete();
            $table->uuid('correlation_id')->nullable()->after('company_id')->index();
            $table->json('metadata')->nullable()->after('new_values');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn(['company_id', 'correlation_id', 'metadata']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['reversal_of_id']);
            $table->dropForeign(['reversed_by_entry_id']);
            $table->dropForeign(['reversed_by']);
            $table->dropColumn([
                'reversal_of_id',
                'reversed_by_entry_id',
                'reversal_reason',
                'reversed_by',
                'reversed_at',
            ]);
        });
    }
};
