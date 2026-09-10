<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\SalesInvoiceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoicePostingApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('accounting.enforce_sales_invoice_posting_policy', false);
        config()->set('accounting.enforce_sales_invoice_posting_approval', true);
        config()->set('accounting.enforce_sales_invoice_posting_dimensions', false);
        config()->set('accounting.enforce_sales_invoice_posting_account_mappings', false);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->company = Company::create(['name' => 'Direct sales posting tenant', 'tax_code' => 'SALES-DIRECT']);
        $this->admin = User::factory()->create(['company_id' => $this->company->id]);
        $this->admin->assignRole('admin');
        $this->configureAccountingTenant($this->admin, $this->company);

        foreach ([
            ['131', 'Phải thu', 'asset', 'debit'],
            ['5111', 'Doanh thu', 'revenue', 'credit'],
            ['33311', 'Thuế GTGT', 'liability', 'credit'],
        ] as [$code, $name, $type, $nature]) {
            ChartOfAccount::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'nature' => $nature, 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        }
    }

    public function test_admin_posts_sales_invoice_directly_and_audit_names_authorization_not_approval(): void
    {
        $invoice = $this->newInvoice('SALES-DIRECT-OK');
        $this->actingAs($this->admin);

        $posted = app(SalesInvoiceService::class)->post($invoice->id);

        $this->assertTrue($posted->is_posted);
        $audit = AuditLog::withoutGlobalScope('company')->where('action', 'sales_invoice.posting_authorization_applied')->where('model_id', $invoice->id)->sole();
        $this->assertSame('direct_two_role', $audit->metadata['authorization_model']);
        $this->assertSame('admin', $audit->metadata['authorization']['actor_role']);
        $this->assertSame('sales.invoices.post', $audit->metadata['authorization']['permission']);
        $this->assertDatabaseCount('approval_requests', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'sales_invoice.approval_applied']);
    }

    public function test_canonical_role_without_matching_permission_cannot_post(): void
    {
        $invoice = $this->newInvoice('SALES-MISSING-PERMISSION');
        Role::findByName('admin')->revokePermissionTo('sales.invoices.post');
        $this->actingAs($this->admin);

        try {
            app(SalesInvoiceService::class)->post($invoice->id);
            $this->fail('A canonical role without the document posting permission must not post.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('sales_invoices', ['id' => $invoice->id, 'is_posted' => false]);
        }
    }

    private function newInvoice(string $number)
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'code' => $number, 'name' => 'Khách hàng '.$number]);

        return app(SalesInvoiceService::class)->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'invoice_number' => $number,
            'invoice_date' => now()->toDateString(),
            'accounting_date' => now()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'lines' => [['description' => 'Hàng hóa ghi sổ trực tiếp', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '10', 'credit_account' => '5111', 'tax_account' => '33311']],
        ]);
    }
}
