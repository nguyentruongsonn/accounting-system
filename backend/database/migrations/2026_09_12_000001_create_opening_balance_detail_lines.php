<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opening_balance_party_lines', function (Blueprint $table): void {
            $table->index(['package_id', 'party_type', 'account_code'], 'opening_balance_party_account_lookup');
        });

        Schema::create('opening_balance_tool_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('opening_balance_packages')->cascadeOnDelete();
            $table->foreignId('tool_equipment_id')->nullable()->constrained('tool_equipments')->nullOnDelete();
            $table->string('account_code', 20);
            $table->decimal('original_cost', 18, 2);
            $table->decimal('accumulated_allocation', 18, 2)->default(0);
            $table->decimal('remaining_value', 18, 2);
            $table->timestamps();
            $table->index(['package_id', 'account_code'], 'opening_balance_tool_account_lookup');
        });

        Schema::create('opening_balance_fixed_asset_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('opening_balance_packages')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->nullable()->constrained('fixed_assets')->nullOnDelete();
            $table->string('asset_account', 20);
            $table->string('depreciation_account', 20);
            $table->decimal('original_cost', 18, 2);
            $table->decimal('accumulated_depreciation', 18, 2)->default(0);
            $table->timestamps();
            $table->index(['package_id', 'asset_account'], 'opening_balance_fixed_asset_account_lookup');
            $table->index(['package_id', 'depreciation_account'], 'opening_balance_fixed_dep_account_lookup');
        });

        Schema::create('opening_balance_prepaid_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('opening_balance_packages')->cascadeOnDelete();
            $table->string('account_code', 20);
            $table->string('description', 255);
            $table->decimal('original_amount', 18, 2);
            $table->decimal('allocated_amount', 18, 2)->default(0);
            $table->decimal('remaining_amount', 18, 2);
            $table->timestamps();
            $table->index(['package_id', 'account_code'], 'opening_balance_prepaid_account_lookup');
        });

        Schema::create('opening_balance_wip_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('opening_balance_packages')->cascadeOnDelete();
            $table->string('account_code', 20);
            $table->string('description', 255);
            $table->decimal('amount', 18, 2);
            $table->timestamps();
            $table->index(['package_id', 'account_code'], 'opening_balance_wip_account_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_wip_lines');
        Schema::dropIfExists('opening_balance_prepaid_lines');
        Schema::dropIfExists('opening_balance_fixed_asset_lines');
        Schema::dropIfExists('opening_balance_tool_lines');
        Schema::table('opening_balance_party_lines', function (Blueprint $table): void {
            $table->dropIndex('opening_balance_party_account_lookup');
        });
    }
};
