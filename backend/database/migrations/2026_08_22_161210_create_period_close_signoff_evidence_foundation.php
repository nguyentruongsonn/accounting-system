<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_close_signoff_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('policy_version', 80);
            $table->string('status', 20)->default('draft');
            $table->date('effective_from');
            $table->date('effective_to');
            // These roles are a company-owned control configuration. They do
            // not assert that a particular statutory role is always required.
            $table->json('reviewer_roles');
            $table->boolean('separation_of_duties_required')->default(true);
            $table->string('approval_key', 120)->default('gl.period-close.signoff');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'policy_version'], 'close_signoff_policy_company_version_unique');
            $table->index(['company_id', 'status', 'effective_from', 'effective_to'], 'close_signoff_policy_resolve_idx');
        });

        Schema::create('period_close_signoff_packages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('period_id')->constrained()->restrictOnDelete();
            $table->foreignId('period_close_readiness_snapshot_id')
                ->constrained('period_close_readiness_snapshots', 'id', 'close_signoff_package_readiness_fk')
                ->restrictOnDelete();
            $table->foreignId('period_close_signoff_policy_id')
                ->constrained('period_close_signoff_policies', 'id', 'close_signoff_package_policy_fk')
                ->restrictOnDelete();
            $table->char('readiness_snapshot_hash', 64);
            // Evidence cutoff is the readiness-evaluation instant. It is not
            // a claim that ledger input has been frozen.
            $table->timestamp('evidence_cutoff_at');
            $table->json('policy_snapshot');
            $table->json('package_snapshot');
            $table->char('package_hash', 64);
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('prepared_at');
            $table->timestamps();
            $table->index(['company_id', 'period_id', 'created_at'], 'close_signoff_package_period_idx');
        });

        Schema::create('period_close_signoff_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('period_close_signoff_package_id')
                ->constrained('period_close_signoff_packages', 'id', 'close_signoff_event_package_fk')
                ->restrictOnDelete();
            $table->string('event_type', 30);
            $table->foreignId('approval_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('evidence')->nullable();
            $table->char('event_hash', 64);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['period_close_signoff_package_id', 'id'], 'close_signoff_event_package_idx');
            $table->unique(['period_close_signoff_package_id', 'event_type'], 'close_signoff_event_once_per_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_close_signoff_events');
        Schema::dropIfExists('period_close_signoff_packages');
        Schema::dropIfExists('period_close_signoff_policies');
    }
};
