<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BankPaymentService;
use App\Services\BankReceiptService;
use App\Services\CashPaymentService;
use App\Services\CashReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashBankServiceTenantReferenceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $foreignCompany;
    private BankAccount $bankAccount;
    private BankAccount $foreignBankAccount;
    private Customer $foreignCustomer;
    private Supplier $foreignSupplier;
    private SalesInvoice $foreignSalesInvoice;
    private PurchaseInvoice $foreignPurchaseInvoice;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('accounting.enforce_cash_bank_posting_policy', false);
        config()->set('accounting.enforce_cash_bank_posting_approval', false);
        config()->set('accounting.enforce_cash_bank_posting_account_mappings', false);

        $this->company = Company::create(['name' => 'Cash-bank service tenant', 'tax_code' => 'CB-SVC-A']);
        $this->foreignCompany = Company::create(['name' => 'Foreign cash-bank tenant', 'tax_code' => 'CB-SVC-B']);
        $user = User::factory()->create();
        $this->configureAccountingTenant($user, $this->company);
        Sanctum::actingAs($user);

        $this->bankAccount = BankAccount::create([
            'company_id' => $this->company->id, 'account_number' => 'CB-SVC-A-001', 'bank_name' => 'Current bank',
        ]);
        $this->foreignBankAccount = BankAccount::withoutGlobalScopes()->create([
            'company_id' => $this->foreignCompany->id, 'account_number' => 'CB-SVC-B-001', 'bank_name' => 'Foreign bank',
        ]);
        $this->foreignCustomer = Customer::withoutGlobalScopes()->create([
            'company_id' => $this->foreignCompany->id, 'code' => 'CUS-FOREIGN-SVC', 'name' => 'Foreign customer',
        ]);
        $this->foreignSupplier = Supplier::withoutGlobalScopes()->create([
            'company_id' => $this->foreignCompany->id, 'code' => 'SUP-FOREIGN-SVC', 'name' => 'Foreign supplier',
        ]);
        $this->foreignSalesInvoice = SalesInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->foreignCompany->id, 'customer_id' => $this->foreignCustomer->id,
            'invoice_number' => 'SI-FOREIGN-SVC', 'invoice_date' => '2026-08-23', 'accounting_date' => '2026-08-23',
            'due_date' => '2026-08-23', 'total_amount' => 100,
        ]);
        $this->foreignPurchaseInvoice = PurchaseInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->foreignCompany->id, 'supplier_id' => $this->foreignSupplier->id,
            'supplier_name' => $this->foreignSupplier->name, 'invoice_number' => 'PI-FOREIGN-SVC',
            'invoice_date' => '2026-08-23', 'accounting_date' => '2026-08-23', 'due_date' => '2026-08-23', 'total_amount' => 100,
        ]);
    }

    public function test_direct_service_create_rejects_foreign_top_and_line_contacts(): void
    {
        $cases = [
            [CashReceiptService::class, $this->cashReceiptPayload(['contact_type' => 'customer', 'contact_id' => $this->foreignCustomer->id]), 'contact_id'],
            [CashPaymentService::class, $this->cashPaymentPayload(['contact_type' => 'supplier', 'contact_id' => $this->foreignSupplier->id]), 'contact_id'],
            [BankReceiptService::class, $this->bankReceiptPayload(['contact_type' => 'customer', 'contact_id' => $this->foreignCustomer->id]), 'contact_id'],
            [BankPaymentService::class, $this->bankPaymentPayload(['contact_type' => 'supplier', 'contact_id' => $this->foreignSupplier->id]), 'contact_id'],
            [CashReceiptService::class, $this->cashReceiptPayload(['lines' => [['credit_account' => '131', 'amount' => 100, 'line_contact_id' => $this->foreignCustomer->id]]]), 'lines.0.line_contact_id'],
            [BankPaymentService::class, $this->bankPaymentPayload(['lines' => [['debit_account' => '331', 'amount' => 100, 'line_contact_id' => $this->foreignSupplier->id]]]), 'lines.0.line_contact_id'],
        ];

        foreach ($cases as [$service, $payload, $field]) {
            try {
                app($service)->create($payload);
                $this->fail("$service accepted a foreign contact through a direct service call.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }

    public function test_direct_service_create_rejects_foreign_invoice_references(): void
    {
        $cases = [
            [CashReceiptService::class, $this->cashReceiptPayload(['lines' => [['credit_account' => '131', 'amount' => 100, 'invoice_id' => $this->foreignSalesInvoice->id]]]), 'cash receipt'],
            [BankReceiptService::class, $this->bankReceiptPayload(['lines' => [['credit_account' => '131', 'amount' => 100, 'invoice_id' => $this->foreignSalesInvoice->id]]]), 'bank receipt'],
            [CashPaymentService::class, $this->cashPaymentPayload(['lines' => [['debit_account' => '331', 'amount' => 100, 'invoice_id' => $this->foreignPurchaseInvoice->id]]]), 'cash payment'],
            [BankPaymentService::class, $this->bankPaymentPayload(['lines' => [['debit_account' => '331', 'amount' => 100, 'invoice_id' => $this->foreignPurchaseInvoice->id]]]), 'bank payment'],
        ];

        foreach ($cases as [$service, $payload, $name]) {
            try {
                app($service)->create($payload);
                $this->fail("$name accepted a foreign invoice through a direct service call.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('lines.0.invoice_id', $exception->errors());
            }
        }
    }

    public function test_direct_bank_services_reject_foreign_primary_or_line_bank_accounts(): void
    {
        foreach ([
            [BankReceiptService::class, $this->bankReceiptPayload(['bank_account_id' => $this->foreignBankAccount->id]), 'bank_account_id'],
            [BankPaymentService::class, $this->bankPaymentPayload(['lines' => [['debit_account' => '331', 'amount' => 100, 'bank_account_id' => $this->foreignBankAccount->id]]]), 'lines.0.bank_account_id'],
        ] as [$service, $payload, $field]) {
            try {
                app($service)->create($payload);
                $this->fail("$service accepted a foreign bank account through a direct service call.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }

    public function test_direct_cash_services_normalize_contact_codes_and_reject_ambiguous_references(): void
    {
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'CUS-CODE-SVC',
            'name' => 'Local code customer',
        ]);
        $supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'SUP-CODE-SVC',
            'name' => 'Local code supplier',
        ]);

        $receipt = app(CashReceiptService::class)->create($this->cashReceiptPayload([
            'contact_type' => 'customer',
            'contact_id' => $customer->code,
        ]));
        $this->assertSame((int) $customer->id, (int) $receipt->contact_id);

        $payment = app(CashPaymentService::class)->create($this->cashPaymentPayload([
            'contact_type' => 'supplier',
            'contact_id' => $supplier->code,
        ]));
        $this->assertSame((int) $supplier->id, (int) $payment->contact_id);

        Customer::create([
            'company_id' => $this->company->id,
            'code' => 'DUPLICATE-CODE',
            'name' => 'Duplicate customer',
        ]);
        Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'DUPLICATE-CODE',
            'name' => 'Duplicate supplier',
        ]);

        try {
            app(CashReceiptService::class)->create($this->cashReceiptPayload([
                'contact_id' => 'DUPLICATE-CODE',
            ]));
            $this->fail('An untyped reference matching customer and supplier must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('contact_id', $exception->errors());
        }
    }

    private function cashReceiptPayload(array $overrides = []): array
    {
        return [...['company_id' => $this->company->id, 'voucher_number' => 'PT-SVC-'.uniqid(), 'voucher_date' => '2026-08-23', 'posting_date' => '2026-08-23', 'lines' => [['credit_account' => '131', 'amount' => 100]]], ...$overrides];
    }

    private function cashPaymentPayload(array $overrides = []): array
    {
        return [...['company_id' => $this->company->id, 'voucher_number' => 'PC-SVC-'.uniqid(), 'voucher_date' => '2026-08-23', 'posting_date' => '2026-08-23', 'lines' => [['debit_account' => '331', 'amount' => 100]]], ...$overrides];
    }

    private function bankReceiptPayload(array $overrides = []): array
    {
        return [...['company_id' => $this->company->id, 'bank_account_id' => $this->bankAccount->id, 'voucher_number' => 'BC-SVC-'.uniqid(), 'voucher_date' => '2026-08-23', 'posting_date' => '2026-08-23', 'lines' => [['credit_account' => '131', 'amount' => 100]]], ...$overrides];
    }

    private function bankPaymentPayload(array $overrides = []): array
    {
        return [...['company_id' => $this->company->id, 'bank_account_id' => $this->bankAccount->id, 'voucher_number' => 'UNC-SVC-'.uniqid(), 'voucher_date' => '2026-08-23', 'posting_date' => '2026-08-23', 'lines' => [['debit_account' => '331', 'amount' => 100]]], ...$overrides];
    }
}
