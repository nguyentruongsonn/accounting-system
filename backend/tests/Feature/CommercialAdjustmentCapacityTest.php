<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\PurchaseDiscount;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CommercialAdjustmentCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommercialAdjustmentCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_return_cannot_exceed_posted_invoice_quantity(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($actor);
        $supplier = Supplier::create(['company_id' => $company->id, 'code' => 'CAP-SUP', 'name' => 'Capacity supplier', 'is_active' => true]);
        $item = Item::create(['company_id' => $company->id, 'code' => 'CAP-ITEM', 'name' => 'Capacity item', 'unit' => 'Cái', 'is_active' => true]);
        $invoice = PurchaseInvoice::withoutGlobalScope('company')->create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'CAP-PI-1',
            'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'total_amount' => '500.00',
            'status' => 'posted', 'is_posted' => true,
        ]);
        PurchaseInvoiceLine::create(['purchase_invoice_id' => $invoice->id, 'item_id' => $item->id, 'description' => 'Capacity item', 'debit_account' => '1561', 'credit_account' => '331', 'quantity' => '5.00', 'unit_price' => '100.00', 'amount' => '500.00']);
        $existing = PurchaseReturn::withoutGlobalScope('company')->create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'voucher_number' => 'CAP-PR-EXISTING',
            'voucher_date' => '2026-08-10', 'accounting_date' => '2026-08-10', 'total_amount' => '300.00',
            'grand_total' => '300.00', 'reference_invoice_id' => $invoice->id, 'is_posted' => true, 'is_outward' => true, 'is_export_slip' => true,
            'is_decrease_debt' => true, 'status' => 'posted',
        ]);
        PurchaseReturnLine::create(['purchase_return_id' => $existing->id, 'item_id' => $item->id, 'quantity' => '3.00', 'unit_price' => '100.00', 'amount' => '300.00']);
        $current = PurchaseReturn::withoutGlobalScope('company')->create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'voucher_number' => 'CAP-PR-CURRENT',
            'voucher_date' => '2026-08-11', 'accounting_date' => '2026-08-11', 'total_amount' => '300.00',
            'grand_total' => '300.00', 'reference_invoice_id' => $invoice->id, 'is_posted' => false, 'is_outward' => true, 'is_export_slip' => true,
            'is_decrease_debt' => true, 'status' => 'draft',
        ]);
        PurchaseReturnLine::create(['purchase_return_id' => $current->id, 'item_id' => $item->id, 'quantity' => '3.00', 'unit_price' => '100.00', 'amount' => '300.00']);

        try {
            app(CommercialAdjustmentCapacityService::class)->assertPurchaseReturn($current->fresh(['lines']));
            $this->fail('The return must not exceed the invoice quantity after prior posted returns.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.quantity', $exception->errors());
            $this->assertStringContainsString('còn được trả', $exception->errors()['lines.0.quantity'][0]);
        }
    }

    public function test_sales_return_cannot_reference_unposted_invoice_or_wrong_customer(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($actor);
        $customer = Customer::create(['company_id' => $company->id, 'code' => 'CAP-CUS', 'name' => 'Capacity customer', 'is_active' => true]);
        $otherCustomer = Customer::create(['company_id' => $company->id, 'code' => 'CAP-CUS-2', 'name' => 'Other customer', 'is_active' => true]);
        $item = Item::create(['company_id' => $company->id, 'code' => 'CAP-SALES-ITEM', 'name' => 'Sales capacity item', 'unit' => 'Cái', 'is_active' => true]);
        $invoice = SalesInvoice::withoutGlobalScope('company')->create([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'invoice_number' => 'CAP-SI-1',
            'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'total_amount' => '500.00',
            'status' => 'draft', 'is_posted' => false,
        ]);
        SalesInvoiceLine::create(['sales_invoice_id' => $invoice->id, 'item_id' => $item->id, 'description' => 'Sales capacity item', 'debit_account' => '131', 'credit_account' => '5111', 'quantity' => '5.00', 'unit_price' => '100.00', 'amount' => '500.00']);
        $return = SalesReturn::withoutGlobalScope('company')->create([
            'company_id' => $company->id, 'customer_id' => $otherCustomer->id, 'voucher_number' => 'CAP-SR-1',
            'voucher_date' => '2026-08-11', 'accounting_date' => '2026-08-11', 'total_amount' => '100.00',
            'grand_total' => '100.00', 'reference_invoice_id' => $invoice->id, 'is_posted' => false, 'is_inward' => true, 'is_import_slip' => true,
            'is_decrease_debt' => true, 'status' => 'draft',
        ]);
        SalesReturnLine::create(['sales_return_id' => $return->id, 'item_id' => $item->id, 'quantity' => '1.00', 'unit_price' => '100.00', 'amount' => '100.00']);

        try {
            app(CommercialAdjustmentCapacityService::class)->assertSalesReturn($return->fresh(['lines']));
            $this->fail('A return must not reference an unposted invoice or another customer.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reference_invoice_id', $exception->errors());
        }
    }

    public function test_debt_reduction_discount_cannot_exceed_remaining_invoice_value(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($actor);
        $supplier = Supplier::create(['company_id' => $company->id, 'code' => 'CAP-DISC-SUP', 'name' => 'Discount supplier', 'is_active' => true]);
        $invoice = PurchaseInvoice::withoutGlobalScope('company')->create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'CAP-DISC-PI',
            'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'total_amount' => '100.00',
            'status' => 'posted', 'is_posted' => true,
        ]);
        PurchaseDiscount::withoutGlobalScope('company')->create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'voucher_number' => 'CAP-DISC-OLD',
            'voucher_date' => '2026-08-10', 'accounting_date' => '2026-08-10', 'total_amount' => '70.00',
            'grand_total' => '70.00', 'reference_invoice_id' => $invoice->id, 'is_posted' => true,
            'is_decrease_debt' => true, 'status' => 'posted',
        ]);
        $current = PurchaseDiscount::withoutGlobalScope('company')->create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'voucher_number' => 'CAP-DISC-CURRENT',
            'voucher_date' => '2026-08-11', 'accounting_date' => '2026-08-11', 'total_amount' => '40.00',
            'grand_total' => '40.00', 'reference_invoice_id' => $invoice->id, 'is_posted' => false,
            'is_decrease_debt' => true, 'status' => 'draft',
        ]);

        $this->expectException(ValidationException::class);
        try {
            app(CommercialAdjustmentCapacityService::class)->assertPurchaseDiscount($current);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('total_amount', $exception->errors());
            throw $exception;
        }
    }
}
