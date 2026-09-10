<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\APAgingService;
use App\Services\ApArSettlementStatusPolicy;
use App\Services\ARAgingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApArSettlementStatusPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_and_multiple_posted_allocations_produce_partially_paid_status_and_outstanding_balance(): void
    {
        $company = Company::query()->firstOrFail();
        $invoice = $this->purchaseInvoice($company->id, 1000, 'Partially Paid');
        $this->allocation($company->id, 'purchase_invoice', $invoice->id, '500', 'ap-one');
        $this->allocation($company->id, 'purchase_invoice', $invoice->id, '200', 'ap-two');

        $policy = app(ApArSettlementStatusPolicy::class);

        $this->assertSame('700.00', $policy->allocatedAmount($invoice));
        $this->assertSame('300.00', $policy->outstandingAmount($invoice));
        $this->assertSame('Partially Paid', $policy->statusFor($invoice));
    }

    public function test_voiding_one_of_two_settlements_uses_reversal_and_restores_only_that_balance(): void
    {
        $company = Company::query()->firstOrFail();
        $invoice = $this->salesInvoice($company->id, 1000, 'Paid');
        $first = $this->allocation($company->id, 'sales_invoice', $invoice->id, '600', 'ar-one');
        $this->allocation($company->id, 'sales_invoice', $invoice->id, '400', 'ar-two');
        $this->allocation($company->id, 'sales_invoice', $invoice->id, '600', 'ar-one-reversal', 'reversal', $first);

        $policy = app(ApArSettlementStatusPolicy::class);

        $this->assertSame('400.00', $policy->allocatedAmount($invoice));
        $this->assertSame('600.00', $policy->outstandingAmount($invoice));
        $this->assertSame('Partially Paid', $policy->statusFor($invoice));
    }

    public function test_aging_reports_use_allocated_balance_for_both_ap_and_ar(): void
    {
        $company = Company::query()->firstOrFail();
        $supplierId = DB::table('suppliers')->insertGetId(['company_id' => $company->id, 'code' => 'SUP-AGING', 'name' => 'Supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $customerId = DB::table('customers')->insertGetId(['company_id' => $company->id, 'code' => 'CUS-AGING', 'name' => 'Customer', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $purchase = PurchaseInvoice::query()->create(['company_id' => $company->id, 'supplier_id' => $supplierId, 'invoice_number' => 'PI-AGING', 'invoice_date' => '2026-08-01', 'due_date' => '2026-12-31', 'total_amount' => 1000, 'status' => 'Unpaid', 'is_posted' => true]);
        $sales = SalesInvoice::query()->create(['company_id' => $company->id, 'customer_id' => $customerId, 'invoice_number' => 'SI-AGING', 'invoice_date' => '2026-08-01', 'due_date' => '2026-12-31', 'total_amount' => 1000, 'status' => 'Unpaid', 'is_posted' => true]);
        $this->allocation($company->id, 'purchase_invoice', $purchase->id, '250', 'aging-ap');
        $this->allocation($company->id, 'sales_invoice', $sales->id, '400', 'aging-ar');

        $ap = collect(app(APAgingService::class)->generateReport($company->id))->first();
        $ar = collect(app(ARAgingService::class)->generateReport($company->id))->first();

        $this->assertSame('750.00', $ap['total_due']);
        $this->assertSame('600.00', $ar['total_due']);
    }

    public function test_aging_reports_exclude_posted_voided_and_cancelled_invoices(): void
    {
        $company = Company::query()->firstOrFail();
        $supplierId = DB::table('suppliers')->insertGetId([
            'company_id' => $company->id, 'code' => 'SUP-VOIDED', 'name' => 'Voided supplier', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'company_id' => $company->id, 'code' => 'CUS-CANCELLED', 'name' => 'Cancelled customer', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['voided', 'cancelled', 'canceled'] as $index => $status) {
            PurchaseInvoice::query()->create([
                'company_id' => $company->id, 'supplier_id' => $supplierId, 'invoice_number' => 'PI-'.$status,
                'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'due_date' => '2026-08-31',
                'total_amount' => 100 + $index, 'status' => $status, 'is_posted' => true,
            ]);
            SalesInvoice::query()->create([
                'company_id' => $company->id, 'customer_id' => $customerId, 'invoice_number' => 'SI-'.$status,
                'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'due_date' => '2026-08-31',
                'total_amount' => 100 + $index, 'status' => $status, 'is_posted' => true,
            ]);
        }

        $this->assertSame([], app(APAgingService::class)->generateReport($company->id, '2026-08-31'));
        $this->assertSame([], app(ARAgingService::class)->generateReport($company->id, '2026-08-31'));
    }

    public function test_reversed_fully_allocated_ap_and_ar_invoices_reopen_with_exact_decimal_balance(): void
    {
        $company = Company::query()->firstOrFail();
        $supplierId = DB::table('suppliers')->insertGetId(['company_id' => $company->id, 'code' => 'SUP-REVERSAL', 'name' => 'Supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $customerId = DB::table('customers')->insertGetId(['company_id' => $company->id, 'code' => 'CUS-REVERSAL', 'name' => 'Customer', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $purchase = PurchaseInvoice::query()->create(['company_id' => $company->id, 'supplier_id' => $supplierId, 'invoice_number' => 'PI-REVERSAL', 'invoice_date' => '2026-08-01', 'due_date' => '2026-12-31', 'total_amount' => '100.10', 'status' => 'Paid', 'is_posted' => true]);
        $sales = SalesInvoice::query()->create(['company_id' => $company->id, 'customer_id' => $customerId, 'invoice_number' => 'SI-REVERSAL', 'invoice_date' => '2026-08-01', 'due_date' => '2026-12-31', 'total_amount' => '100.10', 'status' => 'Paid', 'is_posted' => true]);

        $apPayment = $this->allocation($company->id, 'purchase_invoice', $purchase->id, '100.10', 'ap-full', 'reduction', null, 2);
        $arReceipt = $this->allocation($company->id, 'sales_invoice', $sales->id, '100.10', 'ar-full', 'reduction', null, 2);
        $this->allocation($company->id, 'purchase_invoice', $purchase->id, '0.10', 'ap-reversal', 'reversal', $apPayment, 2);
        $this->allocation($company->id, 'sales_invoice', $sales->id, '0.10', 'ar-reversal', 'reversal', $arReceipt, 2);

        $ap = collect(app(APAgingService::class)->generateReport($company->id))->first();
        $ar = collect(app(ARAgingService::class)->generateReport($company->id))->first();

        $this->assertSame('0.10', app(ApArSettlementStatusPolicy::class)->outstandingAmount($purchase));
        $this->assertSame('0.10', app(ApArSettlementStatusPolicy::class)->outstandingAmount($sales));
        $this->assertSame('0.10', $ap['total_due']);
        $this->assertSame('0.10', $ap['current']);
        $this->assertSame('0.10', $ar['total_due']);
        $this->assertSame('0.10', $ar['current']);
    }

    public function test_aging_cutoff_excludes_future_invoices_and_future_settlements(): void
    {
        $company = Company::query()->firstOrFail();
        $supplierId = DB::table('suppliers')->insertGetId(['company_id' => $company->id, 'code' => 'SUP-CUTOFF', 'name' => 'Supplier cutoff', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $customerId = DB::table('customers')->insertGetId(['company_id' => $company->id, 'code' => 'CUS-CUTOFF', 'name' => 'Customer cutoff', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $purchase = PurchaseInvoice::query()->create(['company_id' => $company->id, 'supplier_id' => $supplierId, 'invoice_number' => 'PI-CUTOFF', 'invoice_date' => '2026-08-20', 'due_date' => '2026-08-25', 'total_amount' => '1000.00', 'status' => 'Unpaid', 'is_posted' => true]);
        $sales = SalesInvoice::query()->create(['company_id' => $company->id, 'customer_id' => $customerId, 'invoice_number' => 'SI-CUTOFF', 'invoice_date' => '2026-08-20', 'due_date' => '2026-08-25', 'total_amount' => '1000.00', 'status' => 'Unpaid', 'is_posted' => true]);
        $futurePurchase = PurchaseInvoice::query()->create(['company_id' => $company->id, 'supplier_id' => $supplierId, 'invoice_number' => 'PI-FUTURE', 'invoice_date' => '2026-08-27', 'due_date' => '2026-09-05', 'total_amount' => '500.00', 'status' => 'Unpaid', 'is_posted' => true]);
        $futureSales = SalesInvoice::query()->create(['company_id' => $company->id, 'customer_id' => $customerId, 'invoice_number' => 'SI-FUTURE', 'invoice_date' => '2026-08-27', 'due_date' => '2026-09-05', 'total_amount' => '500.00', 'status' => 'Unpaid', 'is_posted' => true]);

        $this->allocation($company->id, 'purchase_invoice', $purchase->id, '600', 'ap-cutoff-future-settlement', 'reduction', null, 2, '2026-08-30');
        $this->allocation($company->id, 'sales_invoice', $sales->id, '600', 'ar-cutoff-future-settlement', 'reduction', null, 2, '2026-08-30');

        $ap = collect(app(APAgingService::class)->generateReport($company->id, '2026-08-26'))->first();
        $ar = collect(app(ARAgingService::class)->generateReport($company->id, '2026-08-26'))->first();

        $this->assertSame('1000.00', $ap['total_due']);
        $this->assertSame('1000.00', $ar['total_due']);
        $this->assertDatabaseHas('purchase_invoices', ['id' => $futurePurchase->id]);
        $this->assertDatabaseHas('sales_invoices', ['id' => $futureSales->id]);
    }

    private function allocation(int $companyId, string $targetType, int $targetId, string $amount, string $key, string $direction = 'reduction', ?int $reverses = null, int $scale = 0, string $effectiveDate = '2026-08-15'): int
    {
        return (int) DB::table('settlement_allocations')->insertGetId([
            'company_id' => $companyId, 'source_document_type' => 'cash_payment', 'source_document_id' => 9000 + $targetId,
            'source_line_type' => null, 'source_line_id' => null, 'source_reference_key' => $key,
            'target_document_type' => $targetType, 'target_document_id' => $targetId, 'allocation_kind' => 'settlement',
            'allocation_direction' => $direction, 'reverses_allocation_id' => $reverses, 'amount_raw' => $amount, 'amount_scale' => $scale,
            'currency_code' => 'VND', 'effective_date' => $effectiveDate, 'status' => 'posted', 'posted_at' => now(), 'created_by' => User::factory()->create(['company_id' => $companyId])->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function purchaseInvoice(int $companyId, int $amount, string $status): PurchaseInvoice
    {
        $supplierId = DB::table('suppliers')->insertGetId(['company_id' => $companyId, 'code' => 'SUP-'.uniqid(), 'name' => 'Supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return PurchaseInvoice::query()->create(['company_id' => $companyId, 'supplier_id' => $supplierId, 'invoice_number' => 'PI-'.uniqid(), 'invoice_date' => '2026-08-01', 'due_date' => '2026-08-31', 'total_amount' => $amount, 'status' => $status, 'is_posted' => true]);
    }

    private function salesInvoice(int $companyId, int $amount, string $status): SalesInvoice
    {
        $customerId = DB::table('customers')->insertGetId(['company_id' => $companyId, 'code' => 'CUS-'.uniqid(), 'name' => 'Customer', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return SalesInvoice::query()->create(['company_id' => $companyId, 'customer_id' => $customerId, 'invoice_number' => 'SI-'.uniqid(), 'invoice_date' => '2026-08-01', 'due_date' => '2026-08-31', 'total_amount' => $amount, 'status' => $status, 'is_posted' => true]);
    }
}
