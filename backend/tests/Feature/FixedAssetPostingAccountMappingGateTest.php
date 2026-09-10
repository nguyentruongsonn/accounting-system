<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FixedAsset;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\AssetRevaluation;
use App\Services\FixedAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FixedAssetPostingAccountMappingGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_asset_posting_fails_closed_without_owner_mapping(): void
    {
        $company = Company::create(['name' => 'Fixed asset mapping gate']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user);
        FiscalYear::create([
            'company_id' => $company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        config()->set('accounting.enforce_fixed_asset_posting_account_mappings', true);

        $asset = FixedAsset::create([
            'company_id' => $company->id,
            'voucher_number' => 'FA-MAPPING-GATE',
            'voucher_date' => '2026-08-20',
            'purchase_date' => '2026-08-20',
            'asset_code' => 'FA-MAPPING-GATE',
            'asset_name' => 'Mapping gate asset',
            'original_cost' => 1000,
            'depreciable_cost' => 1000,
            'useful_life_months' => 12,
            'net_value' => 1000,
            'asset_account' => '211',
            'credit_account' => '331',
            'is_active' => true,
            'is_posted' => false,
            'status' => 'draft',
        ]);

        try {
            app(FixedAssetService::class)->postAsset($company->id, $asset->id);
            $this->fail('Fixed-asset posting must fail closed without an owner-approved mapping.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('account_mappings', $exception->errors());
        }

        $this->assertDatabaseMissing('journal_entries', ['source_document_id' => $asset->id]);
        $this->assertDatabaseHas('fixed_assets', ['id' => $asset->id, 'is_posted' => false]);
    }

    public function test_posted_fixed_asset_cannot_be_deleted_as_a_void_and_delete_shortcut(): void
    {
        $company = Company::create(['name' => 'Fixed asset append-only gate']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user);
        FiscalYear::create([
            'company_id' => $company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);

        $asset = FixedAsset::create([
            'company_id' => $company->id,
            'voucher_number' => 'FA-APPEND-ONLY',
            'voucher_date' => '2026-08-20',
            'purchase_date' => '2026-08-20',
            'asset_code' => 'FA-APPEND-ONLY',
            'asset_name' => 'Append-only asset',
            'original_cost' => 1000,
            'depreciable_cost' => 1000,
            'useful_life_months' => 12,
            'net_value' => 1000,
            'is_active' => true,
            'is_posted' => true,
            'status' => 'posted',
        ]);

        try {
            app(FixedAssetService::class)->deleteAsset($company->id, $asset->id);
            $this->fail('A posted fixed asset must not be erased by a void-and-delete shortcut.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fixed_asset', $exception->errors());
        }

        $this->assertDatabaseHas('fixed_assets', [
            'id' => $asset->id,
            'is_posted' => true,
            'status' => 'posted',
        ]);
    }

    public function test_zero_cost_asset_cannot_be_created_as_posted_without_a_journal(): void
    {
        $company = Company::create(['name' => 'Fixed asset zero-cost boundary']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user);
        FiscalYear::create([
            'company_id' => $company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        $previous = config('accounting.enforce_fixed_asset_posting_account_mappings');
        config()->set('accounting.enforce_fixed_asset_posting_account_mappings', false);

        try {
            app(FixedAssetService::class)->createAsset([
                'company_id' => $company->id,
                'voucher_number' => 'FA-ZERO-COST',
                'voucher_date' => '2026-08-20',
                'purchase_date' => '2026-08-20',
                'asset_code' => 'FA-ZERO-COST',
                'asset_name' => 'Zero-cost asset',
                'original_cost' => 0,
                'depreciable_cost' => 0,
                'useful_life_months' => 12,
                'asset_account' => '211',
                'depreciation_account' => '2141',
                'expense_account' => '642',
                'credit_account' => '331',
                'is_posted' => true,
            ]);
            $this->fail('A posted fixed asset must have positive cost and a linked journal entry.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('original_cost', $exception->errors());
        } finally {
            config()->set('accounting.enforce_fixed_asset_posting_account_mappings', $previous);
        }

        $this->assertDatabaseMissing('fixed_assets', ['asset_code' => 'FA-ZERO-COST']);
        $this->assertDatabaseMissing('journal_entries', ['voucher_number' => 'GL-FA-TSCD-2026-0001']);
    }

    public function test_noop_fixed_asset_revaluation_cannot_be_marked_posted_without_a_journal(): void
    {
        $company = Company::create(['name' => 'Fixed asset no-op revaluation']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user);
        FiscalYear::create([
            'company_id' => $company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        $previous = config('accounting.enforce_fixed_asset_posting_account_mappings');
        config()->set('accounting.enforce_fixed_asset_posting_account_mappings', false);
        $asset = FixedAsset::create([
            'company_id' => $company->id,
            'voucher_number' => 'FA-NOOP-REVAL',
            'voucher_date' => '2026-08-20',
            'purchase_date' => '2026-08-20',
            'asset_code' => 'FA-NOOP-REVAL',
            'asset_name' => 'No-op revaluation asset',
            'original_cost' => 1000,
            'depreciable_cost' => 1000,
            'useful_life_months' => 12,
            'net_value' => 1000,
            'asset_account' => '211',
            'depreciation_account' => '2141',
            'expense_account' => '642',
            'credit_account' => '331',
            'is_active' => true,
            'is_posted' => false,
            'status' => 'draft',
        ]);

        try {
            app(FixedAssetService::class)->revalueAsset($company->id, $asset->id, [
                'voucher_date' => '2026-08-20',
                'new_original_cost' => 1000,
                'reason' => 'No-op regression',
            ]);
            $this->fail('A no-op revaluation must not report posted evidence without a journal entry.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('new_original_cost', $exception->errors());
        } finally {
            config()->set('accounting.enforce_fixed_asset_posting_account_mappings', $previous);
        }

        $this->assertDatabaseMissing('asset_revaluations', ['fixed_asset_id' => $asset->id]);
        $this->assertDatabaseMissing('journal_entries', ['source_document_id' => $asset->id]);
    }
}
