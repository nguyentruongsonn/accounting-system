<?php

namespace Tests\Feature;

use App\Models\ApprovalDecision;
use App\Models\Company;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApprovalWorkflowFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_effective_dated_policy_creates_ordered_maker_checker_request_and_immutable_evidence(): void
    {
        $maker = User::factory()->create(['company_id' => 1]);
        $checker = User::factory()->create(['company_id' => 1]);
        $service = app(ApprovalWorkflowService::class);
        $policy = $service->createDraftPolicy($maker, 'purchase.invoice.post', '1.0', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'), [['required_approvals' => 1], ['required_approvals' => 1]]);
        $policy = $service->activatePolicy($checker, $policy);
        $request = $service->request($maker, 'purchase.invoice.post', 'purchase_invoice', 99, ['document_no' => 'PI-99'], CarbonImmutable::parse('2026-08-22'));

        try { $service->decide($maker, $request, 1, 'approved'); $this->fail('Maker must not approve own request.'); } catch (AuthorizationException) { $this->assertTrue(true); }
        $first = $service->decide($checker, $request, 1, 'approved', ['note' => 'checked']);
        $this->assertSame('pending', $first->status);
        $secondChecker = User::factory()->create(['company_id' => 1]);
        $approved = $service->decide($secondChecker, $request, 2, 'approved');
        $this->assertSame('approved', $approved->status);
        $evidence = ApprovalDecision::query()->where('approval_request_id', $request->id)->firstOrFail();
        $this->assertNotEmpty($evidence->evidence_hash);
        $this->expectException(QueryException::class);
        DB::table('approval_decisions')->where('id', $evidence->id)->update(['decision' => 'tampered']);
    }

    public function test_policy_overlap_and_cross_tenant_decision_are_rejected(): void
    {
        $maker = User::factory()->create(['company_id' => 1]); $checker = User::factory()->create(['company_id' => 1]);
        $service = app(ApprovalWorkflowService::class);
        $active = $service->createDraftPolicy($maker, 'gl.post', '1', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'), [[]]);
        $service->activatePolicy($checker, $active);
        $overlap = $service->createDraftPolicy($maker, 'gl.post', '2', CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2027-01-01'), [[]]);
        try { $service->activatePolicy($checker, $overlap); $this->fail('Overlap must fail.'); } catch (\Illuminate\Validation\ValidationException) { $this->assertTrue(true); }
        $request = $service->request($maker, 'gl.post', 'journal_entry', 1, null, CarbonImmutable::parse('2026-08-22'));
        $otherCompany = Company::query()->create(['name' => 'Other approval tenant']);
        $outsider = User::factory()->create(['company_id' => $otherCompany->id]);
        $this->expectException(AuthorizationException::class);
        $service->decide($outsider, $request, 1, 'approved');
    }
}
