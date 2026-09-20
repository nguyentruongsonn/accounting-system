<?php

namespace Tests\Feature\Purchase;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Period;
use App\Services\PurchaseInvoicePostingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PurchaseInvoicePostingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_value_purchase_can_be_posted_through_extracted_posting_service(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Purchase posting company']);
        $supplier = Supplier::create([
            'company_id' => $company->id,
            'code' => 'SUP-POSTING',
            'name' => 'Purchase posting supplier',
            'is_active' => true,
        ]);
        $this->openPeriod($company);

        $user = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $user->assignRole('accountant');
        Sanctum::actingAs($user, [], 'sanctum');

        $invoice = PurchaseInvoice::create([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'invoice_number' => 'HDMH-POST-0001',
            'invoice_date' => '2026-01-15',
            'accounting_date' => '2026-01-15',
            'sub_total' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'status' => 'draft',
            'is_posted' => false,
            'currency' => 'VND',
        ]);

        $posted = app(PurchaseInvoicePostingService::class)->post($invoice->id);

        $this->assertTrue($posted->is_posted);
        $this->assertSame('posted', $posted->status);
        $this->assertNull($posted->journal_entry_id);
    }

    private function openPeriod(Company $company): void
    {
        $fiscalYear = FiscalYear::create([
            'company_id' => $company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        Period::create([
            'fiscal_year_id' => $fiscalYear->id,
            'period' => 1,
            'period_number' => 1,
            'name' => 'Tháng 01/2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'status' => 'open',
            'is_closed' => false,
        ]);
    }
}
