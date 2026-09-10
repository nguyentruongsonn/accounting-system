<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankPayment;
use App\Models\BankReceipt;
use App\Models\BorrowingContract;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\Company;
use App\Models\ApArFxRevaluation;
use App\Models\DebtAdjustment;
use App\Models\EInvoiceDocument;
use App\Models\User;
use App\Services\BankPaymentService;
use App\Services\BankReceiptService;
use App\Services\CashPaymentService;
use App\Services\CashReceiptService;
use App\Services\InventoryIssueService;
use App\Services\InventoryReceiptService;
use App\Services\JournalEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TransactionTenantBoundaryCodeAndListTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_code_generators_reject_a_foreign_company(): void
    {
        [$company, $foreignCompany] = $this->tenantPair();
        Sanctum::actingAs(User::factory()->create(['company_id' => $company->id]));

        $cases = [
            fn () => app(BankPaymentService::class)->generateNextCode($foreignCompany->id),
            fn () => app(BankReceiptService::class)->generateNextCode($foreignCompany->id),
            fn () => app(CashPaymentService::class)->getNextCode($foreignCompany->id),
            fn () => app(CashReceiptService::class)->getNextCode($foreignCompany->id),
            fn () => app(InventoryIssueService::class)->generateNextCode($foreignCompany->id),
            fn () => app(InventoryReceiptService::class)->generateNextCode($foreignCompany->id),
            fn () => app(JournalEntryService::class)->generateNextCode($foreignCompany->id),
        ];

        foreach ($cases as $case) {
            try {
                $case();
                $this->fail('A transaction code generator accepted a foreign company.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('company_id', $exception->errors());
            }
        }
    }

    public function test_cash_and_bank_lists_apply_the_authenticated_company_filter(): void
    {
        [$company, $foreignCompany] = $this->tenantPair();
        Sanctum::actingAs(User::factory()->create(['company_id' => $company->id]));

        $bankAccount = BankAccount::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'account_number' => 'A-'.uniqid(),
            'bank_name' => 'Tenant A Bank',
        ]);
        $foreignBankAccount = BankAccount::withoutGlobalScopes()->create([
            'company_id' => $foreignCompany->id,
            'account_number' => 'B-'.uniqid(),
            'bank_name' => 'Tenant B Bank',
        ]);

        $common = [
            'voucher_date' => '2026-08-21',
            'posting_date' => '2026-08-21',
            'status' => 'draft',
            'is_posted' => false,
        ];
        $cashPayment = CashPayment::withoutGlobalScopes()->create([
            ...$common, 'company_id' => $company->id, 'voucher_number' => 'PC-A-001', 'total_amount' => 100,
        ]);
        CashPayment::withoutGlobalScopes()->create([
            ...$common, 'company_id' => $foreignCompany->id, 'voucher_number' => 'PC-B-001', 'total_amount' => 100,
        ]);
        $cashReceipt = CashReceipt::withoutGlobalScopes()->create([
            ...$common, 'company_id' => $company->id, 'voucher_number' => 'PT-A-001', 'total_amount' => 100,
        ]);
        CashReceipt::withoutGlobalScopes()->create([
            ...$common, 'company_id' => $foreignCompany->id, 'voucher_number' => 'PT-B-001', 'total_amount' => 100,
        ]);
        $bankPayment = BankPayment::withoutGlobalScopes()->create([
            ...$common, 'company_id' => $company->id, 'bank_account_id' => $bankAccount->id,
            'voucher_number' => 'UNC-A-001', 'amount' => 100,
        ]);
        BankPayment::withoutGlobalScopes()->create([
            ...$common, 'company_id' => $foreignCompany->id, 'bank_account_id' => $foreignBankAccount->id,
            'voucher_number' => 'UNC-B-001', 'amount' => 100,
        ]);
        $bankReceipt = BankReceipt::withoutGlobalScopes()->create([
            ...$common, 'company_id' => $company->id, 'bank_account_id' => $bankAccount->id,
            'voucher_number' => 'BC-A-001', 'amount' => 100,
        ]);
        BankReceipt::withoutGlobalScopes()->create([
            ...$common, 'company_id' => $foreignCompany->id, 'bank_account_id' => $foreignBankAccount->id,
            'voucher_number' => 'BC-B-001', 'amount' => 100,
        ]);

        $this->assertSame([$cashPayment->id], app(CashPaymentService::class)->getAll(['company_id' => $company->id])->pluck('id')->all());
        $this->assertSame([$cashReceipt->id], app(CashReceiptService::class)->getAll(['company_id' => $company->id])->pluck('id')->all());
        $this->assertSame([$bankPayment->id], app(BankPaymentService::class)->getAll($company->id)->pluck('id')->all());
        $this->assertSame([$bankReceipt->id], app(BankReceiptService::class)->getAll($company->id)->pluck('id')->all());

        $this->expectException(ValidationException::class);
        app(CashPaymentService::class)->getAll(['company_id' => $foreignCompany->id]);
    }

    public function test_journal_entry_creation_rejects_a_foreign_company(): void
    {
        [$company, $foreignCompany] = $this->tenantPair();
        Sanctum::actingAs(User::factory()->create(['company_id' => $company->id]));

        $this->expectException(ValidationException::class);
        app(JournalEntryService::class)->create(['company_id' => $foreignCompany->id]);
    }

    public function test_ap_ar_indexes_are_explicitly_scoped_to_the_authenticated_company(): void
    {
        [$company, $foreignCompany] = $this->tenantPair();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $foreignActor = User::factory()->create(['company_id' => $foreignCompany->id]);
        Sanctum::actingAs($actor);

        $debt = DebtAdjustment::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'ledger' => 'ap', 'adjustment_kind' => 'write_off',
            'voucher_number' => 'DA-A-001', 'voucher_date' => '2026-08-21', 'accounting_date' => '2026-08-21',
            'reference_document_type' => 'purchase_invoice', 'reference_document_id' => 1,
            'amount' => '10.00', 'debit_account' => 'tenant-debit', 'credit_account' => 'tenant-credit',
            'status' => 'draft', 'is_posted' => false, 'created_by' => $actor->id,
        ]);
        DebtAdjustment::withoutGlobalScopes()->create([
            'company_id' => $foreignCompany->id, 'ledger' => 'ap', 'adjustment_kind' => 'write_off',
            'voucher_number' => 'DA-B-001', 'voucher_date' => '2026-08-21', 'accounting_date' => '2026-08-21',
            'reference_document_type' => 'purchase_invoice', 'reference_document_id' => 1,
            'amount' => '10.00', 'debit_account' => 'tenant-debit', 'credit_account' => 'tenant-credit',
            'status' => 'draft', 'is_posted' => false, 'created_by' => $foreignActor->id,
        ]);
        $fx = ApArFxRevaluation::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'ledger' => 'ap', 'reference_document_type' => 'purchase_invoice',
            'reference_document_id' => 1, 'voucher_number' => 'FX-A-001', 'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21', 'original_currency' => 'USD', 'foreign_open_amount_raw' => '1',
            'foreign_open_amount_scale' => 0, 'closing_exchange_rate_raw' => '1', 'closing_exchange_rate_scale' => 0,
            'carrying_functional_amount' => '10.00', 'revalued_functional_amount' => '11.00',
            'adjustment_functional_amount' => '1.00', 'effect' => 'gain', 'debit_account' => 'tenant-debit',
            'credit_account' => 'tenant-credit', 'reason' => 'Tenant test', 'status' => 'draft', 'is_posted' => false,
            'created_by' => $actor->id,
        ]);
        ApArFxRevaluation::withoutGlobalScopes()->create([
            'company_id' => $foreignCompany->id, 'ledger' => 'ap', 'reference_document_type' => 'purchase_invoice',
            'reference_document_id' => 1, 'voucher_number' => 'FX-B-001', 'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21', 'original_currency' => 'USD', 'foreign_open_amount_raw' => '1',
            'foreign_open_amount_scale' => 0, 'closing_exchange_rate_raw' => '1', 'closing_exchange_rate_scale' => 0,
            'carrying_functional_amount' => '10.00', 'revalued_functional_amount' => '11.00',
            'adjustment_functional_amount' => '1.00', 'effect' => 'gain', 'debit_account' => 'tenant-debit',
            'credit_account' => 'tenant-credit', 'reason' => 'Tenant test', 'status' => 'draft', 'is_posted' => false,
            'created_by' => $foreignActor->id,
        ]);

        $this->getJson('/api/v1/debt-adjustments')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $debt->id);
        $this->getJson('/api/v1/ap-ar-fx-revaluations')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $fx->id);
    }

    public function test_gl_lifecycle_routes_require_an_authenticated_company(): void
    {
        Sanctum::actingAs(User::factory()->create(['company_id' => null]));

        $this->getJson('/api/v1/gl/journal-entries/1')->assertForbidden();
    }

    public function test_borrowing_contract_list_and_show_are_tenant_scoped(): void
    {
        [$company, $foreignCompany] = $this->tenantPair();
        $actor = User::factory()->create(['company_id' => $company->id]);

        $contract = BorrowingContract::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'contract_number' => 'BC-A-'.uniqid(),
            'lender_name' => 'Tenant A lender',
            'amount' => '100.00',
            'disbursement_date' => '2026-08-21',
            'maturity_date' => '2027-08-21',
            'debit_account' => 'tenant-debit',
            'interest_account' => 'tenant-interest',
        ]);
        $foreignContract = BorrowingContract::withoutGlobalScopes()->create([
            'company_id' => $foreignCompany->id,
            'contract_number' => 'BC-B-'.uniqid(),
            'lender_name' => 'Tenant B lender',
            'amount' => '100.00',
            'disbursement_date' => '2026-08-21',
            'maturity_date' => '2027-08-21',
            'debit_account' => 'tenant-debit',
            'interest_account' => 'tenant-interest',
        ]);

        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/borrowing-contracts')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $contract->id);
        $this->getJson('/api/v1/borrowing-contracts/'.$foreignContract->id)->assertNotFound();
    }

    public function test_einvoice_document_list_requires_an_authenticated_company_and_scopes_rows(): void
    {
        [$company, $foreignCompany] = $this->tenantPair();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $permission = Permission::firstOrCreate(['name' => 'einvoices.view', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // The legacy test bootstrap preloads the permissions relation; use a
        // fresh principal so the route middleware observes the new grant.
        $actor = $actor->fresh();
        EInvoiceDocument::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'accounting_document_type' => 'test', 'accounting_document_id' => 1,
            'lifecycle_status' => 'draft', 'payload_hash' => hash('sha256', 'tenant-a'),
            'occurred_at' => '2026-08-21 10:00:00', 'recorded_by' => $actor->id,
        ]);
        EInvoiceDocument::withoutGlobalScopes()->create([
            'company_id' => $foreignCompany->id, 'accounting_document_type' => 'test', 'accounting_document_id' => 2,
            'lifecycle_status' => 'draft', 'payload_hash' => hash('sha256', 'tenant-b'),
            'occurred_at' => '2026-08-21 11:00:00', 'recorded_by' => $actor->id,
        ]);

        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/einvoice-documents')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.company_id', $company->id);
        $this->getJson('/api/v1/einvoice-documents?per_page=1000')->assertStatus(422);

        $unassigned = User::factory()->create(['company_id' => null]);
        $unassigned->givePermissionTo($permission);
        $unassigned = $unassigned->fresh();
        Sanctum::actingAs($unassigned);
        $this->getJson('/api/v1/einvoice-documents')->assertForbidden();
    }

    public function test_period_resource_does_not_advertise_unimplemented_lifecycle_actions(): void
    {
        Sanctum::actingAs(User::factory()->create(['company_id' => 1]));

        $this->getJson('/api/v1/gl/periods/1')->assertNotFound();
        $this->putJson('/api/v1/gl/periods/1', [])->assertNotFound();
        $this->deleteJson('/api/v1/gl/periods/1')->assertNotFound();
    }

    /** @return array{0: Company, 1: Company} */
    private function tenantPair(): array
    {
        return [
            Company::create(['name' => 'Transaction tenant A '.uniqid(), 'tax_code' => 'TX-A-'.uniqid()]),
            Company::create(['name' => 'Transaction tenant B '.uniqid(), 'tax_code' => 'TX-B-'.uniqid()]),
        ];
    }
}
