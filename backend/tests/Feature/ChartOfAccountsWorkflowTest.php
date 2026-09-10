<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ChartOfAccountsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Chart of accounts company',
            'tax_code' => 'COA-WORKFLOW',
        ]);
        $user = User::factory()->create();
        $this->configureAccountingTenant($user, $this->company);
        Permission::findOrCreate('master.accounts.transfer', 'web');
        $user->givePermissionTo('master.accounts.transfer');
        Sanctum::actingAs($user);
    }

    public function test_search_returns_matching_accounts_and_their_ancestors_only(): void
    {
        $this->account('111', ['name' => 'Tiền mặt', 'name_en' => 'Cash in hand', 'is_parent' => true]);
        $this->account('1111', ['name' => 'Tiền Việt Nam', 'parent_code' => '111', 'level' => 2]);
        $this->account('112', ['name' => 'Tiền gửi ngân hàng']);

        $foreignCompany = Company::create(['name' => 'Foreign', 'tax_code' => 'COA-FOREIGN']);
        ChartOfAccount::withoutGlobalScopes()->create($this->accountData(
            $foreignCompany->id,
            '999',
            ['name' => 'Tiền Việt Nam foreign'],
        ));

        $response = $this->getJson('/api/v1/master/accounts?search='.urlencode('Việt Nam'))
            ->assertOk();

        $codes = collect($response->json())->pluck('code')->all();
        $this->assertSame(['111', '1111'], $codes);
    }

    public function test_creating_a_child_derives_level_and_marks_parent(): void
    {
        $parent = $this->account('111', ['is_parent' => false]);

        $this->postJson('/api/v1/master/accounts', [
            'code' => '1111',
            'name' => 'Tiền Việt Nam',
            'name_en' => 'Vietnamese dong',
            'type' => 'asset',
            'nature' => 'debit',
            'parent_code' => '111',
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('level', 2)
            ->assertJsonPath('parent_code', '111')
            ->assertJsonPath('name_en', 'Vietnamese dong');

        $this->assertTrue($parent->fresh()->is_parent);
    }

    public function test_updating_parent_to_a_descendant_is_rejected(): void
    {
        $parent = $this->account('111', ['is_parent' => true]);
        $this->account('1111', ['parent_code' => '111', 'level' => 2]);

        $this->putJson('/api/v1/master/accounts/'.$parent->id, [
            'parent_code' => '1111',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('parent_code');

        $this->assertNull($parent->fresh()->parent_code);
    }

    public function test_account_identity_fields_are_rejected_instead_of_silently_ignored(): void
    {
        $account = $this->account('113');

        $this->putJson('/api/v1/master/accounts/'.$account->id, [
            'code' => '1131',
            'company_id' => $this->company->id + 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'company_id']);

        $this->assertDatabaseHas('chart_of_accounts', [
            'id' => $account->id,
            'company_id' => $this->company->id,
            'code' => '113',
        ]);
    }

    public function test_deleting_an_account_with_children_is_rejected(): void
    {
        $parent = $this->account('111', ['is_parent' => true]);
        $this->account('1111', ['parent_code' => '111', 'level' => 2]);

        $this->deleteJson('/api/v1/master/accounts/'.$parent->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account');

        $this->assertDatabaseHas('chart_of_accounts', [
            'id' => $parent->id,
            'deleted_at' => null,
        ]);
    }

    public function test_inactive_accounts_can_be_excluded_without_changing_the_flat_response_contract(): void
    {
        $this->account('111', ['is_active' => true]);
        $this->account('112', ['is_active' => false]);

        $this->getJson('/api/v1/master/accounts?include_inactive=0')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.code', '111');
    }

    public function test_search_keeps_an_inactive_ancestor_when_inactive_rows_are_filtered(): void
    {
        $this->account('111', ['name' => 'Tiền mặt', 'is_active' => false, 'is_parent' => true]);
        $this->account('1111', ['name' => 'Tiền Việt Nam', 'parent_code' => '111', 'level' => 2, 'is_active' => true]);

        $response = $this->getJson('/api/v1/master/accounts?include_inactive=0&search='.urlencode('Việt Nam'))
            ->assertOk();

        $this->assertSame(['111', '1111'], collect($response->json())->pluck('code')->all());
    }

    public function test_a_soft_deleted_account_code_cannot_be_recreated_as_a_server_error(): void
    {
        $deleted = $this->account('111');
        $deleted->delete();

        $this->postJson('/api/v1/master/accounts', [
            'code' => '111',
            'name' => 'Tiền mặt mới',
            'type' => 'asset',
            'nature' => 'debit',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_transfer_rewrites_tenant_posting_references_and_deactivates_source(): void
    {
        $source = $this->account('111');
        $target = $this->account('112');
        $journal = DB::table('journal_entries')->insertGetId([
            'company_id' => $this->company->id,
            'fiscal_year_id' => 1,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'JV-TRANSFER-001',
            'voucher_date' => now()->toDateString(),
            'posting_date' => now()->toDateString(),
            'description' => 'Transfer account test',
            'total_amount' => 100,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('journal_entry_lines')->insert([
            'journal_entry_id' => $journal,
            'account_code' => $source->code,
            'debit_amount' => 100,
            'credit_amount' => 0,
            'description' => 'Line',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $preview = $this->postJson('/api/v1/master/accounts/'.$source->id.'/transfer', [
            'target_code' => $target->code, 'preview' => true,
        ])->assertOk();
        $this->postJson('/api/v1/master/accounts/'.$source->id.'/transfer', [
            'target_code' => $target->code, 'preview_token' => $preview->json('preview_token'),
        ])->assertOk()
            ->assertJsonPath('account.is_active', false)
            ->assertJsonPath('target.code', $target->code)
            ->assertJsonPath('affected_references', 1);

        $this->assertDatabaseHas('journal_entry_lines', ['journal_entry_id' => $journal, 'account_code' => $target->code]);
        $this->assertDatabaseMissing('journal_entry_lines', ['journal_entry_id' => $journal, 'account_code' => $source->code]);
    }

    private function account(string $code, array $overrides = []): ChartOfAccount
    {
        return ChartOfAccount::withoutGlobalScopes()->create(
            $this->accountData($this->company->id, $code, $overrides),
        );
    }

    private function accountData(int $companyId, string $code, array $overrides = []): array
    {
        return array_merge([
            'company_id' => $companyId,
            'code' => $code,
            'name' => $code,
            'type' => 'asset',
            'nature' => 'debit',
            'level' => 1,
            'is_parent' => false,
            'is_active' => true,
        ], $overrides);
    }
}
