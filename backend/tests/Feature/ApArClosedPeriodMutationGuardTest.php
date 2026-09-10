<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\User;
use App\Services\ApArFxRevaluationService;
use App\Services\DebtAdjustmentService;
use App\Services\SettlementAllocationCorrectionService;
use App\Services\SettlementAllocationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/** Source-service tests so jobs/internal callers cannot bypass the close guard. */
class ApArClosedPeriodMutationGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_closed_period_blocks_debt_fx_and_settlement_create_before_any_evidence_is_written(): void
    {
        $company = $this->company('APAR closed', 'APAR-CLOSED');
        $actor = User::factory()->create(['company_id' => $company->id]);
        $invoiceId = $this->purchaseInvoice($company->id, 'USD');
        $this->period($company, true);

        $this->assertClosed(fn () => app(DebtAdjustmentService::class)->create($actor, $this->debtPayload($invoiceId)));
        $this->assertClosed(fn () => app(ApArFxRevaluationService::class)->create($actor, $this->fxPayload($invoiceId)));
        $this->assertClosed(fn () => app(SettlementAllocationService::class)->createPosted($actor, [
            'source_document_type' => 'cash_payment', 'source_document_id' => 999999,
            'source_line_type' => 'cash_payment_line', 'source_line_id' => 999999,
            'target_document_type' => 'purchase_invoice', 'target_document_id' => $invoiceId,
            'allocation_kind' => 'settlement', 'amount_raw' => '1', 'amount_scale' => 0,
            'effective_date' => '2026-08-15',
        ]));

        $this->assertDatabaseCount('debt_adjustments', 0);
        $this->assertDatabaseCount('ap_ar_fx_revaluations', 0);
        $this->assertDatabaseCount('settlement_allocations', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_closed_period_blocks_post_and_correction_without_partial_journal_mutation(): void
    {
        $company = $this->company('APAR post closed', 'APAR-POST-CLOSED');
        $actor = User::factory()->create(['company_id' => $company->id]);
        $this->accounts($company->id);
        FiscalYear::withoutGlobalScope('company')->create([
            'company_id' => $company->id, 'year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open',
        ]);
        $invoiceId = $this->purchaseInvoice($company->id, 'USD');
        $debt = app(DebtAdjustmentService::class)->create($actor, $this->debtPayload($invoiceId));
        $fx = app(ApArFxRevaluationService::class)->create($actor, $this->fxPayload($invoiceId));
        $paymentId = (int) DB::table('cash_payments')->insertGetId([
            'company_id' => $company->id, 'voucher_number' => 'APAR-CP-1', 'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15', 'total_amount' => 100, 'status' => 'posted', 'is_posted' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $lineId = (int) DB::table('cash_payment_lines')->insertGetId([
            'cash_payment_id' => $paymentId, 'debit_account' => '331', 'credit_account' => '111', 'amount' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $allocation = app(SettlementAllocationService::class)->createPosted($actor, [
            'source_document_type' => 'cash_payment', 'source_document_id' => $paymentId,
            'source_line_type' => 'cash_payment_line', 'source_line_id' => $lineId,
            'target_document_type' => 'purchase_invoice', 'target_document_id' => $invoiceId,
            'allocation_kind' => 'settlement', 'amount_raw' => '100', 'amount_scale' => 0,
            'effective_date' => '2026-08-15',
        ]);
        $this->period($company, true);

        $this->assertClosed(fn () => app(DebtAdjustmentService::class)->post($actor, $debt->id));
        $this->assertClosed(fn () => app(ApArFxRevaluationService::class)->post($actor, $fx->id));
        $this->assertClosed(fn () => app(SettlementAllocationCorrectionService::class)->reverse($actor, $allocation->id, 'Correct source allocation', '2026-08-15'));

        $this->assertDatabaseHas('debt_adjustments', ['id' => $debt->id, 'status' => 'draft', 'is_posted' => false, 'journal_entry_id' => null]);
        $this->assertDatabaseHas('ap_ar_fx_revaluations', ['id' => $fx->id, 'status' => 'draft', 'is_posted' => false, 'journal_entry_id' => null]);
        $this->assertDatabaseCount('settlement_allocation_corrections', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('settlement_allocations', 1);
    }

    public function test_open_tenant_is_not_blocked_by_foreign_close_and_foreign_raw_ids_are_not_resolved(): void
    {
        $company = $this->company('APAR open', 'APAR-OPEN');
        $foreign = $this->company('APAR foreign', 'APAR-FOREIGN');
        $actor = User::factory()->create(['company_id' => $company->id]);
        $this->accounts($company->id);
        FiscalYear::withoutGlobalScope('company')->create([
            'company_id' => $company->id, 'year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open',
        ]);
        $invoiceId = $this->purchaseInvoice($company->id, 'USD');
        $foreignInvoiceId = $this->purchaseInvoice($foreign->id, 'USD');
        $this->period($foreign, true);

        $debt = app(DebtAdjustmentService::class)->create($actor, $this->debtPayload($invoiceId));
        $this->assertTrue(app(DebtAdjustmentService::class)->post($actor, $debt->id)->is_posted);
        $fx = app(ApArFxRevaluationService::class)->create($actor, $this->fxPayload($invoiceId));
        $this->assertTrue(app(ApArFxRevaluationService::class)->post($actor, $fx->id)->is_posted);

        try {
            app(DebtAdjustmentService::class)->create($actor, $this->debtPayload($foreignInvoiceId));
            $this->fail('Foreign source id must not resolve in actor tenant.');
        } catch (AuthorizationException) {
            // Tenant predicate is applied before findOrFail; no foreign facts are disclosed.
        }
        try {
            app(ApArFxRevaluationService::class)->create($actor, $this->fxPayload($foreignInvoiceId));
            $this->fail('Foreign source id must not resolve in actor tenant.');
        } catch (AuthorizationException) {
            // Tenant predicate is applied before findOrFail.
        }

        $this->assertDatabaseCount('debt_adjustments', 1);
        $this->assertDatabaseCount('ap_ar_fx_revaluations', 1);
    }

    public function test_closed_period_blocks_debt_and_fx_reversal_before_a_new_source_or_journal_exists(): void
    {
        $company = $this->company('APAR reversal closed', 'APAR-REV-CLOSED');
        $actor = User::factory()->create(['company_id' => $company->id]);
        $this->accounts($company->id);
        FiscalYear::withoutGlobalScope('company')->create([
            'company_id' => $company->id, 'year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open',
        ]);
        $invoiceId = $this->purchaseInvoice($company->id, 'USD');
        $debt = app(DebtAdjustmentService::class)->post($actor, app(DebtAdjustmentService::class)->create($actor, $this->debtPayload($invoiceId))->id);
        $fx = app(ApArFxRevaluationService::class)->post($actor, app(ApArFxRevaluationService::class)->create($actor, $this->fxPayload($invoiceId))->id);
        $this->period($company, true);

        $this->assertClosed(fn () => app(DebtAdjustmentService::class)->reverse($actor, $debt->id, 'APAR-ADJ-R', '2026-08-15'));
        $this->assertClosed(fn () => app(ApArFxRevaluationService::class)->reverse($actor, $fx->id, 'APAR-FX-R', '2026-08-15', 'Reverse FX'));

        $this->assertDatabaseCount('debt_adjustments', 1);
        $this->assertDatabaseCount('ap_ar_fx_revaluations', 1);
        $this->assertDatabaseCount('journal_entries', 2);
    }

    private function assertClosed(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a closed-period conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertStringContainsString('kỳ kế toán đã khóa', $exception->getMessage());
        }
    }

    private function company(string $name, string $taxCode): Company
    {
        return Company::create(['name' => $name, 'tax_code' => $taxCode]);
    }

    private function period(Company $company, bool $closed): Period
    {
        $fiscal = FiscalYear::withoutGlobalScope('company')->firstOrCreate(
            ['company_id' => $company->id, 'year' => 2026],
            ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open'],
        );
        return Period::create([
            'fiscal_year_id' => $fiscal->id, 'period' => 8, 'period_number' => 8, 'name' => 'August 2026',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => $closed ? 'closed' : 'open', 'is_closed' => $closed,
        ]);
    }

    private function accounts(int $companyId): void
    {
        foreach ([['331', 'Payable', 'liability', 'credit'], ['111', 'Cash', 'asset', 'debit'], ['711', 'Other income', 'revenue', 'credit'], ['515', 'FX income', 'revenue', 'credit'], ['635', 'FX expense', 'expense', 'debit']] as [$code, $name, $type, $nature]) {
            ChartOfAccount::withoutGlobalScopes()->create(['company_id' => $companyId, 'code' => $code, 'name' => $name, 'type' => $type, 'nature' => $nature, 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        }
    }

    private function purchaseInvoice(int $companyId, string $currency): int
    {
        $supplierId = DB::table('suppliers')->insertGetId(['company_id' => $companyId, 'code' => 'APAR-SUP-'.uniqid(), 'name' => 'Supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        return (int) DB::table('purchase_invoices')->insertGetId([
            'company_id' => $companyId, 'supplier_id' => $supplierId, 'invoice_number' => 'APAR-PI-'.uniqid(),
            'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'due_date' => '2026-08-31', 'currency' => $currency,
            'total_amount' => '100.00', 'status' => 'posted', 'is_posted' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function debtPayload(int $invoiceId): array
    {
        return ['ledger' => 'ap', 'adjustment_kind' => 'credit_note', 'voucher_number' => 'APAR-ADJ-'.uniqid(), 'voucher_date' => '2026-08-15', 'accounting_date' => '2026-08-15', 'reference_document_id' => $invoiceId, 'amount' => '10.00', 'debit_account' => '331', 'credit_account' => '711'];
    }

    private function fxPayload(int $invoiceId): array
    {
        return ['ledger' => 'ap', 'reference_document_id' => $invoiceId, 'voucher_number' => 'APAR-FX-'.uniqid(), 'voucher_date' => '2026-08-15', 'accounting_date' => '2026-08-15', 'original_currency' => 'USD', 'foreign_open_amount_raw' => '1', 'foreign_open_amount_scale' => 0, 'closing_exchange_rate_raw' => '26000', 'closing_exchange_rate_scale' => 0, 'carrying_functional_amount' => '25000.00', 'revalued_functional_amount' => '26000.00', 'adjustment_functional_amount' => '1000.00', 'debit_account' => '635', 'credit_account' => '331', 'reason' => 'FX closing'];
    }
}
