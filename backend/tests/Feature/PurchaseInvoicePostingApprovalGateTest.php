<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseInvoiceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoicePostingApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $accountant;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('accounting.enforce_purchase_invoice_posting_policy', false);
        config()->set('accounting.enforce_purchase_invoice_posting_approval', true);
        config()->set('accounting.enforce_purchase_invoice_posting_dimensions', false);
        config()->set('accounting.enforce_purchase_invoice_posting_account_mappings', false);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->company = Company::create(['name' => 'Direct purchase posting tenant', 'tax_code' => 'PURCHASE-DIRECT']);
        $this->accountant = User::factory()->create(['company_id' => $this->company->id]);
        $this->accountant->assignRole('accountant');
        $this->configureAccountingTenant($this->accountant, $this->company);

        foreach ([
            ['1561', 'Hàng hóa', 'asset', 'debit'],
            ['331', 'Phải trả người bán', 'liability', 'credit'],
            ['1331', 'Thuế GTGT', 'asset', 'debit'],
        ] as [$code, $name, $type, $nature]) {
            ChartOfAccount::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'nature' => $nature, 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        }
    }

    public function test_accountant_posts_purchase_invoice_directly_and_audit_names_authorization_not_approval(): void
    {
        $invoice = $this->newInvoice('PURCHASE-DIRECT-OK');
        $this->actingAs($this->accountant);

        $posted = app(PurchaseInvoiceService::class)->post($invoice->id);

        $this->assertTrue($posted->is_posted);
        $audit = AuditLog::withoutGlobalScope('company')->where('action', 'purchase_invoice.posting_authorization_applied')->where('model_id', $invoice->id)->sole();
        $this->assertSame('direct_two_role', $audit->metadata['authorization_model']);
        $this->assertSame('accountant', $audit->metadata['authorization']['actor_role']);
        $this->assertSame('purchase.invoices.post', $audit->metadata['authorization']['permission']);
        $this->assertDatabaseCount('approval_requests', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'purchase_invoice.approval_applied']);
    }

    public function test_permission_grant_does_not_make_a_legacy_role_a_canonical_poster(): void
    {
        $invoice = $this->newInvoice('PURCHASE-LEGACY-ROLE');
        Role::findOrCreate('legacy-owner', 'web')->givePermissionTo('purchase.invoices.post');
        $this->accountant->syncRoles('legacy-owner');
        $this->actingAs($this->accountant);

        try {
            app(PurchaseInvoiceService::class)->post($invoice->id);
            $this->fail('A permission-bearing legacy role must not become a canonical posting identity.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('purchase_invoices', ['id' => $invoice->id, 'is_posted' => false]);
        }
    }

    private function newInvoice(string $number)
    {
        $supplier = Supplier::create(['company_id' => $this->company->id, 'code' => $number, 'name' => 'Nhà cung cấp '.$number]);

        return app(PurchaseInvoiceService::class)->create([
            'company_id' => $this->company->id,
            'supplier_id' => $supplier->id,
            'invoice_number' => $number,
            'invoice_date' => now()->toDateString(),
            'accounting_date' => now()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'lines' => [['description' => 'Hàng hóa ghi sổ trực tiếp', 'debit_account' => '1561', 'credit_account' => '331', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '10', 'tax_account' => '1331']],
        ]);
    }
}
