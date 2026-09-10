<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\PeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeriodServiceTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_period_reads_reject_an_authenticated_foreign_company(): void
    {
        $company = Company::query()->firstOrFail();
        $foreign = Company::create(['name' => 'Foreign period tenant', 'tax_code' => 'PERIOD-FOREIGN']);
        $actor = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($actor);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(PeriodService::class)->getAllForCompany($foreign->id);
    }
}
