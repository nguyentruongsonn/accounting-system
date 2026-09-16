<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_valuation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('method', 32);
            $table->string('status', 32)->default('completed');
            $table->unsignedInteger('updated_issues_count')->default(0);
            $table->decimal('total_cost_amount', 18, 2)->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalidation_reason')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'from_date', 'to_date'], 'ivr_company_status_period_idx');
            $table->index(['company_id', 'warehouse_id', 'item_id'], 'ivr_company_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_valuation_runs');
    }
};
