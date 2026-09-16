<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_dimension_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // A tenant chooses the semantics.  This foundation deliberately
            // does not infer that "department", "project", etc. is required.
            $table->string('code', 80);
            $table->string('name', 180);
            $table->string('status', 20)->default('active');
            $table->date('effective_from');
            $table->date('effective_to');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'acct_dimension_definition_company_code_unique');
            $table->index(['company_id', 'status', 'effective_from', 'effective_to'], 'acct_dimension_definition_effective_idx');
        });

        Schema::create('accounting_dimension_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_dimension_definition_id')
                ->constrained('accounting_dimension_definitions', 'id', 'acct_dim_value_definition_fk')->restrictOnDelete();
            $table->string('code', 120);
            $table->string('name', 255);
            $table->string('status', 20)->default('active');
            $table->date('effective_from');
            $table->date('effective_to');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['accounting_dimension_definition_id', 'code'], 'acct_dimension_value_definition_code_unique');
            $table->index(['company_id', 'accounting_dimension_definition_id', 'status'], 'acct_dimension_value_company_definition_idx');
        });

        Schema::create('accounting_policy_dimension_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_policy_version_id')
                ->constrained('accounting_policy_versions', 'id', 'acct_policy_dim_requirement_policy_fk')->restrictOnDelete();
            $table->foreignId('accounting_dimension_definition_id')
                ->constrained('accounting_dimension_definitions', 'id', 'acct_policy_dim_requirement_definition_fk')->restrictOnDelete();
            $table->boolean('is_required')->default(true);
            // Kept effective-dated independently so a successor requirement
            // can be prepared without modifying immutable policy evidence.
            $table->date('effective_from');
            $table->date('effective_to');
            $table->timestamps();

            $table->unique(['accounting_policy_version_id', 'accounting_dimension_definition_id'], 'acct_policy_dimension_requirement_unique');
            $table->index(['company_id', 'accounting_policy_version_id', 'is_required', 'effective_from', 'effective_to'], 'acct_policy_dimension_requirement_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_policy_dimension_requirements');
        Schema::dropIfExists('accounting_dimension_values');
        Schema::dropIfExists('accounting_dimension_definitions');
    }
};
