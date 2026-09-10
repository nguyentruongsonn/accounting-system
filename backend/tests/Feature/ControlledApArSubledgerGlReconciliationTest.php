<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\ApArSubledgerGlReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ControlledApArSubledgerGlReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_policy_computes_ap_invoice_settlement_and_gl_tie_out(): void
    {
        [$company, $actor] = $this->tenant();
        $supplierId = (int) DB::table('suppliers')->insertGetId([
            'company_id' => $company->id, 'code' => 'CTRL-SUP', 'name' => 'Controlled supplier',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoiceId = (int) DB::table('purchase_invoices')->insertGetId([
            'company_id' => $company->id, 'supplier_id' => $supplierId, 'invoice_number' => 'CTRL-AP-001',
            'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'due_date' => '2026-08-31',
            'currency' => 'VND', 'functional_currency_code' => 'VND', 'functional_total_amount_raw' => '100000',
            'functional_total_amount_scale' => 0, 'original_total_amount_raw' => '100000', 'original_total_amount_scale' => 0,
            'total_amount' => '100000.00', 'status' => 'posted', 'is_posted' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoiceJournal = $this->journal($company->id, $actor->id, '2026-08-01', 'PurchaseInvoice', $invoiceId, [
            ['account_code' => '156', 'debit_amount' => '100000.00', 'credit_amount' => '0.00'],
            ['account_code' => '331', 'debit_amount' => '0.00', 'credit_amount' => '100000.00'],
        ]);
        DB::table('purchase_invoices')->where('id', $invoiceId)->update(['journal_entry_id' => $invoiceJournal]);

        $paymentJournal = $this->journal($company->id, $actor->id, '2026-08-15', 'CashPayment', 9001, [
            ['account_code' => '331', 'debit_amount' => '40000.00', 'credit_amount' => '0.00'],
            ['account_code' => '111', 'debit_amount' => '0.00', 'credit_amount' => '40000.00'],
        ]);
        DB::table('settlement_allocations')->insert([
            'company_id' => $company->id, 'source_document_type' => 'cash_payment', 'source_document_id' => 9001,
            'source_line_type' => 'cash_payment_line', 'source_line_id' => 9002, 'source_reference_key' => 'ctrl-ap-001',
            'target_document_type' => 'purchase_invoice', 'target_document_id' => $invoiceId,
            'allocation_kind' => 'settlement', 'allocation_direction' => 'reduction', 'amount_raw' => '40000', 'amount_scale' => 0,
            'currency_code' => 'VND', 'functional_currency_code' => 'VND', 'functional_amount_raw' => '40000',
            'functional_amount_scale' => 0, 'original_currency_code' => 'VND', 'original_amount_raw' => '40000',
            'original_amount_scale' => 0, 'effective_date' => '2026-08-15', 'status' => 'posted', 'posted_at' => now(),
            'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertNotSame(0, $paymentJournal);

        $this->approvePolicy($actor, $company->id, 'ap', ['331'], 'credit');

        $run = app(ApArSubledgerGlReconciliationService::class)->capture($actor, 'ap', '2026-08-31');

        $this->assertSame('approved', $run->status);
        $this->assertSame(0, $run->divergence_count);
        $this->assertSame('reconciled', $run->snapshot['status']);
        $this->assertTrue($run->snapshot['amounts_calculated']);
        $this->assertTrue($run->snapshot['tie_out_calculated']);
        $this->assertTrue($run->snapshot['close_authority']);
        $this->assertSame('60000.00', $run->snapshot['amounts']['subledger_balance']);
        $this->assertSame('60000.00', $run->snapshot['amounts']['gl_control_balance']);
        $this->assertSame('0.00', $run->snapshot['amounts']['difference']);
        $this->assertSame(1, $run->snapshot['amounts']['invoice_count']);
        $this->assertSame(1, $run->snapshot['amounts']['settlement_count']);
        $this->assertSame([
            'party_type' => 'supplier',
            'party_id' => $supplierId,
            'opening_balance' => '0.00',
            'invoice_balance' => '100000.00',
            'settlement_reduction' => '40000.00',
            'settlement_reversal' => '0.00',
            'ending_balance' => '60000.00',
        ], $run->snapshot['amounts']['party_rollforward'][0]);
    }

    public function test_controlled_capture_is_blocked_when_gl_does_not_tie_out(): void
    {
        [$company, $actor] = $this->tenant();
        $supplierId = (int) DB::table('suppliers')->insertGetId([
            'company_id' => $company->id, 'code' => 'CTRL-SUP-2', 'name' => 'Controlled supplier 2',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoiceId = (int) DB::table('purchase_invoices')->insertGetId([
            'company_id' => $company->id, 'supplier_id' => $supplierId, 'invoice_number' => 'CTRL-AP-002',
            'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'currency' => 'VND',
            'functional_currency_code' => 'VND', 'functional_total_amount_raw' => '50000', 'functional_total_amount_scale' => 0,
            'original_total_amount_raw' => '50000', 'original_total_amount_scale' => 0, 'total_amount' => '50000.00',
            'status' => 'posted', 'is_posted' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $journal = $this->journal($company->id, $actor->id, '2026-08-01', 'PurchaseInvoice', $invoiceId, [
            ['account_code' => '156', 'debit_amount' => '50000.00', 'credit_amount' => '0.00'],
            ['account_code' => '331', 'debit_amount' => '0.00', 'credit_amount' => '49000.00'],
        ]);
        DB::table('purchase_invoices')->where('id', $invoiceId)->update(['journal_entry_id' => $journal]);
        $this->approvePolicy($actor, $company->id, 'ap', ['331'], 'credit');

        $run = app(ApArSubledgerGlReconciliationService::class)->capture($actor, 'ap', '2026-08-31');

        $this->assertSame('blocked', $run->status);
        $this->assertGreaterThan(0, $run->divergence_count);
        $this->assertSame('blocked', $run->snapshot['status']);
        $this->assertSame('1000.00', $run->snapshot['amounts']['difference']);
        $this->assertTrue($run->exceptions->contains('exception_code', 'apar_subledger_gl_tie_out_mismatch'));
    }

    public function test_approved_policy_computes_ar_using_debit_normal_balance(): void
    {
        [$company, $actor] = $this->tenant();
        $customerId = (int) DB::table('customers')->insertGetId([
            'company_id' => $company->id, 'code' => 'CTRL-CUS', 'name' => 'Controlled customer',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoiceId = (int) DB::table('sales_invoices')->insertGetId([
            'company_id' => $company->id, 'customer_id' => $customerId, 'invoice_number' => 'CTRL-AR-001',
            'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'currency' => 'VND',
            'functional_currency_code' => 'VND', 'functional_total_amount_raw' => '70000', 'functional_total_amount_scale' => 0,
            'original_total_amount_raw' => '70000', 'original_total_amount_scale' => 0, 'total_amount' => '70000.00',
            'status' => 'posted', 'is_posted' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $journal = $this->journal($company->id, $actor->id, '2026-08-01', 'SalesInvoice', $invoiceId, [
            ['account_code' => '131', 'debit_amount' => '70000.00', 'credit_amount' => '0.00'],
            ['account_code' => '511', 'debit_amount' => '0.00', 'credit_amount' => '70000.00'],
        ]);
        DB::table('sales_invoices')->where('id', $invoiceId)->update(['journal_entry_id' => $journal]);
        $this->journal($company->id, $actor->id, '2026-08-15', 'CashReceipt', 9101, [
            ['account_code' => '111', 'debit_amount' => '20000.00', 'credit_amount' => '0.00'],
            ['account_code' => '131', 'debit_amount' => '0.00', 'credit_amount' => '20000.00'],
        ]);
        DB::table('settlement_allocations')->insert([
            'company_id' => $company->id, 'source_document_type' => 'cash_receipt', 'source_document_id' => 9101,
            'source_line_type' => 'cash_receipt_line', 'source_line_id' => 9102, 'source_reference_key' => 'ctrl-ar-001',
            'target_document_type' => 'sales_invoice', 'target_document_id' => $invoiceId,
            'allocation_kind' => 'settlement', 'allocation_direction' => 'reduction', 'amount_raw' => '20000', 'amount_scale' => 0,
            'currency_code' => 'VND', 'functional_currency_code' => 'VND', 'functional_amount_raw' => '20000',
            'functional_amount_scale' => 0, 'original_currency_code' => 'VND', 'original_amount_raw' => '20000',
            'original_amount_scale' => 0, 'effective_date' => '2026-08-15', 'status' => 'posted', 'posted_at' => now(),
            'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->approvePolicy($actor, $company->id, 'ar', ['131'], 'debit');

        $run = app(ApArSubledgerGlReconciliationService::class)->capture($actor, 'ar', '2026-08-31');

        $this->assertSame('approved', $run->status);
        $this->assertSame('50000.00', $run->snapshot['amounts']['subledger_balance']);
        $this->assertSame('50000.00', $run->snapshot['amounts']['gl_control_balance']);
    }

    public function test_controlled_capture_does_not_include_opening_package_updated_after_snapshot_watermark(): void
    {
        [$company, $actor] = $this->tenant();
        $supplierId = (int) DB::table('suppliers')->insertGetId([
            'company_id' => $company->id, 'code' => 'CTRL-SUP-WM', 'name' => 'Watermark supplier',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoiceId = (int) DB::table('purchase_invoices')->insertGetId([
            'company_id' => $company->id, 'supplier_id' => $supplierId, 'invoice_number' => 'CTRL-AP-WM',
            'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'currency' => 'VND',
            'functional_currency_code' => 'VND', 'functional_total_amount_raw' => '60000', 'functional_total_amount_scale' => 0,
            'original_total_amount_raw' => '60000', 'original_total_amount_scale' => 0, 'total_amount' => '60000.00',
            'status' => 'posted', 'is_posted' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $journal = $this->journal($company->id, $actor->id, '2026-08-01', 'PurchaseInvoice', $invoiceId, [
            ['account_code' => '156', 'debit_amount' => '60000.00', 'credit_amount' => '0.00'],
            ['account_code' => '331', 'debit_amount' => '0.00', 'credit_amount' => '60000.00'],
        ]);
        DB::table('purchase_invoices')->where('id', $invoiceId)->update(['journal_entry_id' => $journal]);
        $this->approvePolicy($actor, $company->id, 'ap', ['331'], 'credit');

        $future = now()->addDay();
        $packageId = (int) DB::table('opening_balance_packages')->insertGetId([
            'company_id' => $company->id, 'effective_date' => '2026-01-01', 'status' => 'confirmed',
            'created_by' => $actor->id, 'confirmed_by' => $actor->id, 'confirmed_at' => now(),
            'created_at' => now(), 'updated_at' => $future,
        ]);
        DB::table('opening_balance_party_lines')->insert([
            'package_id' => $packageId, 'party_type' => 'supplier', 'party_id' => $supplierId, 'account_code' => '331',
            'document_number' => 'OB-WM', 'due_date' => '2025-12-31', 'debit_amount' => '0.00', 'credit_amount' => '5000.00',
            'created_at' => now(), 'updated_at' => $future,
        ]);

        $run = app(ApArSubledgerGlReconciliationService::class)->capture($actor, 'ap', '2026-08-31');

        $this->assertSame('approved', $run->status);
        $this->assertSame('60000.00', $run->snapshot['amounts']['subledger_balance']);
        $this->assertSame('60000.00', $run->snapshot['amounts']['gl_control_balance']);
        $this->assertSame('0.00', $run->snapshot['amounts']['difference']);
    }

    private function tenant(): array
    {
        $company = Company::query()->firstOrFail();
        $this->ensureControlAccounts($company);
        $actor = User::factory()->create(['company_id' => $company->id]);
        return [$company, $actor];
    }

    private function ensureControlAccounts(Company $company): void
    {
        foreach ([
            ['code' => '131', 'name' => 'Phải thu khách hàng', 'type' => 'asset', 'nature' => 'amphibious'],
            ['code' => '331', 'name' => 'Phải trả người bán', 'type' => 'liability', 'nature' => 'amphibious'],
        ] as $account) {
            DB::table('chart_of_accounts')->updateOrInsert(
                ['company_id' => $company->id, 'code' => $account['code']],
                $account + [
                    'name_en' => null,
                    'parent_code' => null,
                    'level' => 1,
                    'is_parent' => false,
                    'is_active' => true,
                    'description' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function approvePolicy(User $actor, int $companyId, string $ledger, array $accounts, string $normalBalance): void
    {
        $profileId = FiscalYear::withoutGlobalScope('company')->where('company_id', $companyId)->firstOrFail()->accountingRegimeProfile()->firstOrFail()->id;
        $policy = app(AccountingPolicyLifecycleService::class)->createDraft($actor, [
            'company_id' => $companyId, 'accounting_regime_profile_id' => $profileId,
            'policy_key' => 'reconciliation.ap_ar', 'policy_version' => '2026.1',
            'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
            'posting_rule_contract' => [
                'subledger_gl_reconciliation' => [
                    'schema' => 'apar-subledger-gl-reconciliation.v1', 'algorithm' => 'invoice-settlement-v1',
                    'functional_currency' => 'VND', 'ledgers' => [$ledger => [
                        'control_account_codes' => $accounts, 'normal_balance' => $normalBalance,
                        'settlement_source_types' => [$ledger === 'ap' ? 'cash_payment' : 'cash_receipt'],
                        'line_required_source_types' => [$ledger === 'ap' ? 'cash_payment' : 'cash_receipt'],
                    ]],
                ],
            ],
            'required_dimensions' => [], 'regulatory_dependencies' => [],
        ]);
        app(AccountingPolicyLifecycleService::class)->approve($actor, $policy);
    }

    private function journal(int $companyId, int $actorId, string $date, string $sourceType, int $sourceId, array $lines): int
    {
        $id = (int) DB::table('journal_entries')->insertGetId([
            'company_id' => $companyId, 'fiscal_year_id' => DB::table('fiscal_years')->where('company_id', $companyId)->value('id'),
            'voucher_type' => 'general', 'voucher_number' => 'CTRL-'.uniqid(),
            'voucher_date' => $date, 'posting_date' => $date, 'description' => 'Controlled reconciliation fixture',
            'total_amount' => collect($lines)->sum(fn (array $line): float => (float) $line['debit_amount']),
            'status' => 'posted', 'source_document_type' => $sourceType, 'source_document_id' => $sourceId,
            'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $line) {
            DB::table('journal_entry_lines')->insert($line + ['journal_entry_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
        return $id;
    }
}
