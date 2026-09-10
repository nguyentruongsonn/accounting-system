<?php

namespace Tests\Feature;

use App\Models\AllocationLog;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\CostAllocation;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Period;
use App\Models\ToolEquipment;
use App\Services\CostingService;
use App\Services\ToolsEquipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Source-service tests: controllers already scope company IDs, but these
 * protect worker/command callers from changing allocation evidence before a
 * downstream journal-entry guard is reached.
 */
class AllocationClosedPeriodSourceMutationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    public function test_closed_month_blocks_costing_before_prior_allocation_evidence_is_replaced(): void
    {
        $company = $this->company('Closed costing tenant');
        $this->period($company, true);
        $orderId = $this->productionOrder($company, 'CLOSED-COST-001');
        $existing = CostAllocation::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'production_order_id' => $orderId,
            'month' => '2026-08',
            'direct_material_cost' => 10,
            'direct_labor_cost' => 20,
            'manufacturing_overhead' => 30,
            'wip_beginning' => 0,
            'wip_ending' => 0,
            'total_cost' => 60,
            'is_posted' => true,
        ]);

        $this->assertClosed(fn () => app(CostingService::class)->allocateCosts($company->id, '2026-08'));

        $this->assertDatabaseHas('cost_allocations', [
            'id' => $existing->id,
            'company_id' => $company->id,
            'month' => '2026-08',
            'total_cost' => 60,
        ]);
        $this->assertDatabaseCount('cost_allocations', 1);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_closed_month_blocks_tool_allocation_before_balance_or_evidence_mutation(): void
    {
        $company = $this->company('Closed tools tenant');
        $this->period($company, true);
        $tool = ToolEquipment::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'tool_code' => 'CLOSED-TOOL-'.$company->id,
            'tool_name' => 'Closed-period tool',
            'purchase_date' => '2026-08-01',
            'original_cost' => 120,
            'allocation_months' => 12,
            'monthly_allocation' => 10,
            'accumulated_allocation' => 0,
            'remaining_value' => 120,
            'tool_account' => '242',
            'expense_account' => '6423',
            'is_active' => true,
        ]);

        $this->assertClosed(fn () => app(ToolsEquipmentService::class)->runMonthlyAllocation($company->id, '2026-08'));

        $tool->refresh();
        $this->assertSame(0, $tool->accumulated_allocation);
        $this->assertSame(120, $tool->remaining_value);
        $this->assertTrue($tool->is_active);
        $this->assertDatabaseCount('allocation_logs', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_production_tool_allocation_fails_closed_before_source_mutation_without_mapping_policy(): void
    {
        $company = $this->company('Production tools mapping tenant');
        $tool = ToolEquipment::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'tool_code' => 'PROD-TOOL-'.$company->id,
            'tool_name' => 'Production mapping-boundary tool',
            'purchase_date' => '2026-08-01',
            'original_cost' => 120,
            'allocation_months' => 12,
            'monthly_allocation' => 10,
            'accumulated_allocation' => 0,
            'remaining_value' => 120,
            'tool_account' => '242',
            'expense_account' => '6423',
            'is_active' => true,
        ]);

        $previousEnv = config('app.env');
        config(['app.env' => 'production']);
        try {
            app(ToolsEquipmentService::class)->runMonthlyAllocation($company->id, '2026-08');
            $this->fail('Production tool allocation must fail closed without an approved mapping resolver.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('account_mappings', $exception->errors());
        } finally {
            config(['app.env' => $previousEnv]);
        }

        $tool->refresh();
        $this->assertSame(0, $tool->accumulated_allocation);
        $this->assertSame(120, $tool->remaining_value);
        $this->assertDatabaseCount('allocation_logs', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_foreign_tenant_close_does_not_block_open_tenant_before_its_own_validation(): void
    {
        $openCompany = $this->company('Open costing tenant');
        $foreignClosedCompany = $this->company('Foreign closed costing tenant');
        $this->period($openCompany, false);
        $this->period($foreignClosedCompany, true);

        try {
            app(CostingService::class)->allocateCosts($openCompany->id, '2026-08');
            $this->fail('The open tenant has no orders and must reach its own validation.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Không có lệnh sản xuất nào', $exception->getMessage());
        }

        // A tenant B close must not be interpreted as tenant A's close.  The
        // service got past the accounting-period guard and failed only on
        // tenant A's separate, non-mutating production-order validation.
        $this->assertDatabaseCount('cost_allocations', 0);
        $this->assertDatabaseMissing('cost_allocations', ['company_id' => $foreignClosedCompany->id]);
    }

    public function test_open_month_costing_aggregates_621_622_and_627_prefixes_on_sqlite(): void
    {
        // The test suite runs on SQLite. It exercises the real GL aggregation
        // path that historically used MySQL-only LEFT(account_code, 3), while
        // retaining company and open-period checks around the calculation.
        $company = $this->company('SQLite costing aggregation tenant');
        $fiscalYear = $this->fiscalYear($company);
        $this->periodForFiscalYear($fiscalYear, false);
        $orderId = $this->productionOrder($company, 'SQLITE-COST-001');
        $this->activeCostingAccounts($company);

        $source = JournalEntry::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'fiscal_year_id' => $fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'SQLITE-COST-SOURCE-'.$company->id,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Posted production cost source',
            'total_amount' => 600,
            'status' => 'posted',
        ]);
        foreach ([['6211', 100], ['6222', 200], ['6278', 300]] as [$accountCode, $amount]) {
            JournalEntryLine::create([
                'journal_entry_id' => $source->id,
                'account_code' => $accountCode,
                'debit_amount' => $amount,
                'credit_amount' => 0,
            ]);
        }

        $allocations = app(CostingService::class)->allocateCosts($company->id, '2026-08');

        $this->assertCount(1, $allocations);
        $allocation = $allocations[0]->fresh();
        $this->assertSame(100, (int) $allocation->direct_material_cost);
        $this->assertSame(200, (int) $allocation->direct_labor_cost);
        $this->assertSame(300, (int) $allocation->manufacturing_overhead);
        $this->assertSame(600, (int) $allocation->total_cost);
        $this->assertSame($orderId, (int) $allocation->production_order_id);
        $this->assertNotNull($allocation->journal_entry_id);
    }

    public function test_zero_cost_month_cannot_persist_posted_allocation_without_a_journal_entry(): void
    {
        $company = $this->company('Zero-cost accounting tenant');
        $fiscalYear = $this->fiscalYear($company);
        $this->periodForFiscalYear($fiscalYear, false);
        $this->productionOrder($company, 'ZERO-COST-001');
        $this->activeCostingAccounts($company);

        try {
            app(CostingService::class)->allocateCosts($company->id, '2026-08');
            $this->fail('A zero-cost month must not report posted allocation evidence without a GL journal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('accounting', $exception->errors());
        }

        $this->assertDatabaseCount('cost_allocations', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_fractional_gl_cost_fails_closed_before_integer_allocation_rounding(): void
    {
        config(['accounting.enforce_costing_integer_allocation_evidence' => true]);

        $company = $this->company('Fractional costing tenant');
        $fiscalYear = $this->fiscalYear($company);
        $this->periodForFiscalYear($fiscalYear, false);
        $orderId = $this->productionOrder($company, 'FRACTIONAL-COST-001');
        $this->activeCostingAccounts($company);
        $existing = CostAllocation::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'production_order_id' => $orderId,
            'month' => '2026-08',
            'direct_material_cost' => 5,
            'direct_labor_cost' => 0,
            'manufacturing_overhead' => 0,
            'wip_beginning' => 0,
            'wip_ending' => 0,
            'total_cost' => 5,
            'is_posted' => true,
        ]);

        $source = JournalEntry::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'fiscal_year_id' => $fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'FRACTIONAL-COST-SOURCE-'.$company->id,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Fractional production cost source',
            'total_amount' => '0.10',
            'status' => 'posted',
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $source->id,
            'account_code' => '6211',
            'debit_amount' => '0.10',
            'credit_amount' => '0.00',
        ]);

        try {
            app(CostingService::class)->allocateCosts($company->id, '2026-08');
            $this->fail('Fractional source cost must not be rounded into an integer allocation.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('approved allocation rounding policy', $exception->getMessage());
        }

        $this->assertDatabaseCount('cost_allocations', 1);
        $this->assertDatabaseHas('cost_allocations', [
            'id' => $existing->id,
            'company_id' => $company->id,
            'month' => '2026-08',
            'total_cost' => 5,
        ]);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    private function company(string $name): Company
    {
        return Company::create([
            'name' => $name.' '.uniqid(),
            'tax_code' => 'ALLOC-'.uniqid(),
        ]);
    }

    private function period(Company $company, bool $closed): Period
    {
        $fiscal = $this->fiscalYear($company);

        return $this->periodForFiscalYear($fiscal, $closed);
    }

    private function fiscalYear(Company $company): FiscalYear
    {
        return FiscalYear::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
    }

    private function periodForFiscalYear(FiscalYear $fiscal, bool $closed): Period
    {
        return Period::create([
            'fiscal_year_id' => $fiscal->id,
            'period' => 8,
            'period_number' => 8,
            'name' => 'August 2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => $closed ? 'closed' : 'open',
            'is_closed' => $closed,
        ]);
    }

    private function productionOrder(Company $company, string $number): int
    {
        $itemId = (int) DB::table('items')->insertGetId([
            'company_id' => $company->id,
            'code' => 'ITEM-'.$number,
            'name' => 'Finished item '.$number,
            'type' => 'Goods',
            'cost_price' => 0,
            'selling_price' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('production_orders')->insertGetId([
            'company_id' => $company->id,
            'order_number' => $number,
            'start_date' => '2026-08-01',
            'item_id' => $itemId,
            'planned_quantity' => 1,
            'actual_quantity' => 0,
            'status' => 'in_progress',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function activeCostingAccounts(Company $company): void
    {
        foreach ([
            ['154', 'Chi phí sản xuất, kinh doanh dở dang', 'asset'],
            ['621', 'Chi phí nguyên liệu, vật liệu trực tiếp', 'expense'],
            ['622', 'Chi phí nhân công trực tiếp', 'expense'],
            ['627', 'Chi phí sản xuất chung', 'expense'],
        ] as [$code, $name, $type]) {
            ChartOfAccount::withoutGlobalScope('company')->create([
                'company_id' => $company->id,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'nature' => 'debit',
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }
    }

    private function assertClosed(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a closed-period conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertStringContainsString('kỳ kế toán đã khóa', $exception->getMessage());
        }
    }
}
