<?php

namespace Tests\Feature;

use App\Models\ApArFxRevaluation;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\ApArFxRevaluationService;
use App\Services\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApArFxRevaluationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_ar_fx_revaluation_posts_exact_journal_and_reverses_once(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        FiscalYear::query()->where('company_id', $company->id)->update(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->accounts($company->id);
        $invoiceId = $this->salesInvoice($company->id, 'USD');
        $service = app(ApArFxRevaluationService::class);

        $draft = $service->create($actor, $this->payload($invoiceId));
        $posted = $service->post($actor, $draft->id);

        $this->assertTrue($posted->is_posted);
        $this->assertSame('gain', $posted->effect);
        $this->assertDatabaseHas('journal_entries', ['id' => $posted->journal_entry_id, 'status' => 'posted', 'source_document_type' => ApArFxRevaluation::class]);
        $reversal = $service->reverse($actor, $posted->id, 'FX-AR-001-R', '2026-08-31', 'Đảo đánh giá lại');
        $this->assertTrue($reversal->is_posted);
        $this->assertSame($posted->id, $reversal->reversal_of_id);
        $this->assertSame('loss', $reversal->effect);
        $this->assertSame('515', $reversal->debit_account);
        $this->assertSame('131', $reversal->credit_account);

        $this->expectException(InvalidArgumentException::class);
        $service->reverse($actor, $posted->id, 'FX-AR-001-R2', '2026-09-01', 'Đảo lần hai');
    }

    public function test_fx_currency_must_match_the_referenced_open_item(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $this->accounts($company->id);

        $this->expectException(ValidationException::class);
        app(ApArFxRevaluationService::class)->create($actor, $this->payload($this->salesInvoice($company->id, 'USD'), 'EUR'));
    }

    public function test_foreign_invoice_cannot_post_without_exact_dual_currency_evidence(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $actor->assignRole(Role::findOrCreate('accountant', 'web'));
        Sanctum::actingAs($actor);
        $invoiceId = $this->salesInvoice($company->id, 'USD', false);

        $this->expectException(\LogicException::class);
        app(SalesInvoiceService::class)->post($invoiceId);
    }

    public function test_ap_increase_is_loss_and_uses_payable_control_credit(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        foreach ([['331', 'Phải trả người bán', 'liability', 'credit'], ['635', 'Chi phí tài chính', 'expense', 'debit']] as [$code, $name, $type, $nature]) {
            ChartOfAccount::withoutGlobalScopes()->create(['company_id' => $company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'nature' => $nature, 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        }
        $supplierId = DB::table('suppliers')->insertGetId(['company_id' => $company->id, 'code' => 'FX-SUP-'.uniqid(), 'name' => 'FX Supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $invoiceId = (int) DB::table('purchase_invoices')->insertGetId(['company_id' => $company->id, 'supplier_id' => $supplierId, 'invoice_number' => 'FX-PI-'.uniqid(), 'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'currency' => 'USD', 'total_amount' => '250000.00', 'status' => 'draft', 'is_posted' => false, 'created_at' => now(), 'updated_at' => now()]);
        $row = app(ApArFxRevaluationService::class)->create($actor, [...$this->payload($invoiceId), 'ledger' => 'ap', 'debit_account' => '635', 'credit_account' => '331']);
        $this->assertSame('loss', $row->effect);
    }

    private function payload(int $invoiceId, string $currency = 'USD'): array
    {
        return ['ledger' => 'ar', 'reference_document_id' => $invoiceId, 'voucher_number' => 'FX-AR-001', 'voucher_date' => '2026-08-31', 'accounting_date' => '2026-08-31', 'original_currency' => $currency, 'foreign_open_amount_raw' => '10', 'foreign_open_amount_scale' => 0, 'closing_exchange_rate_raw' => '26000', 'closing_exchange_rate_scale' => 0, 'carrying_functional_amount' => '250000.00', 'revalued_functional_amount' => '260000.00', 'adjustment_functional_amount' => '10000.00', 'debit_account' => '131', 'credit_account' => '515', 'reason' => 'Đánh giá lại công nợ cuối kỳ'];
    }

    private function accounts(int $companyId): void
    {
        foreach ([['131', 'Phải thu khách hàng', 'asset', 'debit'], ['515', 'Doanh thu hoạt động tài chính', 'revenue', 'credit']] as [$code, $name, $type, $nature]) {
            ChartOfAccount::withoutGlobalScopes()->create(['company_id' => $companyId, 'code' => $code, 'name' => $name, 'type' => $type, 'nature' => $nature, 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        }
    }

    private function salesInvoice(int $companyId, string $currency, bool $posted = true): int
    {
        $customerId = DB::table('customers')->insertGetId(['company_id' => $companyId, 'code' => 'FX-CUS-'.uniqid(), 'name' => 'FX Customer', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        return (int) DB::table('sales_invoices')->insertGetId(['company_id' => $companyId, 'customer_id' => $customerId, 'invoice_number' => 'FX-SI-'.uniqid(), 'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'due_date' => '2026-08-31', 'currency' => $currency, 'exchange_rate' => '25000.00', 'total_amount' => '250000.00', 'status' => $posted ? 'posted' : 'draft', 'is_posted' => $posted, 'created_at' => now(), 'updated_at' => now()]);
    }
}
