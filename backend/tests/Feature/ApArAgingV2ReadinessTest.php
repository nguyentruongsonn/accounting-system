<?php

namespace Tests\Feature;

use App\Exceptions\ReportDefinitionUnavailableException;
use App\Services\ApArAgingV2ReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApArAgingV2ReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_reports_the_exact_cutoff_and_reconciliation_evidence_gates(): void
    {
        $readiness = app(ApArAgingV2ReadinessService::class)->evaluate('ap');

        $this->assertFalse($readiness['ready']);
        $this->assertSame('accounts_payable_aging.v2', $readiness['report_key']);
        $codes = array_column($readiness['missing_conditions'], 'code');
        $this->assertContains('immutable_cutoff_snapshot_evidence_not_implemented', $codes);
        $this->assertContains('apar_subledger_to_gl_reconciliation_not_executable', $codes);
        $this->assertContains('typed_adjustment_source_contract_not_owner_approved', $codes);
        $this->assertContains('foreign_currency_open_item_roll_forward_not_integrated', $codes);
    }

    public function test_published_definition_execution_is_blocked_by_readiness_not_replaced_with_empty_rows(): void
    {
        $this->expectException(ReportDefinitionUnavailableException::class);

        app(ApArAgingV2ReadinessService::class)->assertExecutable('ar');
    }
}
