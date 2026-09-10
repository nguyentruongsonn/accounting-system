<?php

namespace Tests\Feature;

use App\Models\Company;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PurchaseTestDataSeeder;
use Database\Seeders\SampleReferenceVoucherSeeder;
use Database\Seeders\VoucherTypeSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sample_seeders_reject_production_before_any_database_query(): void
    {
        $previous = app()->environment();
        app()->instance('env', 'production');
        DB::enableQueryLog();
        try {
            foreach ([PurchaseTestDataSeeder::class, SampleReferenceVoucherSeeder::class] as $class) {
                DB::flushQueryLog();
                $caught = null;
                try {
                    app($class)->run();
                } catch (\RuntimeException $error) {
                    $caught = $error;
                }
                $this->assertNotNull($caught, $class.' must reject production.');
                $this->assertStringContainsString('local/testing', $caught->getMessage());
                $this->assertSame([], DB::getQueryLog());
            }
        } finally {
            DB::disableQueryLog();
            app()->instance('env', $previous);
        }
    }

    public function test_chart_seed_preserves_existing_accounts_and_company_identity(): void
    {
        $this->removeBootstrapTenant();
        $company = Company::create(['name' => 'SIM-ENTITY-001 — Doanh nghiệp mô phỏng', 'address' => 'Existing address']);
        $account = \App\Models\ChartOfAccount::create([
            'company_id' => $company->id, 'code' => '111', 'name' => 'Custom account',
            'type' => 'asset', 'nature' => 'credit', 'level' => 1, 'is_parent' => false,
            'is_active' => false, 'description' => 'Keep this',
        ]);
        $sample = $account->replicate();
        $sample->code = 'ACC_A';
        $sample->save();
        $account->delete();
        $before = $account->fresh()->getAttributes();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->assertSame($before, $account->fresh()->getAttributes());
        $this->assertNotNull($sample->fresh());
        $this->assertSame('SIM-ENTITY-001 — Doanh nghiệp mô phỏng', $company->fresh()->name);
        $this->assertSame('Existing address', $company->fresh()->address);
        $this->assertDatabaseHas('chart_of_accounts', ['company_id' => $company->id, 'code' => '1111']);
    }

    public function test_chart_seed_refuses_an_ambiguous_company_before_writing(): void
    {
        $this->removeBootstrapTenant();
        Company::create(['name' => 'First']);
        Company::create(['name' => 'Second']);
        try {
            $this->seed(ChartOfAccountsSeeder::class);
            $this->fail('Ambiguous company must be rejected.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('company', $error->getMessage());
        }
        $this->assertDatabaseCount('chart_of_accounts', 0);
    }

    private function removeBootstrapTenant(): void
    {
        DB::table('fiscal_years')->delete();
        DB::table('companies')->delete();
    }

    public function test_voucher_type_settings_seeder_never_creates_a_simulated_tenant(): void
    {
        $this->removeBootstrapTenant();

        $this->seed(VoucherTypeSettingSeeder::class);

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('voucher_type_settings', 0);
        $this->assertDatabaseMissing('companies', ['name' => 'SIM-ENTITY-001 — Doanh nghiệp mô phỏng']);
    }

    public function test_chart_of_accounts_fallback_uses_a_neutral_company_profile(): void
    {
        $this->removeBootstrapTenant();

        $this->seed(ChartOfAccountsSeeder::class);

        $company = Company::query()->firstOrFail();
        $this->assertSame('Doanh nghiệp của tôi', $company->name);
        $this->assertNull($company->address);
        $this->assertStringNotContainsString('SIMULATED_DATA', (string) $company->address);
        $this->assertDatabaseMissing('companies', ['name' => 'SIM-ENTITY-001 — Doanh nghiệp mô phỏng']);
    }

    public function test_sample_seeders_never_pollute_an_operational_company(): void
    {
        $this->removeBootstrapTenant();

        $this->seed(ChartOfAccountsSeeder::class);
        $company = Company::query()->firstOrFail();

        $this->seed(SampleReferenceVoucherSeeder::class);
        $this->seed(PurchaseTestDataSeeder::class);

        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'Doanh nghiệp của tôi',
        ]);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseCount('sales_invoices', 0);
        $this->assertDatabaseCount('purchase_invoices', 0);
        $this->assertDatabaseMissing('companies', ['name' => 'SIM-ENTITY-001 — Doanh nghiệp mô phỏng']);
    }
}
