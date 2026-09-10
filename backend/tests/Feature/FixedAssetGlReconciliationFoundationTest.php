<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FixedAsset;
use App\Models\FixedAssetGlReconciliationException;
use App\Models\User;
use App\Services\FixedAssetGlReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class FixedAssetGlReconciliationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! \Schema::hasTable('fixed_asset_gl_reconciliation_runs')) {
            (require database_path('migrations/2026_08_22_161240_create_fixed_asset_gl_reconciliation_foundation.php'))->up();
            (require database_path('migrations/2026_08_22_161241_enforce_fixed_asset_gl_reconciliation_append_only.php'))->up();
        }
    }

    public function test_capture_is_append_only_tenant_scoped_and_never_invents_a_balance(): void
    {
        [$company, $actor] = $this->fixture();
        $run = app(FixedAssetGlReconciliationService::class)->capture($actor, '2026-08-31');

        $this->assertSame('not_available', $run->status);
        $this->assertSame($company->id, $run->company_id);
        $this->assertSame('2026-08-31', $run->as_of_date->toDateString());
        $this->assertNull($run->snapshot['amounts']);
        $this->assertSame(64, strlen($run->contract_hash));
        $this->assertSame(64, strlen($run->snapshot_hash));
        $this->assertGreaterThan(0, FixedAssetGlReconciliationException::where('reconciliation_run_id', $run->id)->count());
        $this->expectException(LogicException::class);
        $run->update(['status' => 'available']);
    }

    public function test_it_records_missing_contracts_even_when_increment_lineage_is_provable(): void
    {
        [, $actor] = $this->fixture();
        $asset = FixedAsset::withoutGlobalScopes()->create([
            'company_id' => $actor->company_id, 'asset_code' => 'FA-GL-'.uniqid(), 'asset_name' => 'Lineage asset',
            'purchase_date' => '2026-08-10', 'voucher_date' => '2026-08-10', 'original_cost' => '120000.00', 'depreciable_cost' => '120000.00',
            'useful_life_months' => 12, 'monthly_depreciation' => '10000.00', 'accumulated_depreciation' => '0.00', 'net_value' => '120000.00',
            'asset_account' => '211', 'depreciation_account' => '2141', 'expense_account' => '6424', 'is_active' => true, 'is_posted' => true, 'status' => 'posted',
        ]);
        $journalId = DB::table('journal_entries')->insertGetId([
            'company_id' => $actor->company_id, 'fiscal_year_id' => 1, 'voucher_type' => 'fixed_asset_increment', 'voucher_number' => 'JE-FA-'.uniqid(),
            'voucher_date' => '2026-08-10', 'posting_date' => '2026-08-10', 'description' => 'fixed asset lineage', 'total_amount' => 120000,
            'status' => 'posted', 'source_document_type' => FixedAsset::class, 'source_document_id' => $asset->id, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $asset->update(['journal_entry_id' => $journalId]);

        $run = app(FixedAssetGlReconciliationService::class)->capture($actor, '2026-08-31');
        $codes = $run->exceptions->pluck('exception_code')->all();
        $this->assertSame(0, $run->source_completeness['posted_lifecycle_journal_lineage']['observed']['invalid_count']);
        $this->assertContains('fixed_asset_lifecycle_reducer_not_owner_approved', $codes);
        $this->assertContains('fixed_asset_control_account_mapping_not_owner_approved', $codes);
        $this->assertContains('fixed_asset_gl_cutoff_exception_policy_not_owner_approved', $codes);
    }

    public function test_unproven_posted_lifecycle_source_is_a_blocking_exception_not_a_zero_variance(): void
    {
        [, $actor] = $this->fixture();
        FixedAsset::withoutGlobalScopes()->create([
            'company_id' => $actor->company_id, 'asset_code' => 'FA-BROKEN-'.uniqid(), 'asset_name' => 'Broken lineage',
            'purchase_date' => '2026-08-11', 'voucher_date' => '2026-08-11', 'original_cost' => '1.00', 'depreciable_cost' => '1.00',
            'useful_life_months' => 1, 'monthly_depreciation' => '1.00', 'accumulated_depreciation' => '0.00', 'net_value' => '1.00',
            'asset_account' => '211', 'depreciation_account' => '2141', 'expense_account' => '6424', 'is_active' => true, 'is_posted' => true, 'status' => 'posted',
        ]);
        $run = app(FixedAssetGlReconciliationService::class)->capture($actor, '2026-08-31');

        $this->assertFalse($run->source_completeness['posted_lifecycle_journal_lineage']['available']);
        $this->assertSame(1, $run->source_completeness['posted_lifecycle_journal_lineage']['observed']['invalid_count']);
        $this->assertContains('fixed_asset_posted_lifecycle_lineage_unproven', $run->exceptions->pluck('exception_code')->all());
        $this->assertNull($run->snapshot['amounts']);
    }

    public function test_posted_event_without_an_accounting_date_is_not_silently_excluded_from_cutoff(): void
    {
        [, $actor] = $this->fixture();
        FixedAsset::withoutGlobalScopes()->create([
            'company_id' => $actor->company_id, 'asset_code' => 'FA-NODATE-'.uniqid(), 'asset_name' => 'Undated lifecycle',
            'purchase_date' => '2026-08-11', 'voucher_date' => null, 'original_cost' => '1.00', 'depreciable_cost' => '1.00',
            'useful_life_months' => 1, 'monthly_depreciation' => '1.00', 'accumulated_depreciation' => '0.00', 'net_value' => '1.00',
            'asset_account' => '211', 'depreciation_account' => '2141', 'expense_account' => '6424', 'is_active' => true, 'is_posted' => true, 'status' => 'posted',
        ]);
        $run = app(FixedAssetGlReconciliationService::class)->capture($actor, '2026-08-31');

        $observed = $run->source_completeness['posted_lifecycle_journal_lineage']['observed'];
        $this->assertSame(1, $observed['date_unproven_count']);
        $this->assertSame('missing_accounting_date', $observed['invalid_sources'][0]['reason']);
        $this->assertNull($run->snapshot['amounts']);
    }

    private function fixture(): array
    {
        $company = Company::query()->firstOrFail();
        return [$company, User::factory()->create(['company_id' => $company->id])];
    }
}
