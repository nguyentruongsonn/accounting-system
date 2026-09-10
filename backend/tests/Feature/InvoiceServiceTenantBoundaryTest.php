<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\PurchaseContract;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\PurchaseInvoiceService;
use App\Services\SalesInvoiceService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceServiceTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $foreignCompany;
    private Customer $foreignCustomer;
    private Supplier $foreignSupplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Invoice service A', 'tax_code' => 'INV-SVC-A']);
        $this->foreignCompany = Company::create(['name' => 'Invoice service B', 'tax_code' => 'INV-SVC-B']);
        $actor = User::factory()->create();
        $this->configureAccountingTenant($actor, $this->company);
        Sanctum::actingAs($actor);

        $this->foreignCustomer = Customer::withoutGlobalScopes()->create([
            'company_id' => $this->foreignCompany->id,
            'code' => 'INV-FOREIGN-CUS',
            'name' => 'Foreign customer',
        ]);
        $this->foreignSupplier = Supplier::withoutGlobalScopes()->create([
            'company_id' => $this->foreignCompany->id,
            'code' => 'INV-FOREIGN-SUP',
            'name' => 'Foreign supplier',
        ]);
    }

    public function test_direct_create_rejects_foreign_customer_and_supplier(): void
    {
        try {
            app(SalesInvoiceService::class)->create([
                'company_id' => $this->company->id,
                'customer_id' => $this->foreignCustomer->id,
                'invoice_number' => 'INV-FOREIGN-CREATE-SALES',
                'invoice_date' => '2026-08-23',
                'lines' => [['quantity' => 1, 'unit_price' => 100]],
            ]);
            $this->fail('Sales invoice service accepted a foreign customer.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_id', $exception->errors());
        }

        try {
            app(PurchaseInvoiceService::class)->create([
                'company_id' => $this->company->id,
                'supplier_id' => $this->foreignSupplier->id,
                'invoice_number' => 'INV-FOREIGN-CREATE-PURCHASE',
                'invoice_date' => '2026-08-23',
                'lines' => [['quantity' => 1, 'unit_price' => 100]],
            ]);
            $this->fail('Purchase invoice service accepted a foreign supplier.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('supplier_id', $exception->errors());
        }

        $this->assertDatabaseMissing('sales_invoices', ['invoice_number' => 'INV-FOREIGN-CREATE-SALES']);
        $this->assertDatabaseMissing('purchase_invoices', ['invoice_number' => 'INV-FOREIGN-CREATE-PURCHASE']);
    }

    public function test_direct_invoice_reads_and_mutations_cannot_cross_tenant(): void
    {
        $sales = SalesInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->foreignCompany->id,
            'customer_id' => $this->foreignCustomer->id,
            'invoice_number' => 'INV-FOREIGN-READ-SALES',
            'invoice_date' => '2026-08-23',
            'accounting_date' => '2026-08-23',
            'total_amount' => 100,
        ]);
        $purchase = PurchaseInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->foreignCompany->id,
            'supplier_id' => $this->foreignSupplier->id,
            'invoice_number' => 'INV-FOREIGN-READ-PURCHASE',
            'invoice_date' => '2026-08-23',
            'accounting_date' => '2026-08-23',
            'total_amount' => 100,
        ]);

        foreach ([
            [SalesInvoiceService::class, $sales->id],
            [PurchaseInvoiceService::class, $purchase->id],
        ] as [$service, $id]) {
            foreach (['getById', 'update', 'post', 'delete', 'duplicate'] as $operation) {
                try {
                    $args = $operation === 'update'
                        ? [$id, ['description' => 'cross-tenant mutation']]
                        : [$id];
                    app($service)->{$operation}(...$args);
                    $this->fail("{$service} {$operation} crossed the tenant boundary.");
                } catch (ModelNotFoundException) {
                    $this->assertTrue(true);
                }
            }
        }
    }

    public function test_purchase_invoice_list_cannot_be_requested_for_a_foreign_company(): void
    {
        PurchaseInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->foreignCompany->id,
            'supplier_id' => $this->foreignSupplier->id,
            'invoice_number' => 'INV-FOREIGN-LIST-PURCHASE',
            'invoice_date' => '2026-08-23',
            'accounting_date' => '2026-08-23',
            'total_amount' => 100,
        ]);

        try {
            app(PurchaseInvoiceService::class)->getAll($this->foreignCompany->id);
            $this->fail('Purchase invoice listing accepted a foreign company context.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company_id', $exception->errors());
        }
    }

    public function test_unassigned_authenticated_invoice_service_cannot_read_any_tenant(): void
    {
        $invoice = SalesInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'customer_id' => Customer::create([
                'company_id' => $this->company->id,
                'code' => 'INV-OWN-CUS',
                'name' => 'Own customer',
            ])->id,
            'invoice_number' => 'INV-UNASSIGNED',
            'invoice_date' => '2026-08-23',
            'total_amount' => 1,
        ]);
        Sanctum::actingAs(User::factory()->create(['company_id' => null]));

        try {
            app(SalesInvoiceService::class)->getById($invoice->id);
            $this->fail('An unassigned actor must not read invoice data.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company_id', $exception->errors());
        }
    }

    public function test_invoice_creation_ignores_forged_lifecycle_and_audit_fields(): void
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'code' => 'OWN-CUS', 'name' => 'Own customer']);

        $sales = app(SalesInvoiceService::class)->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-LIFECYCLE-SALES',
            'invoice_date' => '2026-08-23',
            'status' => 'posted',
            'payment_status' => 'Paid',
            'is_posted' => true,
            'created_by' => 999999,
            'updated_by' => 999999,
            'lines' => [['quantity' => 1, 'unit_price' => 100, 'debit_account' => '131', 'credit_account' => '5111']],
        ]);

        $this->assertFalse($sales->is_posted);
        $this->assertSame('Unpaid', $sales->status);
        $this->assertSame($this->userId(), (int) $sales->created_by);
        $this->assertNotSame(999999, (int) $sales->updated_by);
    }

    public function test_sales_cash_settlement_is_derived_from_explicit_line_account_evidence(): void
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'code' => 'CASH-CUS', 'name' => 'Cash customer']);
        $invoice = app(SalesInvoiceService::class)->create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id,
            'invoice_number' => 'INV-CASH-EVIDENCE', 'invoice_date' => '2026-08-23',
            'payment_status' => 'Paid',
            'lines' => [['quantity' => 1, 'unit_price' => 100, 'debit_account' => '1111', 'credit_account' => '5111']],
        ]);

        $this->assertSame('cash', $invoice->payment_method);
        $this->assertSame('Paid', $invoice->status);
        $this->assertSame('Paid', $invoice->payment_status);
    }

    public function test_invoice_line_order_and_contract_references_must_be_same_tenant(): void
    {
        $foreignOrder = PurchaseOrder::withoutGlobalScopes()->create(['company_id' => $this->foreignCompany->id, 'order_number' => 'PO-X', 'order_date' => '2026-08-23']);
        $foreignContract = PurchaseContract::withoutGlobalScopes()->create(['company_id' => $this->foreignCompany->id, 'contract_number' => 'PC-X', 'signed_date' => '2026-08-23', 'effective_date' => '2026-08-23']);
        $ownSupplier = Supplier::create(['company_id' => $this->company->id, 'code' => 'OWN-SUP', 'name' => 'Own supplier']);

        $ownOrder = PurchaseOrder::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'order_number' => 'PO-OWN', 'order_date' => '2026-08-23']);
        $ownContract = PurchaseContract::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'contract_number' => 'PC-OWN', 'signed_date' => '2026-08-23', 'effective_date' => '2026-08-23']);
        $valid = app(PurchaseInvoiceService::class)->create([
            'company_id' => $this->company->id, 'supplier_id' => $ownSupplier->id,
            'invoice_number' => 'INV-REF-VALID', 'invoice_date' => '2026-08-23',
            'lines' => [['quantity' => 1, 'unit_price' => 100, 'debit_account' => '1561', 'credit_account' => '331', 'order_id' => $ownOrder->id, 'contract_id' => $ownContract->id]],
        ]);
        $this->assertSame($this->company->id, $valid->company_id);

        foreach ([['order_id' => $foreignOrder->id], ['contract_id' => $foreignContract->id]] as $reference) {
            try {
                app(PurchaseInvoiceService::class)->create([
                    'company_id' => $this->company->id, 'supplier_id' => $ownSupplier->id,
                    'invoice_number' => 'INV-REF-'.uniqid(), 'invoice_date' => '2026-08-23',
                    'lines' => [['quantity' => 1, 'unit_price' => 100, 'debit_account' => '1561', 'credit_account' => '331'] + $reference],
                ]);
                $this->fail('A foreign commercial reference was accepted.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_authenticated_user_without_company_cannot_list_either_invoice_domain(): void
    {
        Sanctum::actingAs(User::factory()->create(['company_id' => null]));

        foreach ([SalesInvoiceService::class, PurchaseInvoiceService::class] as $service) {
            try {
                app($service)->getAll();
                $this->fail("{$service} exposed invoices without a tenant.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('company_id', $exception->errors());
            }
        }
    }

    public function test_invoice_update_rejects_foreign_line_commercial_references(): void
    {
        $ownCustomer = Customer::create(['company_id' => $this->company->id, 'code' => 'UPD-CUS', 'name' => 'Own customer']);
        $ownSupplier = Supplier::create(['company_id' => $this->company->id, 'code' => 'UPD-SUP', 'name' => 'Own supplier']);
        $foreignOrder = PurchaseOrder::withoutGlobalScopes()->create(['company_id' => $this->foreignCompany->id, 'order_number' => 'PO-UPD-X', 'order_date' => '2026-08-23']);

        $sales = app(SalesInvoiceService::class)->create([
            'company_id' => $this->company->id, 'customer_id' => $ownCustomer->id,
            'invoice_number' => 'INV-UPD-SALES', 'invoice_date' => '2026-08-23',
            'lines' => [['quantity' => 1, 'unit_price' => 100, 'debit_account' => '131', 'credit_account' => '5111']],
        ]);

        try {
            app(SalesInvoiceService::class)->update($sales->id, [
                'lines' => [['quantity' => 1, 'unit_price' => 100, 'debit_account' => '131', 'credit_account' => '5111', 'order_id' => $foreignOrder->id]],
            ]);
            $this->fail('Sales invoice update accepted a foreign order reference.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.order_id', $exception->errors());
        }

        $purchase = app(PurchaseInvoiceService::class)->create([
            'company_id' => $this->company->id, 'supplier_id' => $ownSupplier->id,
            'invoice_number' => 'INV-UPD-PURCHASE', 'invoice_date' => '2026-08-23',
            'lines' => [['quantity' => 1, 'unit_price' => 100, 'debit_account' => '1561', 'credit_account' => '331']],
        ]);

        try {
            app(PurchaseInvoiceService::class)->update($purchase->id, [
                'lines' => [['quantity' => 1, 'unit_price' => 100, 'debit_account' => '1561', 'credit_account' => '331', 'order_id' => $foreignOrder->id]],
            ]);
            $this->fail('Purchase invoice update accepted a foreign order reference.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.order_id', $exception->errors());
        }
    }

    private function userId(): int
    {
        return (int) auth()->id();
    }
}
