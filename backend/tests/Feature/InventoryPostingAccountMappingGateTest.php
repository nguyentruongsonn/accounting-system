<?php

namespace Tests\Feature;

use App\Exceptions\AccountingAccountMappingUnavailableException;
use App\Models\ChartOfAccount;
use App\Models\FiscalYear;
use App\Models\InventoryIssue;
use App\Models\InventoryIssueLine;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptLine;
use App\Models\Item;
use App\Models\User;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\ApprovedAccountMappingLifecycleService;
use App\Services\InventoryIssueService;
use App\Services\InventoryReceiptService;
use App\Services\InventoryVoucherAccountMappingPostingGate;
use App\Support\TwoRolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryPostingAccountMappingGateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        TwoRolePermissions::seed();
        $this->user = User::factory()->create(['company_id' => 1]);
        $this->user->assignRole('accountant');
        Sanctum::actingAs($this->user);
        $this->item = Item::create([
            'company_id' => 1,
            'code' => 'MAPPING-GATE-ITEM',
            'name' => 'Mapping gate test item',
            'type' => 'inventory',
            'inventory_account' => '1561',
        ]);
        config()->set('accounting.enforce_inventory_posting_account_mappings', true);
        config()->set('accounting.enforce_inventory_stock_availability', false);
        foreach (['1561', '331', '632'] as $code) {
            ChartOfAccount::create([
                'company_id' => 1, 'code' => $code, 'name' => 'Account '.$code,
                'type' => 'asset', 'nature' => 'debit', 'level' => 1,
                'is_parent' => false, 'is_active' => true,
            ]);
        }
    }

    public function test_inventory_receipt_cannot_reach_journal_without_owner_mapping(): void
    {
        $receipt = InventoryReceipt::create([
            'company_id' => 1,
            'voucher_type' => 'purchase_receipt',
            'voucher_number' => 'PN-MAPPING-GATE',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'total_amount' => '100.00',
            'status' => 'draft',
            'is_posted' => false,
        ]);
        InventoryReceiptLine::create([
            'inventory_receipt_id' => $receipt->id,
            'item_id' => $this->item->id,
            'amount' => '100.00',
            'debit_account' => '1561',
            'credit_account' => '331',
        ]);

        $this->approvePolicy('inventory_receipt');
        $this->expectException(AccountingAccountMappingUnavailableException::class);
        app(InventoryReceiptService::class)->post($receipt->id);

        $this->assertDatabaseMissing('journal_entries', ['source_document_id' => $receipt->id]);
    }

    public function test_inventory_issue_cannot_reach_journal_without_owner_mapping(): void
    {
        $issue = InventoryIssue::create([
            'company_id' => 1,
            'voucher_type' => 'sale_issue',
            'voucher_number' => 'PX-MAPPING-GATE',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'total_amount' => '100.00',
            'status' => 'draft',
            'is_posted' => false,
        ]);
        InventoryIssueLine::create([
            'inventory_issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'amount' => '100.00',
            'debit_account' => '632',
            'credit_account' => '1561',
        ]);

        $this->approvePolicy('inventory_issue');
        $this->expectException(AccountingAccountMappingUnavailableException::class);
        app(InventoryIssueService::class)->post($issue->id);

        $this->assertDatabaseMissing('journal_entries', ['source_document_id' => $issue->id]);
    }

    public function test_inventory_issue_with_unresolved_draft_accounts_returns_a_field_error_instead_of_a_server_error(): void
    {
        $issue = InventoryIssue::create([
            'company_id' => 1,
            'warehouse_id' => null,
            'voucher_type' => 'Kiểm kê điều chỉnh giảm',
            'voucher_number' => 'PX-UNRESOLVED-DRAFT',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'total_amount' => '100.00',
            'status' => 'draft',
            'is_posted' => false,
        ]);
        InventoryIssueLine::create([
            'inventory_issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'amount' => '100.00',
            'debit_account' => null,
            'credit_account' => null,
        ]);

        $this->approvePolicy('inventory_issue');

        try {
            app(InventoryIssueService::class)->post($issue->id);
            $this->fail('An unresolved draft account must block posting.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('account_mappings', $exception->errors());
        }

        $this->assertDatabaseMissing('journal_entries', ['source_document_id' => $issue->id]);
    }

    public function test_inventory_issue_with_no_positive_amount_cannot_be_marked_posted_without_a_journal(): void
    {
        // Exercise the non-production/diagnostic mapping branch explicitly:
        // this regression protects the independent source-to-GL invariant.
        config()->set('accounting.enforce_inventory_posting_account_mappings', false);

        $issue = InventoryIssue::create([
            'company_id' => 1,
            'voucher_type' => 'sale_issue',
            'voucher_number' => 'PX-EMPTY-GL',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'total_amount' => '0.00',
            'status' => 'draft',
            'is_posted' => false,
        ]);
        InventoryIssueLine::create([
            'inventory_issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'amount' => '0.00',
            'debit_account' => '632',
            'credit_account' => '1561',
        ]);

        try {
            app(InventoryIssueService::class)->post($issue->id);
            $this->fail('A zero-value inventory issue must not report posted without a journal entry.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        } finally {
            config()->set('accounting.enforce_inventory_posting_account_mappings', true);
        }

        $this->assertDatabaseHas('inventory_issues', [
            'id' => $issue->id,
            'is_posted' => false,
            'status' => 'draft',
            'journal_entry_id' => null,
        ]);
        $this->assertDatabaseMissing('journal_entries', ['source_document_id' => $issue->id]);
    }

    public function test_receipt_posts_once_when_effective_owner_mappings_exist(): void
    {
        $receipt = InventoryReceipt::create([
            'company_id' => 1, 'voucher_type' => 'purchase_receipt', 'voucher_number' => 'PN-MAPPED',
            'voucher_date' => '2026-08-20', 'posting_date' => '2026-08-20',
            'total_amount' => '100.00', 'status' => 'draft', 'is_posted' => false,
        ]);
        $line = InventoryReceiptLine::create([
            'inventory_receipt_id' => $receipt->id, 'item_id' => $this->item->id,
            'quantity' => 1, 'unit_price' => '100.00', 'amount' => '100.00',
            'debit_account' => '1561', 'credit_account' => '331',
        ]);
        $this->approvePolicyAndMappings($receipt, $line, 'inventory_receipt');

        $posted = app(InventoryReceiptService::class)->post($receipt->id);

        $this->assertTrue($posted->is_posted);
        $this->assertNotNull($posted->journal_entry_id);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => InventoryReceipt::class, 'model_id' => $receipt->id,
            'action' => 'inventory_receipt.account_mappings_applied',
        ]);
    }

    public function test_issue_posts_once_when_effective_owner_mappings_exist(): void
    {
        $issue = InventoryIssue::create([
            'company_id' => 1, 'voucher_type' => 'sale_issue', 'voucher_number' => 'PX-MAPPED',
            'voucher_date' => '2026-08-20', 'posting_date' => '2026-08-20',
            'total_amount' => '100.00', 'status' => 'draft', 'is_posted' => false,
        ]);
        $line = InventoryIssueLine::create([
            'inventory_issue_id' => $issue->id, 'item_id' => $this->item->id,
            'quantity' => 1, 'unit_price' => '100.00', 'amount' => '100.00',
            'debit_account' => '632', 'credit_account' => '1561',
        ]);
        $this->approvePolicyAndMappings($issue, $line, 'inventory_issue');

        $posted = app(InventoryIssueService::class)->post($issue->id);

        $this->assertTrue($posted->is_posted);
        $this->assertNotNull($posted->journal_entry_id);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => InventoryIssue::class, 'model_id' => $issue->id,
            'action' => 'inventory_issue.account_mappings_applied',
        ]);
    }

    private function approvePolicyAndMappings(object $voucher, object $line, string $voucherType): void
    {
        $checker = User::factory()->create(['company_id' => 1]);
        $policy = $this->approvePolicy($voucherType, $checker);
        $mappings = app(ApprovedAccountMappingLifecycleService::class);
        foreach (['debit' => $line->debit_account, 'credit' => $line->credit_account] as $role => $code) {
            $draft = $mappings->createDraft($this->user, [
                'company_id' => 1, 'accounting_policy_version_id' => $policy->id,
                'mapping_key' => InventoryVoucherAccountMappingPostingGate::MAPPING_KEY,
                'mapping_context' => InventoryVoucherAccountMappingPostingGate::contextFor($voucher, $line, (string) $code),
                'account_role' => $role, 'account_code' => $code,
                'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
                'regulatory_dependencies' => [],
            ]);
            $mappings->approve($checker, $draft);
        }
    }

    private function approvePolicy(string $voucherType, ?User $checker = null): object
    {
        $checker ??= User::factory()->create(['company_id' => 1]);
        $year = FiscalYear::withoutGlobalScope('company')->where('company_id', 1)->where('year', 2026)->firstOrFail();
        $policies = app(AccountingPolicyLifecycleService::class);

        return $policies->approve($checker, $policies->createDraft($this->user, [
            'company_id' => 1,
            'accounting_regime_profile_id' => $year->accountingRegimeProfile()->firstOrFail()->id,
            'policy_key' => 'posting.'.$voucherType,
            'policy_version' => 'inventory-map-v1',
            'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
            'posting_rule_contract' => ['mapping_reference' => 'owner-approved-inventory-map'],
            'required_dimensions' => [], 'regulatory_dependencies' => [],
        ]));
    }
}
