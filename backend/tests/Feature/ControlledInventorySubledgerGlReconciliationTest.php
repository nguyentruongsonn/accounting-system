<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\InventorySubledgerGlReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ControlledInventorySubledgerGlReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_policy_computes_inventory_value_and_control_account_tie_out(): void
    {
        [$company, $actor] = $this->tenant();
        $itemId = (int) DB::table('items')->insertGetId(['company_id' => $company->id, 'type' => 'Goods', 'code' => 'CTRL-ITEM', 'name' => 'Controlled item', 'unit' => 'Cái', 'cost_price' => 100000, 'selling_price' => 120000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $warehouseId = (int) DB::table('warehouses')->where('company_id', $company->id)->value('id');
        $receiptId = (int) DB::table('inventory_receipts')->insertGetId(['company_id' => $company->id, 'warehouse_id' => $warehouseId, 'voucher_number' => 'CTRL-IR-001', 'voucher_date' => '2026-08-01', 'total_amount' => 100000, 'status' => 'posted', 'is_posted' => true, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $lineId = (int) DB::table('inventory_receipt_lines')->insertGetId(['inventory_receipt_id' => $receiptId, 'item_id' => $itemId, 'warehouse_id' => $warehouseId, 'quantity' => 1, 'unit_price' => 100000, 'amount' => 100000, 'debit_account' => '156', 'credit_account' => '331', 'created_at' => now(), 'updated_at' => now()]);
        $journal = $this->journal($company->id, $actor->id, '2026-08-01', \App\Models\InventoryReceipt::class, $receiptId, [['account_code' => '156', 'debit_amount' => '100000.00', 'credit_amount' => '0.00'], ['account_code' => '331', 'debit_amount' => '0.00', 'credit_amount' => '100000.00']]);
        DB::table('inventory_receipts')->where('id', $receiptId)->update(['journal_entry_id' => $journal]);
        $this->assertNotSame(0, $lineId);
        $this->approvePolicy($actor, $company->id);

        $run = app(InventorySubledgerGlReconciliationService::class)->capture($actor, '2026-08-31');

        $this->assertSame('approved', $run->status);
        $this->assertSame(0, $run->divergence_count);
        $this->assertSame('reconciled', $run->snapshot['status']);
        $this->assertSame('100000.00', $run->snapshot['amounts']['subledger_ending_value']);
        $this->assertSame('100000.00', $run->snapshot['amounts']['gl_inventory_balance']);
        $this->assertSame('0.00', $run->snapshot['amounts']['difference']);
        $this->assertSame(1, $run->snapshot['amounts']['receipt_line_count']);
        $this->assertNotEmpty($run->snapshot['input_cutoff_at']);
        $this->assertSame('100000.00', $run->snapshot['amounts']['item_warehouse_rollforward'][0]['ending_value']);
        $this->assertSame('1.0000', $run->snapshot['amounts']['item_warehouse_rollforward'][0]['ending_quantity']);

        Permission::findOrCreate('inventory.reconciliations.view', 'web');
        Permission::findOrCreate('inventory.reconciliations.capture', 'web');
        $actor->givePermissionTo(['inventory.reconciliations.view', 'inventory.reconciliations.capture']);
        $this->actingAs($actor)->postJson('/api/v1/inventory/subledger-gl-reconciliations', ['as_of_date' => '2026-08-31'])
            ->assertCreated()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.amounts.subledger_ending_value', '100000.00')->assertJsonPath('data.input_cutoff_at', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_controlled_inventory_capture_blocks_unvalued_transfer_event(): void
    {
        [$company, $actor] = $this->tenant();
        $itemId = (int) DB::table('items')->insertGetId(['company_id' => $company->id, 'type' => 'Goods', 'code' => 'CTRL-ITEM-2', 'name' => 'Controlled item 2', 'unit' => 'Cái', 'cost_price' => 100000, 'selling_price' => 120000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $warehouses = DB::table('warehouses')->where('company_id', $company->id)->orderBy('id')->pluck('id')->all();
        DB::table('inventory_movement_events')->insert(['company_id' => $company->id, 'movement_date' => '2026-08-15', 'warehouse_id' => $warehouses[0], 'item_id' => $itemId, 'movement_type' => 'transfer_out', 'quantity_delta' => '-1.0000', 'amount_delta' => null, 'source_type' => 'inventory_transfer', 'source_id' => 1, 'source_line_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->approvePolicy($actor, $company->id);

        $run = app(InventorySubledgerGlReconciliationService::class)->capture($actor, '2026-08-31');

        $this->assertSame('blocked', $run->status);
        $this->assertTrue($run->exceptions->contains('exception_code', 'inventory_transfer_value_unavailable'));
        $this->assertFalse($run->snapshot['close_authority']);
    }

    private function tenant(): array
    {
        $company = Company::query()->firstOrFail();
        $this->ensureControlAccount($company);
        return [$company, User::factory()->create(['company_id' => $company->id])];
    }

    private function ensureControlAccount(Company $company): void
    {
        DB::table('chart_of_accounts')->updateOrInsert(
            ['company_id' => $company->id, 'code' => '156'],
            [
                'name' => 'Hàng hóa',
                'name_en' => null,
                'parent_code' => null,
                'type' => 'asset',
                'nature' => 'debit',
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
                'description' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function approvePolicy(User $actor, int $companyId): void
    {
        $profileId = FiscalYear::withoutGlobalScope('company')->where('company_id', $companyId)->firstOrFail()->accountingRegimeProfile()->firstOrFail()->id;
        $policy = app(AccountingPolicyLifecycleService::class)->createDraft($actor, [
            'company_id' => $companyId, 'accounting_regime_profile_id' => $profileId, 'policy_key' => 'reconciliation.inventory', 'policy_version' => '2026.1', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
            'posting_rule_contract' => ['inventory_subledger_gl_reconciliation' => ['schema' => 'inventory-subledger-gl-reconciliation.v1', 'algorithm' => 'inventory-receipt-issue-transfer-v1', 'valuation_method' => 'weighted_average', 'functional_currency' => 'VND', 'control_account_codes' => ['156'], 'normal_balance' => 'debit', 'physical_count_signoff_required' => true]],
            'required_dimensions' => [], 'regulatory_dependencies' => [],
        ]);
        app(AccountingPolicyLifecycleService::class)->approve($actor, $policy);
    }

    private function journal(int $companyId, int $actorId, string $date, string $sourceType, int $sourceId, array $lines): int
    {
        $id = (int) DB::table('journal_entries')->insertGetId(['company_id' => $companyId, 'fiscal_year_id' => DB::table('fiscal_years')->where('company_id', $companyId)->value('id'), 'voucher_type' => 'general', 'voucher_number' => 'CTRL-'.uniqid(), 'voucher_date' => $date, 'posting_date' => $date, 'description' => 'Controlled inventory reconciliation fixture', 'total_amount' => collect($lines)->sum(fn (array $line): float => (float) $line['debit_amount']), 'status' => 'posted', 'source_document_type' => $sourceType, 'source_document_id' => $sourceId, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($lines as $line) DB::table('journal_entry_lines')->insert($line + ['journal_entry_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }
}
