<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('approval_key', 120);
            $table->string('policy_version', 80);
            $table->string('status', 20)->default('draft');
            $table->date('effective_from');
            $table->date('effective_to');
            $table->boolean('separation_of_duties_required')->default(true);
            $table->json('steps');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'approval_key', 'policy_version'], 'approval_policy_company_key_version_unique');
            $table->index(['company_id', 'approval_key', 'status', 'effective_from', 'effective_to'], 'approval_policy_resolve_idx');
        });

        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('approval_policy_id')->constrained()->restrictOnDelete();
            $table->string('approval_key', 120);
            $table->string('subject_type', 160);
            $table->string('subject_id', 100);
            $table->string('status', 20)->default('pending');
            $table->boolean('separation_of_duties_required')->default(true);
            $table->json('policy_snapshot');
            $table->json('request_evidence')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'approval_key', 'subject_type', 'subject_id'], 'approval_request_subject_idx');
        });

        Schema::create('approval_request_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('step_order');
            $table->unsignedSmallInteger('required_approvals')->default(1);
            $table->string('status', 20)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['approval_request_id', 'step_order'], 'approval_request_step_order_unique');
        });

        Schema::create('approval_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_request_step_id')->constrained()->cascadeOnDelete();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 20);
            $table->json('evidence')->nullable();
            $table->string('evidence_hash', 64);
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->unique(['approval_request_step_id', 'decided_by'], 'approval_decision_one_per_actor_step');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_request_steps');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_policies');
    }
};
