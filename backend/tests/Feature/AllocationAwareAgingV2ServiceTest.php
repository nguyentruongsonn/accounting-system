<?php

namespace Tests\Feature;

use App\Exceptions\ReportDefinitionUnavailableException;
use App\Models\Company;
use App\Models\User;
use App\Services\AllocationAwareAgingV2Service;
use App\Services\ManagementReportDefinitionLifecycleService;
use App\Support\DecimalMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AllocationAwareAgingV2ServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ap_id_collision_with_ar_invoice_fails_closed_instead_of_applying_untyped_payment(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $supplierId = $this->supplier(1, 'SUP-AGING');
        $invoiceId = $this->purchaseInvoice(1, $supplierId, 'PINV-AGING', '2026-08-01', '2026-08-01', '100.25');
        $customerId = $this->customer(1, 'CUS-COLLISION');
        // The separate AP/AR tables permit the same number to identify two
        // different invoices. A legacy cash line's invoice_id cannot say
        // which one it clears.
        $this->salesInvoiceWithId(1, $customerId, $invoiceId, 'SINV-COLLISION', '2026-08-01', '2026-08-01', '999.00');

        $this->cashPayment(1, $invoiceId, '2026-08-10', '40.10', true);

        $this->expectException(ReportDefinitionUnavailableException::class);
        app(AllocationAwareAgingV2Service::class)->accountsPayable(
            $this->publishedDefinition($actor, 'accounts_payable_aging.v2', 'ap'),
            1,
            '2026-08-31',
        );
    }

    public function test_ar_id_collision_with_ap_invoice_fails_closed_instead_of_applying_untyped_receipt(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $supplierId = $this->supplier(1, 'SUP-COLLISION');
        $customerOne = $this->customer(1, 'CUS-ONE');
        $invoiceId = $this->salesInvoice(1, $customerOne, 'SINV-ONE', '2026-08-01', '2026-09-15', '50.00', 'Paid');
        $this->purchaseInvoiceWithId(1, $supplierId, $invoiceId, 'PINV-COLLISION', '2026-08-01', '2026-08-01', '999.00');
        $this->cashReceipt(1, $invoiceId, '2026-08-10', '40.10', true);

        $definition = $this->publishedDefinition($actor, 'accounts_receivable_aging.v2', 'ar');
        $service = app(AllocationAwareAgingV2Service::class);

        $this->expectException(ReportDefinitionUnavailableException::class);
        $service->accountsReceivable($definition, 1, '2026-08-31');
    }

    public function test_cross_tenant_party_reference_fails_before_any_unattributed_aging_row_is_emitted(): void
    {
        $companyTwo = Company::query()->create(['name' => 'Other company', 'tax_code' => 'OTHER', 'address' => 'Other']);
        $actor = User::factory()->create(['company_id' => 1]);
        $foreignSupplierId = $this->supplier($companyTwo->id, 'SUP-OTHER-TENANT');
        $this->purchaseInvoice(1, $foreignSupplierId, 'PINV-CROSS-TENANT', '2026-08-01', '2026-08-01', '50.00');

        $this->expectException(ReportDefinitionUnavailableException::class);
        app(AllocationAwareAgingV2Service::class)->accountsPayable(
            $this->publishedDefinition($actor, 'accounts_payable_aging.v2', 'ap'),
            1,
            '2026-08-31',
        );
    }

    public function test_canonical_typed_settlement_evidence_still_fails_closed_until_adjustment_completeness_is_implemented(): void
    {
        // The source no longer reads legacy invoice_id lines. Even canonical
        // typed settlement evidence is not sufficient while adjustment feeds
        // (returns, discounts, credit notes, write-offs) are incomplete.
        $source = AllocationAwareAgingV2Service::supportedSourceContract('ap');
        $this->assertSame('settlement_allocations', $source['allocations']['table']);
        $this->assertSame(['cash_payment', 'bank_payment', 'purchase_return', 'purchase_discount', 'ap_debt_adjustment'], $source['allocations']['source_types']);
        $this->assertSame(['cash_payment', 'bank_payment'], $source['allocations']['line_required_source_types']);
        $this->assertSame(['purchase_return', 'purchase_discount', 'ap_debt_adjustment'], $source['allocations']['document_amount_source_types']);
        $this->assertSame('partial', $source['adjustment_completeness']['status']);
        $actor = User::factory()->create(['company_id' => 1]);
        $supplierId = $this->supplier(1, 'SUP-ADJUSTMENT-GATE');
        $invoiceId = $this->purchaseInvoice(1, $supplierId, 'PINV-ADJUSTMENT-GATE', '2026-08-01', '2026-08-01', '100.00');

        $this->expectException(ReportDefinitionUnavailableException::class);
        app(AllocationAwareAgingV2Service::class)->accountsPayable(
            $this->publishedDefinition($actor, 'accounts_payable_aging.v2', 'ap'),
            1,
            '2026-08-31',
        );
    }

    public function test_incomplete_or_unknown_contract_is_not_interpreted(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $definition = $this->publishedDefinition($actor, 'accounts_payable_aging.v2', 'ap');
        $source = $definition->source_contract;
        $source['invoice']['cutoff_date_field'] = 'invoice_date';

        $draft = app(ManagementReportDefinitionLifecycleService::class)->createDraft(
            $actor,
            'accounts_payable_aging.v2',
            'unsupported-contract',
            $source,
            AllocationAwareAgingV2Service::supportedCalculationContract(),
        );
        $unsupported = app(ManagementReportDefinitionLifecycleService::class)->publish($actor, $draft);

        $this->expectException(ReportDefinitionUnavailableException::class);
        app(AllocationAwareAgingV2Service::class)->accountsPayable($unsupported, 1, '2026-08-31');
    }

    public function test_decimal_kernel_retains_values_beyond_php_integer_range(): void
    {
        $this->assertSame(
            '9223372036854775808.00',
            DecimalMoney::subtract('9223372036854775808.01', '0.01'),
        );
    }

    public function test_v2_contract_uses_invoice_cutoff_date_when_due_date_is_null(): void
    {
        $source = AllocationAwareAgingV2Service::supportedSourceContract('ap');

        $this->assertSame('invoice_cutoff_date', $source['invoice']['null_due_date']);
        $this->assertSame('2026-08-01', AllocationAwareAgingV2Service::resolveInvoiceAgingDate(null, '2026-08-01'));
        $this->assertSame('2026-08-20', AllocationAwareAgingV2Service::resolveInvoiceAgingDate('2026-08-20', '2026-08-01'));
    }

    public function test_v2_contract_excludes_voided_and_cancelled_invoices(): void
    {
        foreach (['ap', 'ar'] as $ledger) {
            $source = AllocationAwareAgingV2Service::supportedSourceContract($ledger);

            $this->assertSame('status', $source['invoice']['status_field']);
            $this->assertSame(['voided', 'cancelled', 'canceled'], $source['invoice']['excluded_statuses']);
        }
    }

    private function publishedDefinition(User $actor, string $key, string $ledger)
    {
        $definitions = app(ManagementReportDefinitionLifecycleService::class);
        $draft = $definitions->createDraft(
            $actor,
            $key,
            uniqid('test-', true),
            AllocationAwareAgingV2Service::supportedSourceContract($ledger),
            AllocationAwareAgingV2Service::supportedCalculationContract(),
        );

        return $definitions->publish($actor, $draft);
    }

    private function supplier(int $companyId, string $code): int
    {
        return (int) DB::table('suppliers')->insertGetId([
            'company_id' => $companyId, 'code' => $code, 'name' => 'Supplier aging', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function customer(int $companyId, string $code): int
    {
        return (int) DB::table('customers')->insertGetId([
            'company_id' => $companyId, 'code' => $code, 'name' => 'Customer aging', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function purchaseInvoice(int $companyId, int $supplierId, string $number, string $accountingDate, ?string $dueDate, string $amount): int
    {
        return (int) DB::table('purchase_invoices')->insertGetId([
            'company_id' => $companyId, 'supplier_id' => $supplierId, 'invoice_number' => $number,
            'invoice_date' => $accountingDate, 'accounting_date' => $accountingDate, 'due_date' => $dueDate,
            'total_amount' => $amount, 'status' => 'Paid', 'is_posted' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }


    private function salesInvoice(int $companyId, int $customerId, string $number, string $accountingDate, string $dueDate, string $amount, string $status): int
    {
        return (int) DB::table('sales_invoices')->insertGetId([
            'company_id' => $companyId, 'customer_id' => $customerId, 'invoice_number' => $number,
            'invoice_date' => $accountingDate, 'accounting_date' => $accountingDate, 'due_date' => $dueDate,
            'total_amount' => $amount, 'status' => $status, 'is_posted' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }


    private function purchaseInvoiceWithId(int $companyId, int $supplierId, int $id, string $number, string $accountingDate, string $dueDate, string $amount): void
    {
        DB::table('purchase_invoices')->insert([
            'id' => $id, 'company_id' => $companyId, 'supplier_id' => $supplierId, 'invoice_number' => $number,
            'invoice_date' => $accountingDate, 'accounting_date' => $accountingDate, 'due_date' => $dueDate,
            'total_amount' => $amount, 'status' => 'Paid', 'is_posted' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function salesInvoiceWithId(int $companyId, int $customerId, int $id, string $number, string $accountingDate, string $dueDate, string $amount): void
    {
        DB::table('sales_invoices')->insert([
            'id' => $id, 'company_id' => $companyId, 'customer_id' => $customerId, 'invoice_number' => $number,
            'invoice_date' => $accountingDate, 'accounting_date' => $accountingDate, 'due_date' => $dueDate,
            'total_amount' => $amount, 'status' => 'Paid', 'is_posted' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cashPayment(int $companyId, int $invoiceId, string $date, string $amount, bool $posted, ?string $invoiceType = null): void
    {
        $id = DB::table('cash_payments')->insertGetId([
            'company_id' => $companyId, 'voucher_number' => uniqid('CP-', true), 'voucher_date' => $date,
            'posting_date' => $date, 'total_amount' => $amount, 'status' => $posted ? 'posted' : 'draft',
            'is_posted' => $posted, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $line = [
            'cash_payment_id' => $id, 'debit_account' => '331', 'credit_account' => '111',
            'amount' => $amount, 'invoice_id' => $invoiceId, 'created_at' => now(), 'updated_at' => now(),
        ];
        if (Schema::hasColumn('cash_payment_lines', 'invoice_type')) {
            $line['invoice_type'] = $invoiceType;
        }
        DB::table('cash_payment_lines')->insert($line);
    }

    private function cashReceipt(int $companyId, int $invoiceId, string $date, string $amount, bool $posted): void
    {
        $id = DB::table('cash_receipts')->insertGetId([
            'company_id' => $companyId, 'voucher_number' => uniqid('CR-', true), 'voucher_date' => $date,
            'posting_date' => $date, 'total_amount' => $amount, 'status' => $posted ? 'posted' : 'draft',
            'is_posted' => $posted, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cash_receipt_lines')->insert([
            'cash_receipt_id' => $id, 'debit_account' => '111', 'credit_account' => '131',
            'amount' => $amount, 'invoice_id' => $invoiceId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
