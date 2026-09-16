<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approved_account_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_policy_version_id')->constrained()->restrictOnDelete();
            $table->string('mapping_key', 120);
            // The normalized hash makes a structured context safe to resolve
            // without treating JSON formatting/order as business meaning.
            $table->json('mapping_context');
            $table->char('context_hash', 64);
            $table->string('account_role', 80);
            // Store the owner-approved code, not an assumed statutory chart.
            $table->string('account_code', 30);
            $table->string('status', 20)->default('draft');
            $table->date('effective_from');
            $table->date('effective_to');
            $table->json('regulatory_dependencies')->nullable();
            $table->char('contract_hash', 64)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'mapping_key', 'context_hash', 'account_role', 'status', 'effective_from', 'effective_to'], 'approved_account_mapping_resolution_idx');
            $table->index(['accounting_policy_version_id', 'status'], 'approved_account_mapping_policy_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approved_account_mappings');
    }
};
