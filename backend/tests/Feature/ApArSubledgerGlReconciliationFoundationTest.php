<?php

namespace Tests\Feature;

use App\Models\ApArSubledgerGlReconciliationException;
use App\Models\Company;
use App\Models\User;
use App\Services\ApArSubledgerGlReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ApArSubledgerGlReconciliationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The test schema snapshot can predate this bounded foundation.
        if (! \Schema::hasTable('apar_subledger_gl_reconciliation_runs')) {
            (require database_path('migrations/2026_08_22_161190_create_apar_subledger_gl_reconciliation_foundation.php'))->up();
            (require database_path('migrations/2026_08_22_161191_enforce_apar_subledger_gl_reconciliation_append_only.php'))->up();
        }
    }

    public function test_capture_is_tenant_bound_append_only_and_never_invents_a_tie_out(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $run = app(ApArSubledgerGlReconciliationService::class)->capture($actor, 'ap', '2026-08-31');

        $this->assertSame('not_available', $run->status);
        $this->assertSame('2026-08-31', $run->as_of_date->toDateString());
        $this->assertNull($run->snapshot['amounts']);
        $this->assertGreaterThan(0, $run->divergence_count);
        $this->assertSame($company->id, $run->company_id);
        $this->assertGreaterThan(0, ApArSubledgerGlReconciliationException::where('reconciliation_run_id', $run->id)->count());
        $this->expectException(LogicException::class);
        $run->update(['status' => 'available']);
    }

    public function test_required_owner_approved_contract_gates_are_always_recorded(): void
    {
        $actor = User::factory()->create(['company_id' => Company::query()->firstOrFail()->id]);
        $run = app(ApArSubledgerGlReconciliationService::class)->capture($actor, 'ar', '2026-08-31');
        $codes = $run->exceptions->pluck('exception_code')->all();
        $this->assertContains('apar_open_item_reducer_not_owner_approved', $codes);
        $this->assertContains('apar_control_account_mapping_not_owner_approved', $codes);
    }
}
