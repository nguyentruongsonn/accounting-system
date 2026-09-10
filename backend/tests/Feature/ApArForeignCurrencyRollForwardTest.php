<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\ApArForeignCurrencyRollForwardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApArForeignCurrencyRollForwardTest extends TestCase
{
    use RefreshDatabase;

    public function test_ar_rolls_original_and_functional_open_balance_through_settlement_and_fx_at_cutoff(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $customer = DB::table('customers')->insertGetId(['company_id' => $company->id, 'code' => 'FX-RF-CUS', 'name' => 'FX RF Customer', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $invoiceId = DB::table('sales_invoices')->insertGetId(['company_id' => $company->id, 'customer_id' => $customer, 'invoice_number' => 'FX-RF-SI', 'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'currency' => 'USD', 'functional_currency_code' => 'VND', 'functional_total_amount_raw' => '250000', 'functional_total_amount_scale' => 0, 'original_total_amount_raw' => '10', 'original_total_amount_scale' => 0, 'total_amount' => '250000.00', 'status' => 'posted', 'is_posted' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('settlement_allocations')->insert(['company_id' => $company->id, 'source_document_type' => 'cash_receipt', 'source_document_id' => 9001, 'source_line_type' => 'cash_receipt_line', 'source_line_id' => 9002, 'source_reference_key' => 'fx-rf-source', 'target_document_type' => 'sales_invoice', 'target_document_id' => $invoiceId, 'allocation_kind' => 'settlement', 'allocation_direction' => 'reduction', 'amount_raw' => '100000', 'amount_scale' => 0, 'currency_code' => 'VND', 'functional_currency_code' => 'VND', 'functional_amount_raw' => '100000', 'functional_amount_scale' => 0, 'original_currency_code' => 'USD', 'original_amount_raw' => '4', 'original_amount_scale' => 0, 'effective_date' => '2026-08-15', 'status' => 'posted', 'posted_at' => now(), 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ap_ar_fx_revaluations')->insert(['company_id' => $company->id, 'ledger' => 'ar', 'reference_document_type' => 'sales_invoice', 'reference_document_id' => $invoiceId, 'voucher_number' => 'FX-RF-001', 'voucher_date' => '2026-08-31', 'accounting_date' => '2026-08-31', 'original_currency' => 'USD', 'foreign_open_amount_raw' => '6', 'foreign_open_amount_scale' => 0, 'closing_exchange_rate_raw' => '25666.6667', 'closing_exchange_rate_scale' => 4, 'carrying_functional_amount' => '150000.00', 'revalued_functional_amount' => '154000.00', 'adjustment_functional_amount' => '4000.00', 'effect' => 'gain', 'debit_account' => '131', 'credit_account' => '515', 'reason' => 'FX', 'status' => 'posted', 'is_posted' => true, 'created_by' => $actor->id, 'posted_by' => $actor->id, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $row = app(ApArForeignCurrencyRollForwardService::class)->forInvoice('ar', $company->id, $invoiceId, '2026-08-31');
        $this->assertSame('USD', $row['original_currency']);
        $this->assertSame('6.00', $row['original_open_amount']);
        $this->assertSame('154000.00', $row['functional_open_amount']);
        $this->assertSame('4000.00', $row['fx_adjustment_amount']);
    }

    public function test_direct_roll_forward_rejects_foreign_authenticated_company(): void
    {
        $company = Company::query()->firstOrFail();
        $foreignCompany = Company::query()->create([
            'name' => 'FX Roll-forward Foreign Company',
            'tax_code' => 'FX-RF-FOREIGN',
        ]);
        $actor = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($actor);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ApArForeignCurrencyRollForwardService::class)->forInvoice('ar', $foreignCompany->id, 1, '2026-08-31');
    }

    public function test_direct_roll_forward_rejects_unknown_ledger(): void
    {
        $company = Company::query()->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);
        app(ApArForeignCurrencyRollForwardService::class)->forInvoice('gl', $company->id, 1, '2026-08-31');
    }
}
