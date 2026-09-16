<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Closing-rule codes identify a rule within a tenant, not globally.
     * The original catalogue migration used a global unique index, which
     * prevented two companies from using the same legitimate code.
     */
    public function up(): void
    {
        if (! Schema::hasTable('closing_rules')) {
            return;
        }

        Schema::table('closing_rules', function (Blueprint $table): void {
            $table->dropUnique('closing_rules_rule_code_unique');
            $table->unique(['company_id', 'rule_code'], 'closing_rules_company_rule_code_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('closing_rules')) {
            return;
        }

        Schema::table('closing_rules', function (Blueprint $table): void {
            $table->dropUnique('closing_rules_company_rule_code_unique');
            $table->unique('rule_code', 'closing_rules_rule_code_unique');
        });
    }
};
