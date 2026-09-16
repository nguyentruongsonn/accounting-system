<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_regime_profile_id')
                ->constrained('accounting_regime_profiles')->cascadeOnDelete();
            $table->string('policy_key', 120);
            $table->string('policy_version', 80);
            $table->string('status', 20)->default('draft');
            $table->date('effective_from');
            $table->date('effective_to');
            // These are tenant-approved contracts, never a hard-coded claim
            // that a particular accounting or tax treatment is lawful.
            $table->json('posting_rule_contract')->nullable();
            $table->json('required_dimensions')->nullable();
            $table->json('regulatory_dependencies')->nullable();
            $table->string('contract_hash', 64)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'policy_key', 'policy_version'],
                'acct_policy_company_key_version_unique'
            );
            $table->index(
                ['company_id', 'policy_key', 'status', 'effective_from', 'effective_to'],
                'acct_policy_resolution_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_policy_versions');
    }
};
