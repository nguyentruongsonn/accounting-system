<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Period;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\AcademicSimulationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicSimulationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_bootstrap_creates_only_the_two_canonical_simulation_identities(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(AcademicSimulationSeeder::class);

        $company = Company::query()->where('name', 'SIM-ENTITY-001 — Doanh nghiệp mô phỏng')->firstOrFail();

        $admin = User::query()->where('email', 'sim.admin@accounting.local')->firstOrFail();
        $accountant = User::query()->where('email', 'sim.operator@accounting.local')->firstOrFail();

        $this->assertSame($company->id, $admin->company_id);
        $this->assertSame($company->id, $accountant->company_id);
        $this->assertSame(['admin'], $admin->getRoleNames()->all());
        $this->assertSame(['accountant'], $accountant->getRoleNames()->all());
        $this->assertCount(0, $admin->getDirectPermissions());
        $this->assertCount(0, $accountant->getDirectPermissions());
        $this->assertSame(2, Customer::query()->where('company_id', $company->id)->count());
        $this->assertSame(2, Supplier::query()->where('company_id', $company->id)->count());
        $this->assertSame(2, Item::query()->where('company_id', $company->id)->count());
        $this->assertSame(2, Warehouse::query()->where('company_id', $company->id)->count());
        $fiscalYear = FiscalYear::withoutGlobalScopes()->where('company_id', $company->id)->where('year', 2026)->firstOrFail();
        $this->assertSame(2, Period::query()->where('fiscal_year_id', $fiscalYear->id)->whereIn('period', [1, 2])->count());
        $this->assertSame(
            [$admin->id, $accountant->id],
            User::query()->where('company_id', $company->id)->orderBy('id')->pluck('id')->all(),
        );
    }

    public function test_catalogue_collision_aborts_before_creating_partial_simulation_rows(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $existingCompany = Company::create(['name' => 'Existing local company']);
        Customer::create([
            'company_id' => $existingCompany->id,
            'code' => 'SIM-CUSTOMER-001',
            'name' => 'Existing code',
            'customer_type' => 'org',
            'is_customer' => true,
            'is_active' => true,
        ]);

        try {
            $this->seed(AcademicSimulationSeeder::class);
            $this->fail('A global SIM catalogue collision must fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('SIM-CUSTOMER-001', $exception->getMessage());
        }

        $this->assertDatabaseMissing('companies', ['name' => 'SIM-ENTITY-001 — Doanh nghiệp mô phỏng']);
        $this->assertDatabaseMissing('users', ['email' => 'sim.admin@accounting.local']);
    }
}
