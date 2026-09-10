<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\SalesDiscount;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SalesManagementReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_returns_only_posted_tenant_activity_with_signed_decimal_totals(): void
    {
        [$company, $user, $customer] = $this->authenticatedTenant('sales.reports.view');
        $otherCompany = Company::create(['name' => 'Other sales report tenant']);
        $otherCustomer = Customer::create(['company_id' => $otherCompany->id, 'code' => 'OTHER', 'name' => 'Other customer']);

        SalesInvoice::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'invoice_number' => 'BH-POSTED-001',
            'invoice_date' => '2026-08-10',
            'accounting_date' => '2026-08-10',
            'sub_total' => '100.00',
            'discount_amount' => '0.00',
            'tax_amount' => '10.00',
            'total_amount' => '110.00',
            'status' => 'posted',
            'is_posted' => true,
        ]);
        SalesInvoice::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'invoice_number' => 'BH-DRAFT-001',
            'invoice_date' => '2026-08-12',
            'accounting_date' => '2026-08-12',
            'sub_total' => '999.00',
            'tax_amount' => '0.00',
            'total_amount' => '999.00',
            'status' => 'draft',
            'is_posted' => false,
        ]);
        SalesReturn::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'voucher_number' => 'TH-POSTED-001',
            'voucher_date' => '2026-08-20',
            'accounting_date' => '2026-08-20',
            'sub_total' => '20.00',
            'discount_amount' => '0.00',
            'tax_amount' => '2.00',
            'total_amount' => '22.00',
            'grand_total' => '22.00',
            'status' => 'posted',
            'is_posted' => true,
        ]);
        SalesDiscount::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'voucher_number' => 'GG-POSTED-001',
            'voucher_date' => '2026-08-25',
            'accounting_date' => '2026-08-25',
            'sub_total' => '5.00',
            'tax_amount' => '0.00',
            'total_amount' => '5.00',
            'grand_total' => '5.00',
            'status' => 'posted',
            'is_posted' => true,
        ]);
        SalesInvoice::create([
            'company_id' => $otherCompany->id,
            'customer_id' => $otherCustomer->id,
            'customer_name' => $otherCustomer->name,
            'invoice_number' => 'OTHER-POSTED-001',
            'invoice_date' => '2026-08-15',
            'accounting_date' => '2026-08-15',
            'sub_total' => '700.00',
            'tax_amount' => '0.00',
            'total_amount' => '700.00',
            'status' => 'posted',
            'is_posted' => true,
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/sales/reports?'.http_build_query([
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
        ]));

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.date_basis', 'accounting_date')
            ->assertJsonPath('totals.sub_total', '75.00')
            ->assertJsonPath('totals.tax_amount', '8.00')
            ->assertJsonPath('totals.total_amount', '83.00')
            ->assertJsonPath('data.0.source_type', 'sales_discount')
            ->assertJsonPath('data.0.signed_total_amount', '-5.00');

        $this->assertStringNotContainsString('BH-DRAFT-001', $response->getContent());
        $this->assertStringNotContainsString('OTHER-POSTED-001', $response->getContent());
    }

    public function test_report_filters_by_customer_and_rejects_an_inverted_date_range(): void
    {
        [$company, $user, $customer] = $this->authenticatedTenant('sales.reports.view');
        $secondCustomer = Customer::create(['company_id' => $company->id, 'code' => 'C002', 'name' => 'Second customer']);

        foreach ([[$customer, 'CUS-001'], [$secondCustomer, 'CUS-002']] as [$rowCustomer, $number]) {
            SalesInvoice::create([
                'company_id' => $company->id,
                'customer_id' => $rowCustomer->id,
                'customer_name' => $rowCustomer->name,
                'invoice_number' => $number,
                'invoice_date' => '2026-08-10',
                'accounting_date' => '2026-08-10',
                'sub_total' => '10.00',
                'tax_amount' => '0.00',
                'total_amount' => '10.00',
                'status' => 'posted',
                'is_posted' => true,
            ]);
        }

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/sales/reports?'.http_build_query([
            'customer_id' => $customer->id,
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-31',
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer_id', $customer->id);

        $this->getJson('/api/v1/sales/reports?from_date=2026-09-01&to_date=2026-08-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from_date', 'to_date']);
    }

    /** @return array{Company, User, Customer} */
    private function authenticatedTenant(string $permission): array
    {
        $company = Company::create(['name' => 'Sales report tenant']);
        $user = User::factory()->create(['company_id' => $company->id]);
        // The API's active-account boundary requires one canonical role.
        // Keep this report fixture aligned with the two-role production
        // contract instead of relying only on the legacy permission callback.
        $user->assignRole(Role::findOrCreate('accountant', 'web'));
        $customer = Customer::create(['company_id' => $company->id, 'code' => 'C001', 'name' => 'Primary customer']);
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->givePermissionTo($permission);

        return [$company, $user, $customer];
    }
}
