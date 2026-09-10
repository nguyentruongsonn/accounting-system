<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BorrowingContractProductionMappingBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_create_rejects_legacy_account_defaults_when_account_evidence_is_missing(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        Permission::firstOrCreate(['name' => 'borrowing.contracts.create', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor->givePermissionTo('borrowing.contracts.create');
        Sanctum::actingAs($actor);
        Config::set('app.env', 'production');

        $number = 'BORROW-PROD-MISSING-'.uniqid();

        try {
            $this->postJson('/api/v1/borrowing-contracts', [
                'contract_number' => $number,
                'lender_name' => 'Production test lender',
                'amount' => '1000000.00',
                'disbursement_date' => '2026-08-24',
                'maturity_date' => '2027-08-24',
            ])
                ->assertUnprocessable()
                ->assertJsonStructure(['errors' => ['debit_account', 'interest_account']]);
        } finally {
            Config::set('app.env', 'testing');
        }

        $this->assertDatabaseMissing('borrowing_contracts', ['contract_number' => $number]);
    }
}
