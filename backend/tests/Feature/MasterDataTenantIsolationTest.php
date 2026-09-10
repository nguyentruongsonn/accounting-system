<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PaymentTerm;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\VoucherTypeSetting;
use App\Models\Warehouse;
use App\Services\AccountService;
use App\Services\BankAccountService;
use App\Services\CustomerService;
use App\Services\ItemService;
use App\Services\SupplierService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MasterDataTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyA = Company::create(['name' => 'Master A', 'tax_code' => 'MASTER-A']);
        $this->companyB = Company::create(['name' => 'Master B', 'tax_code' => 'MASTER-B']);
        $this->userA = User::factory()->create();
        $this->configureAccountingTenant($this->userA, $this->companyA);
        Sanctum::actingAs($this->userA);
    }

    public function test_company_settings_are_limited_to_authenticated_company(): void
    {
        $this->getJson('/api/v1/master/company')
            ->assertOk()
            ->assertJsonPath('id', $this->companyA->id)
            ->assertJsonMissing(['id' => $this->companyB->id]);

        $this->putJson('/api/v1/master/company/'.$this->companyB->id, ['name' => 'Attacked'])
            ->assertNotFound();
        $this->assertSame('Master B', Company::find($this->companyB->id)->name);

        $this->putJson('/api/v1/master/company/'.$this->companyA->id, ['name' => 'Master A Updated'])
            ->assertOk();
        $this->assertSame('Master A Updated', $this->companyA->fresh()->name);
    }

    public function test_all_master_lists_and_route_ids_are_tenant_scoped(): void
    {
        foreach ($this->masterPairs() as $pair) {
            $response = $this->getJson($pair['endpoint'].'?company_id='.$this->companyB->id)->assertOk();
            $json = json_encode($response->json(), JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString($pair['code_a'], $json);
            $this->assertStringNotContainsString($pair['code_b'], $json);

            $this->getJson($pair['endpoint'].'/'.$pair['b']->getKey())->assertNotFound();
            $this->assertGreaterThanOrEqual(400, $this->putJson($pair['endpoint'].'/'.$pair['b']->getKey(), $pair['update'])->status());
            $this->assertGreaterThanOrEqual(400, $this->deleteJson($pair['endpoint'].'/'.$pair['b']->getKey())->status());
            $this->assertNotNull($pair['b']::withoutGlobalScopes()->find($pair['b']->getKey()));
        }
    }

    public function test_master_creates_ignore_malicious_company_id(): void
    {
        $requests = [
            ['/api/v1/master/accounts', ['code' => 'ACC-CREATE-A', 'name' => 'Account', 'type' => 'asset', 'nature' => 'debit', 'level' => 1]],
            ['/api/v1/master/customers', ['code' => 'CUS-CREATE-A', 'name' => 'Customer']],
            ['/api/v1/master/suppliers', ['code' => 'SUP-CREATE-A', 'name' => 'Supplier']],
            ['/api/v1/master/employees', ['code' => 'EMP-CREATE-A', 'name' => 'Employee']],
            ['/api/v1/master/payment-terms', ['code' => 'TERM-CREATE-A', 'name' => 'Term', 'due_days' => 30]],
            ['/api/v1/master/item-categories', ['code' => 'CAT-CREATE-A', 'name' => 'Category']],
            ['/api/v1/master/units', ['code' => 'UNIT-CREATE-A', 'name' => 'Unit']],
            ['/api/v1/master/warehouses', ['code' => 'WH-CREATE-A', 'name' => 'Warehouse']],
            ['/api/v1/master/voucher-type-settings', ['voucher_type' => 'thu_tien_mat', 'name' => 'Setting A']],
            ['/api/v1/bank/accounts', ['account_number' => 'BANK-CREATE-A', 'bank_name' => 'Bank A']],
        ];

        foreach ($requests as [$endpoint, $payload]) {
            $payload['company_id'] = $this->companyB->id;
            $this->postJson($endpoint, $payload)->assertCreated();
        }

        foreach ([
            ['chart_of_accounts', 'code', 'ACC-CREATE-A'],
            ['customers', 'code', 'CUS-CREATE-A'],
            ['suppliers', 'code', 'SUP-CREATE-A'],
            ['employees', 'code', 'EMP-CREATE-A'],
            ['payment_terms', 'code', 'TERM-CREATE-A'],
            ['item_categories', 'code', 'CAT-CREATE-A'],
            ['units', 'code', 'UNIT-CREATE-A'],
            ['warehouses', 'code', 'WH-CREATE-A'],
            ['voucher_type_settings', 'name', 'Setting A'],
            ['bank_accounts', 'account_number', 'BANK-CREATE-A'],
        ] as [$table, $column, $value]) {
            $this->assertDatabaseHas($table, [$column => $value, 'company_id' => $this->companyA->id]);
            $this->assertDatabaseMissing($table, [$column => $value, 'company_id' => $this->companyB->id]);
        }
    }

    public function test_master_services_scope_direct_id_lookups_to_the_authenticated_company(): void
    {
        $accountA = ChartOfAccount::withoutGlobalScopes()->create($this->accountData($this->companyA->id, 'SERVICE-ACC-A'));
        $accountB = ChartOfAccount::withoutGlobalScopes()->create($this->accountData($this->companyB->id, 'SERVICE-ACC-B'));
        $customerA = Customer::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'code' => 'SERVICE-CUS-A', 'name' => 'A']);
        $customerB = Customer::withoutGlobalScopes()->create(['company_id' => $this->companyB->id, 'code' => 'SERVICE-CUS-B', 'name' => 'B']);
        $supplierA = Supplier::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'code' => 'SERVICE-SUP-A', 'name' => 'A']);
        $supplierB = Supplier::withoutGlobalScopes()->create(['company_id' => $this->companyB->id, 'code' => 'SERVICE-SUP-B', 'name' => 'B']);
        $bankA = BankAccount::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'account_number' => 'SERVICE-BANK-A', 'bank_name' => 'A']);
        $bankB = BankAccount::withoutGlobalScopes()->create(['company_id' => $this->companyB->id, 'account_number' => 'SERVICE-BANK-B', 'bank_name' => 'B']);
        $itemA = Item::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'code' => 'SERVICE-ITEM-A', 'name' => 'A', 'type' => 'goods']);
        $itemB = Item::withoutGlobalScopes()->create(['company_id' => $this->companyB->id, 'code' => 'SERVICE-ITEM-B', 'name' => 'B', 'type' => 'goods']);

        $cases = [
            [app(AccountService::class), $accountA, $accountB],
            [app(CustomerService::class), $customerA, $customerB],
            [app(SupplierService::class), $supplierA, $supplierB],
            [app(BankAccountService::class), $bankA, $bankB],
            [app(ItemService::class), $itemA, $itemB],
        ];

        foreach ($cases as [$service, $owned, $foreign]) {
            $this->assertSame($owned->id, $service->getById($owned->id, $this->companyA->id)->id);

            try {
                $service->getById($foreign->id, $this->companyA->id);
                $this->fail('A direct master-data service lookup crossed the tenant boundary.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_master_services_reject_a_caller_selected_foreign_company(): void
    {
        $account = ChartOfAccount::withoutGlobalScopes()->create($this->accountData($this->companyA->id, 'SERVICE-GUARD-ACC'));
        $customer = Customer::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'code' => 'SERVICE-GUARD-CUS', 'name' => 'A']);
        $supplier = Supplier::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'code' => 'SERVICE-GUARD-SUP', 'name' => 'A']);
        $bank = BankAccount::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'account_number' => 'SERVICE-GUARD-BANK', 'bank_name' => 'A']);
        $item = Item::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'code' => 'SERVICE-GUARD-ITEM', 'name' => 'A', 'type' => 'goods']);

        foreach ([
            [app(AccountService::class), $account],
            [app(CustomerService::class), $customer],
            [app(SupplierService::class), $supplier],
            [app(BankAccountService::class), $bank],
            [app(ItemService::class), $item],
        ] as [$service, $model]) {
            try {
                $service->getById($model->id, $this->companyB->id);
                $this->fail('A direct master-data service accepted a foreign company selector.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('company_id', $exception->errors());
            }

            try {
                $service->update($model->id, ['company_id' => $this->companyB->id, 'name' => 'cross-tenant'], $this->companyA->id);
                $this->fail('A direct master-data service allowed changing the owning company.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('company_id', $exception->errors());
            }
        }
    }

    public function test_foreign_parent_and_default_account_references_are_rejected(): void
    {
        $foreignAccount = ChartOfAccount::withoutGlobalScopes()->create($this->accountData($this->companyB->id, 'FOREIGN-ACC'));
        $foreignTerm = PaymentTerm::withoutGlobalScopes()->create(['company_id' => $this->companyB->id, 'code' => 'FOREIGN-TERM', 'name' => 'Foreign', 'due_days' => 1]);
        $foreignEmployee = Employee::withoutGlobalScopes()->create(['company_id' => $this->companyB->id, 'code' => 'FOREIGN-EMP', 'name' => 'Foreign employee', 'status' => 'active']);

        $this->postJson('/api/v1/master/accounts', [
            'company_id' => $this->companyB->id,
            'code' => 'ACC-WITH-FOREIGN-PARENT',
            'name' => 'Account',
            'type' => 'asset',
            'nature' => 'debit',
            'level' => 2,
            'parent_code' => $foreignAccount->code,
        ])->assertUnprocessable();

        $this->postJson('/api/v1/master/customers', [
            'code' => 'CUS-FOREIGN-DEFAULTS',
            'name' => 'Customer',
            'default_account' => $foreignAccount->code,
        ])->assertUnprocessable();
        $this->postJson('/api/v1/master/customers', [
            'code' => 'CUS-FOREIGN-EMPLOYEE',
            'name' => 'Customer',
            'assigned_employee_id' => $foreignEmployee->id,
        ])->assertUnprocessable();
        $this->postJson('/api/v1/master/suppliers', [
            'code' => 'SUP-FOREIGN-TERM',
            'name' => 'Supplier',
            'payment_term' => $foreignTerm->code,
        ])->assertUnprocessable();
        $this->postJson('/api/v1/master/voucher-type-settings', [
            'voucher_type' => 'thu_tien_mat',
            'name' => 'Foreign account setting',
            'debit_account' => $foreignAccount->code,
        ])->assertUnprocessable();
    }

    public function test_unassigned_user_is_forbidden_from_master_data(): void
    {
        Sanctum::actingAs(User::factory()->create(['company_id' => null]));
        foreach ([
            '/api/v1/master/company',
            '/api/v1/master/accounts',
            '/api/v1/master/customers',
            '/api/v1/master/suppliers',
            '/api/v1/master/employees',
            '/api/v1/master/payment-terms',
            '/api/v1/master/item-categories',
            '/api/v1/master/units',
            '/api/v1/master/warehouses',
            '/api/v1/master/voucher-type-settings',
            '/api/v1/bank/accounts',
        ] as $endpoint) {
            $this->getJson($endpoint)->assertForbidden();
        }
    }

    /** @return array<int, array{endpoint: string, a: Model, b: Model, code_a: string, code_b: string, update: array}> */
    private function masterPairs(): array
    {
        $accountA = ChartOfAccount::withoutGlobalScopes()->create($this->accountData($this->companyA->id, 'ACC-A'));
        $accountB = ChartOfAccount::withoutGlobalScopes()->create($this->accountData($this->companyB->id, 'ACC-B'));

        return [
            $this->pair('/api/v1/master/accounts', $accountA, $accountB, 'ACC-A', 'ACC-B'),
            $this->pair('/api/v1/master/customers', Customer::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'code' => 'CUS-A', 'name' => 'Customer A']), Customer::withoutGlobalScopes()->create(['company_id' => $this->companyB->id, 'code' => 'CUS-B', 'name' => 'Customer B']), 'CUS-A', 'CUS-B'),
            $this->pair('/api/v1/master/suppliers', Supplier::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'code' => 'SUP-A', 'name' => 'Supplier A']), Supplier::withoutGlobalScopes()->create(['company_id' => $this->companyB->id, 'code' => 'SUP-B', 'name' => 'Supplier B']), 'SUP-A', 'SUP-B'),
            $this->pair('/api/v1/master/employees', Employee::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'code' => 'EMP-A', 'name' => 'Employee A', 'status' => 'active']), Employee::withoutGlobalScopes()->create(['company_id' => $this->companyB->id, 'code' => 'EMP-B', 'name' => 'Employee B', 'status' => 'active']), 'EMP-A', 'EMP-B'),
            $this->pair('/api/v1/master/payment-terms', PaymentTerm::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'code' => 'TERM-A', 'name' => 'Term A', 'due_days' => 30, 'is_active' => true]), PaymentTerm::withoutGlobalScopes()->create(['company_id' => $this->companyB->id, 'code' => 'TERM-B', 'name' => 'Term B', 'due_days' => 30, 'is_active' => true]), 'TERM-A', 'TERM-B'),
            $this->pair('/api/v1/master/item-categories', ItemCategory::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'code' => 'CAT-A', 'name' => 'Category A', 'is_active' => true]), ItemCategory::withoutGlobalScopes()->create(['company_id' => $this->companyB->id, 'code' => 'CAT-B', 'name' => 'Category B', 'is_active' => true]), 'CAT-A', 'CAT-B'),
            $this->pair('/api/v1/master/units', Unit::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'code' => 'UNIT-A', 'name' => 'Unit A', 'is_active' => true]), Unit::withoutGlobalScopes()->create(['company_id' => $this->companyB->id, 'code' => 'UNIT-B', 'name' => 'Unit B', 'is_active' => true]), 'UNIT-A', 'UNIT-B'),
            $this->pair('/api/v1/master/warehouses', Warehouse::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'code' => 'WH-A-MASTER', 'name' => 'Warehouse A', 'is_active' => true]), Warehouse::withoutGlobalScopes()->create(['company_id' => $this->companyB->id, 'code' => 'WH-B-MASTER', 'name' => 'Warehouse B', 'is_active' => true]), 'WH-A-MASTER', 'WH-B-MASTER'),
            $this->pair('/api/v1/master/voucher-type-settings', VoucherTypeSetting::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'voucher_type' => 'thu_tien_mat', 'name' => 'SETTING-A', 'is_active' => true]), VoucherTypeSetting::withoutGlobalScopes()->create(['company_id' => $this->companyB->id, 'voucher_type' => 'thu_tien_mat', 'name' => 'SETTING-B', 'is_active' => true]), 'SETTING-A', 'SETTING-B'),
            $this->pair('/api/v1/bank/accounts', BankAccount::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'account_number' => 'BANK-A', 'bank_name' => 'Bank A']), BankAccount::withoutGlobalScopes()->create(['company_id' => $this->companyB->id, 'account_number' => 'BANK-B', 'bank_name' => 'Bank B']), 'BANK-A', 'BANK-B', ['bank_name' => 'attack']),
        ];
    }

    private function pair(string $endpoint, Model $a, Model $b, string $codeA, string $codeB, array $update = ['name' => 'attack']): array
    {
        return ['endpoint' => $endpoint, 'a' => $a, 'b' => $b, 'code_a' => $codeA, 'code_b' => $codeB, 'update' => $update];
    }

    private function accountData(int $companyId, string $code): array
    {
        return ['company_id' => $companyId, 'code' => $code, 'name' => $code, 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false];
    }
}
