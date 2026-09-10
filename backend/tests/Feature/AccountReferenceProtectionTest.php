<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountReferenceProtectionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private ChartOfAccount $source;
    private ChartOfAccount $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Account protection', 'tax_code' => 'COA-PROTECT']);
        $user = User::factory()->create();
        $this->configureAccountingTenant($user, $this->company);
        \Spatie\Permission\Models\Permission::findOrCreate('master.accounts.transfer', 'web');
        $user->givePermissionTo('master.accounts.transfer');
        Sanctum::actingAs($user);
        foreach (['source' => '1111', 'target' => '1112'] as $property => $code) {
            $this->$property = ChartOfAccount::create([
                'company_id' => $this->company->id, 'code' => $code, 'name' => $code,
                'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false, 'is_active' => true,
            ]);
        }
    }

    public function test_used_account_cannot_be_deleted_even_when_it_has_no_children(): void
    {
        $this->journal('draft');
        $this->deleteJson('/api/v1/master/accounts/'.$this->source->id)
            ->assertUnprocessable()->assertJsonValidationErrors('account');
        $this->assertDatabaseHas('chart_of_accounts', ['id' => $this->source->id, 'deleted_at' => null]);
    }

    public function test_transfer_preview_counts_draft_references_without_mutating_them(): void
    {
        $id = $this->journal('draft');
        $response = $this->postJson('/api/v1/master/accounts/'.$this->source->id.'/transfer', [
            'target_code' => $this->target->code, 'preview' => true,
        ])->assertOk()->assertJsonPath('affected_references', 1)->assertJsonPath('preview', true);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $response->json('preview_token'));
        $this->assertDatabaseHas('journal_entry_lines', ['journal_entry_id' => $id, 'account_code' => '1111']);
        $this->assertTrue($this->source->fresh()->is_active);
    }

    public function test_transfer_requires_a_current_preview_token_before_mutating_references(): void
    {
        $id = $this->journal('draft');

        $this->postJson('/api/v1/master/accounts/'.$this->source->id.'/transfer', [
            'target_code' => $this->target->code,
        ])->assertConflict();

        $this->assertDatabaseHas('journal_entry_lines', ['journal_entry_id' => $id, 'account_code' => $this->source->code]);
        $this->assertTrue($this->source->fresh()->is_active);
    }

    public function test_transfer_rejects_a_preview_token_when_references_changed(): void
    {
        $id = $this->journal('draft');
        $preview = $this->postJson('/api/v1/master/accounts/'.$this->source->id.'/transfer', [
            'target_code' => $this->target->code, 'preview' => true,
        ])->assertOk();
        $token = $preview->json('preview_token');
        $this->assertIsString($token);

        DB::table('journal_entry_lines')->where('journal_entry_id', $id)->update(['account_code' => $this->target->code]);

        $this->postJson('/api/v1/master/accounts/'.$this->source->id.'/transfer', [
            'target_code' => $this->target->code, 'preview_token' => $token,
        ])->assertConflict();
        $this->assertTrue($this->source->fresh()->is_active);
    }

    public function test_transfer_rejects_posted_history_without_rewriting_or_deactivating(): void
    {
        $id = $this->journal('posted');
        $this->postJson('/api/v1/master/accounts/'.$this->source->id.'/transfer', [
            'target_code' => $this->target->code, 'preview' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('account');
        $this->assertDatabaseHas('journal_entry_lines', ['journal_entry_id' => $id, 'account_code' => '1111']);
        $this->assertTrue($this->source->fresh()->is_active);
    }

    public function test_unused_account_can_still_be_deleted(): void
    {
        $this->deleteJson('/api/v1/master/accounts/'.$this->source->id)->assertNoContent();
        $this->assertSoftDeleted('chart_of_accounts', ['id' => $this->source->id]);
    }

    public function test_transfer_rejects_draft_in_a_closed_period(): void
    {
        $id = $this->journal('draft');
        $year = DB::table('fiscal_years')->where('company_id', $this->company->id)->where('year', 2026)->value('id');
        \App\Models\Period::create(['fiscal_year_id' => $year, 'period' => 8, 'period_number' => 8,
            'name' => 'August', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
            'is_closed' => true, 'status' => 'closed']);
        $this->postJson('/api/v1/master/accounts/'.$this->source->id.'/transfer', [
            'target_code' => $this->target->code, 'preview' => true,
        ])->assertConflict();
        $this->assertDatabaseHas('journal_entry_lines', ['journal_entry_id' => $id, 'account_code' => '1111']);
        $this->assertTrue($this->source->fresh()->is_active);
    }

    public function test_update_permission_alone_cannot_transfer_accounts(): void
    {
        auth()->user()->revokePermissionTo('master.accounts.transfer');
        $this->postJson('/api/v1/master/accounts/'.$this->source->id.'/transfer', [
            'target_code' => $this->target->code,
        ])->assertForbidden();
        $this->assertTrue($this->source->fresh()->is_active);
    }

    public function test_sales_cogs_accounts_are_included_in_reference_protection(): void
    {
        $customerId = DB::table('customers')->insertGetId([
            'company_id' => $this->company->id, 'code' => 'CUS-COGS', 'name' => 'COGS customer',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoiceId = DB::table('sales_invoices')->insertGetId([
            'company_id' => $this->company->id, 'customer_id' => $customerId,
            'invoice_number' => 'SI-COGS-REF', 'invoice_date' => '2026-08-20',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_invoice_lines')->insert([
            'sales_invoice_id' => $invoiceId, 'credit_account' => '5111',
            'cogs_debit_account' => $this->source->code, 'cogs_credit_account' => '1561',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->deleteJson('/api/v1/master/accounts/'.$this->source->id)
            ->assertUnprocessable()->assertJsonValidationErrors('account');
        $this->assertDatabaseHas('chart_of_accounts', ['id' => $this->source->id, 'deleted_at' => null]);
    }

    public function test_hidden_future_module_account_columns_are_still_protected(): void
    {
        DB::table('fixed_assets')->insert([
            'company_id' => $this->company->id, 'asset_code' => 'FA-REF', 'asset_name' => 'Reference asset',
            'purchase_date' => '2026-08-20', 'asset_account' => $this->source->code,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tool_equipments')->insert([
            'company_id' => $this->company->id, 'tool_code' => 'TOOL-REF', 'tool_name' => 'Reference tool',
            'purchase_date' => '2026-08-20', 'tool_account' => $this->source->code,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('borrowing_contracts')->insert([
            'company_id' => $this->company->id, 'contract_number' => 'BORROW-REF', 'lender_name' => 'Lender',
            'debit_account' => $this->source->code, 'amount' => 1, 'term' => 1,
            'disbursement_date' => '2026-08-20', 'maturity_date' => '2026-09-20',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $references = DB::transaction(fn () => app(\App\Services\AccountReferenceService::class)
            ->inspect($this->company->id, $this->source->code));

        $this->assertSame(1, $references['fixed_assets.asset_account'] ?? null);
        $this->assertSame(1, $references['tool_equipments.tool_account'] ?? null);
        $this->assertSame(1, $references['borrowing_contracts.debit_account'] ?? null);
        $this->deleteJson('/api/v1/master/accounts/'.$this->source->id)
            ->assertUnprocessable()->assertJsonValidationErrors('account');
    }

    private function journal(string $status): int
    {
        $fiscalYearId = DB::table('fiscal_years')->where('company_id', $this->company->id)->value('id');
        $id = DB::table('journal_entries')->insertGetId([
            'company_id' => $this->company->id, 'fiscal_year_id' => $fiscalYearId,
            'voucher_type' => 'general_journal', 'voucher_number' => 'JV-REFERENCE-'.$status,
            'voucher_date' => '2026-08-20', 'posting_date' => '2026-08-20',
            'description' => 'Account reference fixture',
            'total_amount' => 100, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('journal_entry_lines')->insert([
            'journal_entry_id' => $id, 'account_code' => '1111', 'debit_amount' => 100,
            'credit_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }
}
