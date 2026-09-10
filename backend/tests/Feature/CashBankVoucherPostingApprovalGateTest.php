<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\User;
use App\Services\CashPaymentService;
use App\Services\CashReceiptService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashBankVoucherPostingApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $accountant;

    private User $admin;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('accounting.enforce_cash_bank_posting_policy', false);
        config()->set('accounting.enforce_cash_bank_posting_approval', true);
        config()->set('accounting.enforce_cash_bank_posting_account_mappings', false);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->company = Company::create(['name' => 'Direct cash posting tenant', 'tax_code' => 'CB-DIRECT']);
        $this->accountant = User::factory()->create(['company_id' => $this->company->id]);
        $this->accountant->assignRole('accountant');
        $this->admin = User::factory()->create(['company_id' => $this->company->id]);
        $this->admin->assignRole('admin');
        $this->configureAccountingTenant($this->accountant, $this->company);

        foreach ([
            ['1111', 'Cash', 'asset', 'debit'],
            ['131', 'Receivable', 'asset', 'debit'],
            ['331', 'Payable', 'liability', 'credit'],
        ] as [$code, $name, $type, $nature]) {
            ChartOfAccount::create([
                'company_id' => $this->company->id,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'nature' => $nature,
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }
    }

    public function test_active_canonical_accountant_and_admin_post_cash_documents_without_approval_requests(): void
    {
        $receipt = $this->newReceipt('CASH-DIRECT-RECEIPT');
        $this->actingAs($this->accountant);
        $this->assertTrue(app(CashReceiptService::class)->post($receipt->id)->is_posted);
        $this->assertDirectAuthorization($receipt, 'cash_receipt.posting_authorization_applied', $this->accountant, 'accountant', 'cash.receipts.post');

        $payment = $this->newPayment('CASH-DIRECT-PAYMENT');
        $this->actingAs($this->admin);
        $this->assertTrue(app(CashPaymentService::class)->post($payment->id)->is_posted);
        $this->assertDirectAuthorization($payment, 'cash_payment.posting_authorization_applied', $this->admin, 'admin', 'cash.payments.post');

        $this->assertDatabaseCount('approval_requests', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'cash_receipt.approval_applied']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'cash_payment.approval_applied']);
    }

    public function test_authorization_reloads_account_state_before_posting(): void
    {
        $payment = $this->newPayment('CASH-INACTIVE');
        $this->accountant->load('roles.permissions');
        $this->actingAs($this->accountant);
        DB::table('users')->where('id', $this->accountant->id)->update(['is_active' => false]);

        try {
            app(CashPaymentService::class)->post($payment->id);
            $this->fail('A cached principal must not post after its account is deactivated.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('cash_payments', ['id' => $payment->id, 'is_posted' => false]);
            $this->assertDatabaseCount('approval_requests', 0);
        }
    }

    private function assertDirectAuthorization(CashReceipt|CashPayment $document, string $action, User $actor, string $role, string $permission): void
    {
        $audit = AuditLog::withoutGlobalScope('company')->where('action', $action)->where('model_id', $document->id)->sole();

        $this->assertSame('direct_two_role', $audit->metadata['authorization_model']);
        $this->assertSame($actor->id, $audit->metadata['authorization']['actor_id']);
        $this->assertSame($role, $audit->metadata['authorization']['actor_role']);
        $this->assertSame($permission, $audit->metadata['authorization']['permission']);
        $this->assertTrue($audit->metadata['authorization']['account_active']);
    }

    private function newReceipt(string $number): CashReceipt
    {
        return app(CashReceiptService::class)->create([
            'company_id' => $this->company->id,
            'voucher_number' => $number,
            'voucher_date' => now()->toDateString(),
            'posting_date' => now()->toDateString(),
            'reason' => 'Direct cash receipt',
            'lines' => [['description' => 'Receipt', 'debit_account' => '1111', 'credit_account' => '131', 'amount' => 100]],
        ]);
    }

    private function newPayment(string $number): CashPayment
    {
        return app(CashPaymentService::class)->create([
            'company_id' => $this->company->id,
            'voucher_number' => $number,
            'voucher_date' => now()->toDateString(),
            'posting_date' => now()->toDateString(),
            'reason' => 'Direct cash payment',
            'lines' => [['description' => 'Payment', 'debit_account' => '331', 'credit_account' => '1111', 'amount' => 100]],
        ]);
    }
}
