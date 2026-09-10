<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\InventoryIssue;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class InventoryValuationClosedPeriodIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_closed_own_period_leaves_issue_and_linked_gl_untouched(): void
    {
        // This case isolates the closed-period guard; the dedicated mapping
        // gate tests cover the production requirement for approved mappings.
        config()->set('accounting.enforce_inventory_posting_account_mappings', false);
        $company = $this->company('Closed valuation tenant', 'VAL-CLOSED');
        $this->period($company, true);
        [$issue, $entry] = $this->postedIssueWithJournal($company, 'ISSUE-CLOSED');
        $before = $this->mutationSnapshot($issue, $entry);

        foreach (['weighted_average', 'fifo'] as $method) {
            try {
                app(InventoryValuationService::class)->runCostCalculation($this->params($company, $method));
                $this->fail("Expected closed period conflict for {$method}.");
            } catch (ConflictHttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
                $this->assertStringContainsString('kỳ kế toán đã khóa', $exception->getMessage());
            }

            $this->assertSame($before, $this->mutationSnapshot($issue, $entry));
        }
    }

    public function test_foreign_linked_journal_id_is_not_disclosed_or_mutated_and_rolls_back_own_issue_line(): void
    {
        config()->set('accounting.enforce_inventory_posting_account_mappings', false);
        $company = $this->company('Open valuation tenant', 'VAL-OPEN');
        $foreign = $this->company('Foreign valuation tenant', 'VAL-FOREIGN');
        $this->period($company, false);
        $this->period($foreign, false);

        [$issue] = $this->postedIssueWithJournal($company, 'ISSUE-OWN', null);
        [, $foreignEntry] = $this->postedIssueWithJournal($foreign, 'ISSUE-FOREIGN');
        $issue->setAttribute('journal_entry_id', $foreignEntry->id)->saveQuietly();

        $beforeOwn = $this->mutationSnapshot($issue, null);
        $beforeForeign = $this->mutationSnapshot(null, $foreignEntry);

        try {
            app(InventoryValuationService::class)->runCostCalculation($this->params($company));
            $this->fail('Expected tenant-scoped linked journal rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('journal_entry_id', $exception->errors());
            $this->assertSame(
                'Bút toán liên kết không thuộc đơn vị đang tính giá.',
                $exception->errors()['journal_entry_id'][0]
            );
        }

        $this->assertSame($beforeOwn, $this->mutationSnapshot($issue, null));
        $this->assertSame($beforeForeign, $this->mutationSnapshot(null, $foreignEntry));
    }

    public function test_open_period_revalues_issue_and_its_same_tenant_journal(): void
    {
        config()->set('accounting.enforce_inventory_posting_account_mappings', false);
        $company = $this->company('Open revaluation tenant', 'VAL-WORKS');
        $this->period($company, false);
        [$issue, $entry] = $this->postedIssueWithJournal($company, 'ISSUE-OPEN');

        $result = app(InventoryValuationService::class)->runCostCalculation($this->params($company));

        $issue->refresh();
        $entry->refresh();
        $entry->load('lines');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['updated_issues_count']);
        $this->assertSame('100.00', (string) $issue->lines()->firstOrFail()->amount);
        $this->assertSame('100.00', (string) $issue->total_amount);
        $this->assertSame('100.00', (string) $entry->total_amount);
        $this->assertCount(2, $entry->lines);
        $this->assertEquals('100.00', $entry->lines->sum('debit_amount'));
        $this->assertEquals('100.00', $entry->lines->sum('credit_amount'));
    }

    public function test_production_rejects_rewriting_a_finalized_linked_journal(): void
    {
        $company = $this->company('Production immutability tenant', 'VAL-PROD');
        $this->period($company, false);
        [$issue, $entry] = $this->postedIssueWithJournal($company, 'ISSUE-PROD');
        $before = $this->mutationSnapshot($issue, $entry);
        $previousEnvironment = config('app.env');
        $previousMappingGate = config('accounting.enforce_inventory_posting_account_mappings');

        try {
            config([
                'app.env' => 'production',
                // Keep this test focused on the finalized-journal mutation
                // guard. Missing-mapping behavior has a separate test below.
                'accounting.enforce_inventory_posting_account_mappings' => false,
            ]);
            app(InventoryValuationService::class)->runCostCalculation($this->params($company));
            $this->fail('Expected production inventory valuation to reject a finalized journal rewrite.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Không thể ghi đè dòng bút toán đã kết thúc trong production; cần luồng điều chỉnh/đảo giá vốn được kiểm soát.',
                $exception->errors()['journal_entry_id'][0]
            );
        } finally {
            config([
                'app.env' => $previousEnvironment,
                'accounting.enforce_inventory_posting_account_mappings' => $previousMappingGate,
            ]);
        }

        $this->assertSame($before, $this->mutationSnapshot($issue, $entry));
    }

    public function test_production_cost_calculation_fails_closed_before_source_mutation_without_inventory_mapping(): void
    {
        $company = $this->company('Production mapping gate tenant', 'VAL-MAP-GATE');
        $this->period($company, false);
        [$issue, $entry] = $this->postedIssueWithJournal($company, 'ISSUE-MAP-GATE');
        $before = $this->mutationSnapshot($issue, $entry);
        $previousEnvironment = config('app.env');
        $previousMappingGate = config('accounting.enforce_inventory_posting_account_mappings');

        try {
            config([
                'app.env' => 'production',
                'accounting.enforce_inventory_posting_account_mappings' => true,
            ]);
            app(InventoryValuationService::class)->runCostCalculation($this->params($company));
            $this->fail('Production inventory cost calculation must fail closed without approved mapping evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('account_mappings', $exception->errors());
        } finally {
            config([
                'app.env' => $previousEnvironment,
                'accounting.enforce_inventory_posting_account_mappings' => $previousMappingGate,
            ]);
        }

        $this->assertSame($before, $this->mutationSnapshot($issue, $entry));
    }

    private function company(string $name, string $taxCode): Company
    {
        return Company::create(['name' => $name, 'tax_code' => $taxCode]);
    }

    private function period(Company $company, bool $closed): void
    {
        $fiscal = FiscalYear::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);

        Period::create([
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

    /** @return array{InventoryIssue, ?JournalEntry} */
    private function postedIssueWithJournal(Company $company, string $number, ?JournalEntry $journal = null): array
    {
        $fiscal = FiscalYear::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();
        $item = Item::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'ITEM-'.$number,
            'name' => 'Item '.$number,
            'type' => 'goods',
            'unit' => 'pcs',
            'purchase_price' => '100.00',
        ]);

        $journal ??= JournalEntry::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'fiscal_year_id' => $fiscal->id,
            'voucher_type' => 'inventory_issue',
            'voucher_number' => 'JE-'.$number,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Initial inventory cost',
            'total_amount' => '1.00',
            'status' => 'posted',
        ]);
        $journal->lines()->createMany([
            ['account_code' => '632', 'description' => 'Initial debit', 'debit_amount' => '1.00', 'credit_amount' => '0.00'],
            ['account_code' => '1561', 'description' => 'Initial credit', 'debit_amount' => '0.00', 'credit_amount' => '1.00'],
        ]);

        $issue = InventoryIssue::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'voucher_number' => $number,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'total_amount' => '1.00',
            'status' => 'posted',
            'is_posted' => true,
            'journal_entry_id' => $journal->id,
        ]);
        $issue->lines()->create([
            'item_id' => $item->id,
            'quantity' => '1.00',
            'unit_price' => '1.00',
            'amount' => '1.00',
            'debit_account' => '632',
            'credit_account' => '1561',
        ]);

        return [$issue, $journal];
    }

    /** @return array<string, mixed> */
    private function params(Company $company, string $method = 'weighted_average'): array
    {
        return [
            'company_id' => $company->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
            'method' => $method,
        ];
    }

    /** @return array<string, mixed> */
    private function mutationSnapshot(?InventoryIssue $issue, ?JournalEntry $entry): array
    {
        $snapshot = [];
        if ($issue !== null) {
            $freshIssue = InventoryIssue::withoutGlobalScopes()->with('lines')->findOrFail($issue->id);
            $snapshot['issue'] = [
                'total_amount' => (string) $freshIssue->total_amount,
                'journal_entry_id' => $freshIssue->journal_entry_id,
                'lines' => $freshIssue->lines->map(fn ($line) => [
                    'unit_price' => (string) $line->unit_price,
                    'amount' => (string) $line->amount,
                ])->all(),
            ];
        }
        if ($entry !== null) {
            $freshEntry = JournalEntry::withoutGlobalScopes()->with('lines')->findOrFail($entry->id);
            $snapshot['journal'] = [
                'total_amount' => (string) $freshEntry->total_amount,
                'lines' => $freshEntry->lines->map(fn ($line) => [
                    'account_code' => $line->account_code,
                    'debit_amount' => (string) $line->debit_amount,
                    'credit_amount' => (string) $line->credit_amount,
                ])->all(),
            ];
        }

        return $snapshot;
    }
}
