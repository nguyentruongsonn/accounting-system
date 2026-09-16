<?php

use App\Enums\AccountingRegime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_regime_profiles')) {
            Schema::create('accounting_regime_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->restrictOnDelete();
                $table->foreignId('fiscal_year_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('regime', 20);
                $table->date('effective_from');
                $table->date('effective_to');
                $table->string('legal_source');
                $table->json('metadata')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['company_id', 'fiscal_year_id']);
                // MySQL limits identifiers to 64 characters. An explicit
                // short name also makes an interrupted migration restartable.
                $table->index(['company_id', 'effective_from', 'effective_to'], 'acct_regime_company_effective_idx');
            });
        } else {
            // The first production attempt could create the table before
            // MySQL rejected Laravel's generated index name. Complete that
            // safe partial state when this still-pending migration is rerun.
            Schema::table('accounting_regime_profiles', function (Blueprint $table) {
                $table->index(['company_id', 'effective_from', 'effective_to'], 'acct_regime_company_effective_idx');
            });
        }

        $now = now();
        foreach (DB::table('fiscal_years')->orderBy('id')->get() as $fiscalYear) {
            $regime = AccountingRegime::forFiscalYearStart((string) $fiscalYear->start_date);
            DB::table('accounting_regime_profiles')->updateOrInsert([
                'fiscal_year_id' => $fiscalYear->id,
            ], [
                'company_id' => $fiscalYear->company_id,
                'regime' => $regime->value,
                'effective_from' => $fiscalYear->start_date,
                'effective_to' => $fiscalYear->end_date,
                'legal_source' => $regime->label(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_regime_profiles');
    }
};
