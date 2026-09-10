<?php

namespace Tests\Feature;

use App\Models\ApArSubledgerGlReconciliationRun;
use App\Models\Company;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApArReconciliationInputBoundaryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! \Schema::hasColumn('apar_subledger_gl_reconciliation_runs', 'input_boundary')) {
            (require database_path('migrations/2026_08_22_161190_create_apar_subledger_gl_reconciliation_foundation.php'))->up();
            (require database_path('migrations/2026_08_22_161191_enforce_apar_subledger_gl_reconciliation_append_only.php'))->up();
            (require database_path('migrations/2026_08_23_090000_add_input_boundary_to_apar_subledger_gl_reconciliation_runs.php'))->up();
        }
        Carbon::setTestNow(Carbon::parse('2026-08-31 17:00:00 UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_capture_is_tenant_bound_deterministic_append_only_and_has_no_amount_or_close_authority(): void
    {
        [$actor, $other] = $this->actors();
        $this->actingAs($actor)->postJson('/api/v1/ap-ar/reconciliation-input-boundaries', ['ledger' => 'ap', 'as_of_date' => '2026-08-31', 'company_id' => $other->company_id, 'amounts' => ['forged' => '1']])
            ->assertStatus(422);

        $response = $this->actingAs($actor)->postJson('/api/v1/ap-ar/reconciliation-input-boundaries', ['ledger' => 'ap', 'as_of_date' => '2026-08-31'])
            ->assertCreated()->assertJsonPath('data.status', 'not_available')->assertJsonPath('data.amounts', null)->assertJsonPath('data.amounts_calculated', false)->assertJsonPath('data.tie_out_calculated', false)->assertJsonPath('data.close_authority', false);
        $run = ApArSubledgerGlReconciliationRun::withoutGlobalScopes()->where('uuid', $response->json('data.uuid'))->firstOrFail();
        $this->assertSame($actor->company_id, $run->company_id);
        $this->assertSame('captured_consistent_snapshot', $run->input_boundary['state']);
        $this->assertSame('2026-08-31T17:00:00.000000Z', $run->input_boundary['input_cutoff_at']);
        $this->assertArrayHasKey('open_item_candidates', $run->input_boundary['sources']);
        $this->assertNull($run->snapshot['amounts']);
        try {
            $run->update(['status' => 'available']);
            $this->fail('Eloquent must not update append-only evidence.');
        } catch (\LogicException) {
            // Expected application-layer guard.
        }
        try {
            \DB::table('apar_subledger_gl_reconciliation_runs')->where('id', $run->id)->update(['status' => 'available']);
            $this->fail('The database trigger must reject direct evidence mutation.');
        } catch (QueryException) {
            // Expected database-layer guard.
        }
        $this->assertDatabaseHas('audit_logs', ['company_id' => $actor->company_id, 'action' => 'apar_subledger_gl_reconciliation.captured', 'model_id' => $run->id]);
    }

    public function test_foreign_tenant_cannot_view_evidence_and_capture_permission_is_separate(): void
    {
        [$actor, $other] = $this->actors();
        $created = $this->actingAs($actor)->postJson('/api/v1/ap-ar/reconciliation-input-boundaries', ['ledger' => 'ar', 'as_of_date' => '2026-08-31'])->assertCreated();
        $this->actingAs($other)->getJson('/api/v1/ap-ar/reconciliation-input-boundaries/'.$created->json('data.uuid'))->assertNotFound();

        $unprivileged = User::factory()->create(['company_id' => $actor->company_id]);
        $unprivileged->assignRole(Role::findOrCreate('accountant', 'web'));
        $this->actingAs($unprivileged)->postJson('/api/v1/ap-ar/reconciliation-input-boundaries', ['ledger' => 'ap', 'as_of_date' => '2026-08-31'])->assertForbidden();
        $unprivileged->givePermissionTo('apar.reconciliations.view');
        $this->actingAs($unprivileged)->getJson('/api/v1/ap-ar/reconciliation-input-boundaries')->assertOk();
    }

    public function test_same_fixed_cutoff_recaptures_new_append_only_evidence_with_same_source_fingerprints(): void
    {
        [$actor] = $this->actors();
        $one = $this->actingAs($actor)->postJson('/api/v1/ap-ar/reconciliation-input-boundaries', ['ledger' => 'ap', 'as_of_date' => '2026-08-31'])->assertCreated()->json('data');
        $two = $this->actingAs($actor)->postJson('/api/v1/ap-ar/reconciliation-input-boundaries', ['ledger' => 'ap', 'as_of_date' => '2026-08-31'])->assertCreated()->json('data');
        $this->assertNotSame($one['uuid'], $two['uuid']);
        $this->assertSame($one['input_boundary']['boundary_hash'], $two['input_boundary']['boundary_hash']);
        $this->assertSame($one['snapshot_hash'], $two['snapshot_hash']);
        $this->assertSame(2, ApArSubledgerGlReconciliationRun::withoutGlobalScopes()->where('company_id', $actor->company_id)->count());
    }

    public function test_input_boundary_counts_only_authenticated_tenant_rows_at_or_before_cutoff(): void
    {
        [$actor, $other] = $this->actors();
        $actorSupplier = $this->supplier($actor->company_id, 'APAR-ACTOR');
        $otherSupplier = $this->supplier($other->company_id, 'APAR-OTHER');
        $timestamps = ['created_at' => '2026-08-31 16:00:00', 'updated_at' => '2026-08-31 16:00:00'];

        // One eligible source row for the authenticated tenant.
        DB::table('purchase_invoices')->insert($timestamps + [
            'company_id' => $actor->company_id, 'supplier_id' => $actorSupplier,
            'invoice_number' => 'APAR-ACTOR-ELIGIBLE', 'invoice_date' => '2026-08-30',
            'accounting_date' => '2026-08-30', 'status' => 'Unpaid', 'is_posted' => true,
            'sub_total' => 100, 'tax_amount' => 0, 'total_amount' => 100,
        ]);
        // A future-dated row must not enter an 2026-08-31 boundary.
        DB::table('purchase_invoices')->insert($timestamps + [
            'company_id' => $actor->company_id, 'supplier_id' => $actorSupplier,
            'invoice_number' => 'APAR-ACTOR-FUTURE', 'invoice_date' => '2026-09-01',
            'accounting_date' => '2026-09-01', 'status' => 'Unpaid', 'is_posted' => true,
            'sub_total' => 200, 'tax_amount' => 0, 'total_amount' => 200,
        ]);
        // A same-cutoff row belonging to another tenant must not enter either
        // the count or high-water mark for the authenticated actor.
        DB::table('purchase_invoices')->insert($timestamps + [
            'company_id' => $other->company_id, 'supplier_id' => $otherSupplier,
            'invoice_number' => 'APAR-OTHER-ELIGIBLE', 'invoice_date' => '2026-08-30',
            'accounting_date' => '2026-08-30', 'status' => 'Unpaid', 'is_posted' => true,
            'sub_total' => 300, 'tax_amount' => 0, 'total_amount' => 300,
        ]);

        $data = $this->actingAs($actor)
            ->postJson('/api/v1/ap-ar/reconciliation-input-boundaries', ['ledger' => 'ap', 'as_of_date' => '2026-08-31'])
            ->assertCreated()->json('data');
        $source = $data['input_boundary']['sources']['open_item_candidates'];

        $this->assertSame(1, $source['count']);
        $this->assertSame('purchase_invoices', $source['table']);
        $this->assertSame('accounting_date', $source['candidate_date_column']);
    }

    private function supplier(int $companyId, string $code): int
    {
        return (int) DB::table('suppliers')->insertGetId([
            'company_id' => $companyId, 'code' => $code.'-'.uniqid('', true), 'name' => $code,
            'is_active' => true, 'created_at' => '2026-08-31 16:00:00', 'updated_at' => '2026-08-31 16:00:00',
        ]);
    }

    /** @return array{User,User} */
    private function actors(): array
    {
        $company = Company::query()->firstOrFail();
        $otherCompany = Company::query()->create(['name' => 'Tenant boundary '.uniqid('', true)]);
        Permission::findOrCreate('apar.reconciliations.view', 'web');
        Permission::findOrCreate('apar.reconciliations.capture', 'web');
        $actor = User::factory()->create(['company_id' => $company->id]);
        $actor->givePermissionTo(['apar.reconciliations.view', 'apar.reconciliations.capture']);
        $other = User::factory()->create(['company_id' => $otherCompany->id]);
        $other->givePermissionTo(['apar.reconciliations.view', 'apar.reconciliations.capture']);
        // Protected API sessions must carry one of the two canonical roles.
        // Keep this tenant-isolation fixture focused on the company boundary,
        // not on the separate active-account authentication gate.
        $accountant = Role::findOrCreate('accountant', 'web');
        $actor->assignRole($accountant);
        $other->assignRole($accountant);
        return [$actor, $other];
    }
}
