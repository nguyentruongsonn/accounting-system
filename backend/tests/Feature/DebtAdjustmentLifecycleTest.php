<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\DebtAdjustment;
use App\Models\FiscalYear;
use App\Models\PurchaseDiscount;
use App\Models\SettlementAllocation;
use App\Models\User;
use App\Services\DebtAdjustmentService;
use App\Services\JournalEntryService;
use App\Services\SettlementAllocationReversalService;
use App\Services\SettlementAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DebtAdjustmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_ap_credit_note_posts_exact_journal_and_immutable_allocation_then_reverses(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($actor);
        FiscalYear::query()->where('company_id', $company->id)->update(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->accounts($company->id);
        $invoiceId = $this->purchaseInvoice($company->id, '100.00');

        $service = app(DebtAdjustmentService::class);
        $draft = $service->create($actor, [
            'ledger' => 'ap',
            'adjustment_kind' => 'credit_note',
            'voucher_number' => 'AP-ADJ-001',
            'voucher_date' => '2026-08-22',
            'accounting_date' => '2026-08-22',
            'reference_document_id' => $invoiceId,
            'amount' => '25.50',
            'debit_account' => '331',
            'credit_account' => '711',
            'description' => 'Credit note supplier approved',
        ]);
        $posted = $service->post($actor, $draft->id);

        $this->assertTrue($posted->is_posted);
        $this->assertSame('posted', $posted->status);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $posted->journal_entry_id,
            'company_id' => $company->id,
            'status' => 'posted',
            'source_document_type' => DebtAdjustment::class,
            'source_document_id' => $posted->id,
        ]);
        $originalAllocation = SettlementAllocation::query()
            ->where('source_document_type', 'ap_debt_adjustment')
            ->where('source_document_id', $posted->id)
            ->firstOrFail();
        $this->assertSame('reduction', $originalAllocation->allocation_direction);
        $this->assertSame('25.50', $originalAllocation->amount_raw);
        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $company->id,
            'action' => 'debt_adjustment.posted',
            'model_id' => $posted->id,
        ]);

        $reversal = $service->reverse($actor, $posted->id, 'AP-ADJ-001-R', '2026-08-23');
        $reversalAllocation = SettlementAllocation::query()
            ->where('source_document_type', 'ap_debt_adjustment')
            ->where('source_document_id', $reversal->id)
            ->firstOrFail();

        $this->assertTrue($reversal->is_posted);
        $this->assertSame($posted->id, $reversal->reversal_of_id);
        $this->assertSame('reversal', $reversalAllocation->allocation_direction);
        $this->assertSame($originalAllocation->id, $reversalAllocation->reverses_allocation_id);
        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $company->id,
            'action' => 'debt_adjustment.posted',
            'model_id' => $reversal->id,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $service->reverse($actor, $posted->id, 'AP-ADJ-001-R2', '2026-08-24');
    }

    public function test_api_requires_explicit_permission_and_ignores_client_company(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($actor);
        $this->accounts($company->id);
        $invoiceId = $this->purchaseInvoice($company->id, '100.00');
        $foreign = Company::query()->create(['name' => 'Foreign', 'tax_code' => 'DA-FOREIGN', 'address' => 'Foreign']);

        $actor->revokePermissionTo('debt-adjustments.create');
        $this->postJson('/api/v1/debt-adjustments', [])->assertForbidden();
        $actor->givePermissionTo('debt-adjustments.create');

        $response = $this->postJson('/api/v1/debt-adjustments', [
            'company_id' => $foreign->id,
            'ledger' => 'ap', 'adjustment_kind' => 'write_off',
            'voucher_number' => 'AP-ADJ-API', 'voucher_date' => '2026-08-22', 'accounting_date' => '2026-08-22',
            'reference_document_id' => $invoiceId, 'amount' => '10.00', 'debit_account' => '331', 'credit_account' => '711',
        ]);
        $response->assertCreated()->assertJsonPath('company_id', $company->id);
    }

    public function test_return_discount_allocation_reversal_uses_opposite_linked_journal(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($actor);
        FiscalYear::query()->where('company_id', $company->id)->update(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->accounts($company->id);
        ChartOfAccount::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'code' => '1561', 'name' => 'Hàng hóa',
            'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false, 'is_active' => true,
        ]);
        $invoiceId = $this->purchaseInvoice($company->id, '100.00');
        $discountId = (int) DB::table('purchase_discounts')->insertGetId([
            'company_id' => $company->id, 'voucher_number' => 'DA-PD-001', 'voucher_date' => '2026-08-22',
            'accounting_date' => '2026-08-22', 'total_amount' => '10.00', 'grand_total' => '10.00',
            'reference_invoice_id' => $invoiceId, 'is_posted' => true, 'is_decrease_debt' => true, 'status' => 'posted',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $journal = app(JournalEntryService::class)->createPosted([
            'company_id' => $company->id, 'voucher_type' => 'purchase_discount', 'voucher_number' => 'GL-DA-PD-001',
            'voucher_date' => '2026-08-22', 'posting_date' => '2026-08-22', 'description' => 'Posted discount',
            'source_document_type' => PurchaseDiscount::class, 'source_document_id' => $discountId,
            'lines' => [['debit_account' => '331', 'credit_account' => '1561', 'amount' => '10.00']],
        ]);
        DB::table('purchase_discounts')->where('id', $discountId)->update(['journal_entry_id' => $journal->id]);

        $original = app(SettlementAllocationService::class)->createPosted($actor, [
            'source_document_type' => 'purchase_discount', 'source_document_id' => $discountId,
            'target_document_type' => 'purchase_invoice', 'target_document_id' => $invoiceId,
            'allocation_kind' => 'discount', 'amount_raw' => '10.00', 'amount_scale' => 2,
            'effective_date' => '2026-08-22',
        ]);
        $reversal = app(SettlementAllocationReversalService::class)->reverse($actor, $original->id, 'Supplier rescinded discount', '2026-08-23');

        $this->assertSame('reversal', $reversal->allocation_direction);
        $this->assertSame($original->id, $reversal->reverses_allocation_id);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $reversal->source_document_id,
            'reversal_of_id' => $journal->id,
            'status' => 'posted',
        ]);
    }

    public function test_cash_settlement_reversal_creates_immutable_line_level_correction(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        FiscalYear::query()->where('company_id', $company->id)->update(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->accounts($company->id);
        $invoiceId = $this->purchaseInvoice($company->id, '100.00');
        $paymentId = (int) DB::table('cash_payments')->insertGetId([
            'company_id' => $company->id, 'voucher_number' => 'DA-CP-001', 'voucher_date' => '2026-08-22',
            'posting_date' => '2026-08-22', 'total_amount' => 100, 'status' => 'posted', 'is_posted' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $lineId = (int) DB::table('cash_payment_lines')->insertGetId([
            'cash_payment_id' => $paymentId, 'debit_account' => '331', 'credit_account' => '111', 'amount' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $original = app(SettlementAllocationService::class)->createPosted($actor, [
            'source_document_type' => 'cash_payment', 'source_document_id' => $paymentId,
            'source_line_type' => 'cash_payment_line', 'source_line_id' => $lineId,
            'target_document_type' => 'purchase_invoice', 'target_document_id' => $invoiceId,
            'allocation_kind' => 'settlement', 'amount_raw' => '100', 'amount_scale' => 0,
            'effective_date' => '2026-08-22',
        ]);

        $reversal = app(SettlementAllocationReversalService::class)->reverse($actor, $original->id, 'Nhập sai đối tượng công nợ', '2026-08-23');

        $this->assertSame('settlement_correction', $reversal->source_document_type);
        $this->assertSame('reversal', $reversal->allocation_direction);
        $this->assertSame($original->id, $reversal->reverses_allocation_id);
        $this->assertDatabaseHas('settlement_allocation_corrections', [
            'company_id' => $company->id, 'reverses_allocation_id' => $original->id,
            'source_line_type' => 'cash_payment_line', 'source_line_id' => $lineId,
            'amount_scale' => 0, 'debit_account' => '111', 'credit_account' => '331', 'status' => 'posted',
        ]);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'settlement_allocation_correction.posted']);

        $this->expectException(InvalidArgumentException::class);
        app(SettlementAllocationReversalService::class)->reverse($actor, $original->id, 'Đảo lần hai', '2026-08-24');
    }

    private function accounts(int $companyId): void
    {
        foreach ([
            ['331', 'Phải trả người bán', 'liability', 'credit'],
            ['111', 'Tiền mặt', 'asset', 'debit'],
            ['711', 'Thu nhập khác', 'revenue', 'credit'],
        ] as [$code, $name, $type, $nature]) {
            ChartOfAccount::withoutGlobalScopes()->create([
                'company_id' => $companyId, 'code' => $code, 'name' => $name,
                'type' => $type, 'nature' => $nature, 'level' => 1, 'is_parent' => false, 'is_active' => true,
            ]);
        }
    }

    private function purchaseInvoice(int $companyId, string $amount): int
    {
        $supplierId = DB::table('suppliers')->insertGetId([
            'company_id' => $companyId, 'code' => 'DA-SUP-'.uniqid(), 'name' => 'Debt adjustment supplier', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('purchase_invoices')->insertGetId([
            'company_id' => $companyId, 'supplier_id' => $supplierId, 'invoice_number' => 'DA-PI-'.uniqid(),
            'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'due_date' => '2026-08-31',
            'total_amount' => $amount, 'status' => 'posted', 'is_posted' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
