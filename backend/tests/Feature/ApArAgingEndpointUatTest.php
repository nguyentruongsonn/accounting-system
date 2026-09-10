<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApArAgingEndpointUatTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_can_read_ap_and_ar_aging_endpoints_with_allocated_balances(): void
    {
        $company = Company::create(['name' => 'AP AR endpoint UAT']);
        $actor = User::factory()->create(['company_id' => $company->id]);
        // Aging endpoints are behind active_account and therefore require a
        // canonical production role in addition to report permissions.
        $actor->assignRole(Role::findOrCreate('accountant', 'web'));
        $actor->givePermissionTo([
            Permission::findOrCreate('purchase.reports.view', 'web'),
            Permission::findOrCreate('sales.reports.view', 'web'),
        ]);
        Sanctum::actingAs($actor);

        $supplierId = DB::table('suppliers')->insertGetId([
            'company_id' => $company->id,
            'code' => 'SUP-ENDPOINT',
            'name' => 'Nhà cung cấp UAT',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'company_id' => $company->id,
            'code' => 'CUS-ENDPOINT',
            'name' => 'Khách hàng UAT',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchase = PurchaseInvoice::query()->create([
            'company_id' => $company->id,
            'supplier_id' => $supplierId,
            'invoice_number' => 'PI-ENDPOINT-001',
            'invoice_date' => '2026-08-01',
            'accounting_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '1000.00',
            'status' => 'Unpaid',
            'is_posted' => true,
        ]);
        $sales = SalesInvoice::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customerId,
            'invoice_number' => 'SI-ENDPOINT-001',
            'invoice_date' => '2026-08-01',
            'accounting_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '1000.00',
            'status' => 'Unpaid',
            'is_posted' => true,
        ]);

        $this->insertAllocation($company->id, 'purchase_invoice', $purchase->id, '250.00', 'endpoint-ap', $actor->id);
        $this->insertAllocation($company->id, 'sales_invoice', $sales->id, '400.00', 'endpoint-ar', $actor->id);

        $this->getJson('/api/v1/purchase/ap-aging?as_of_date=2026-08-31')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.supplier_code', 'SUP-ENDPOINT')
            ->assertJsonPath('0.total_due', '750.00')
            ->assertJsonPath('0.current', '750.00');

        $this->getJson('/api/v1/sales/ar-aging?as_of_date=2026-08-31')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.customer_code', 'CUS-ENDPOINT')
            ->assertJsonPath('0.total_due', '600.00')
            ->assertJsonPath('0.current', '600.00');
    }

    private function insertAllocation(int $companyId, string $targetType, int $targetId, string $amount, string $key, int $actorId): void
    {
        DB::table('settlement_allocations')->insert([
            'company_id' => $companyId,
            'source_document_type' => 'cash_payment',
            'source_document_id' => 9000 + $targetId,
            'source_reference_key' => $key,
            'target_document_type' => $targetType,
            'target_document_id' => $targetId,
            'allocation_kind' => 'settlement',
            'allocation_direction' => 'reduction',
            'amount_raw' => $amount,
            'amount_scale' => 2,
            'currency_code' => 'VND',
            'functional_currency_code' => 'VND',
            'effective_date' => '2026-08-15',
            'status' => 'posted',
            'posted_at' => now(),
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
