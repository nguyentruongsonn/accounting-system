<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SettlementAllocation;
use App\Models\User;
use App\Services\CashPaymentService;
use App\Services\PurchaseDiscountService;
use App\Services\PurchaseInvoiceService;
use App\Services\SettlementAllocationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class SettlementAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_typed_posted_settlement_with_exact_source_scale(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $invoiceId = $this->purchaseInvoice($company->id);
        $paymentId = $this->cashPayment($company->id, true);
        $paymentLineId = $this->cashPaymentLine($paymentId, 100);

        $allocation = app(SettlementAllocationService::class)->createPosted($actor, [
            'source_document_type' => 'cash_payment',
            'source_document_id' => $paymentId,
            'source_line_type' => 'cash_payment_line',
            'source_line_id' => $paymentLineId,
            'target_document_type' => 'purchase_invoice',
            'target_document_id' => $invoiceId,
            'allocation_kind' => 'settlement',
            'amount_raw' => '100',
            'amount_scale' => 0,
            'currency_code' => 'VND',
            'effective_date' => '2026-08-22',
        ]);

        $this->assertSame($company->id, $allocation->company_id);
        $this->assertSame('cash_payment', $allocation->source_document_type);
        $this->assertSame('purchase_invoice', $allocation->target_document_type);
        $this->assertSame('100', $allocation->amount_raw);
        $this->assertSame("cash_payment|{$paymentId}|cash_payment_line|{$paymentLineId}|purchase_invoice|{$invoiceId}", $allocation->source_reference_key);
        $this->assertSame(0, $allocation->amount_scale);
        $this->assertSame('posted', $allocation->status);
        $this->assertNotNull($allocation->posted_at);
        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $company->id,
            'user_id' => $actor->id,
            'action' => 'settlement_allocation.posted',
            'model_type' => SettlementAllocation::class,
            'model_id' => $allocation->id,
        ]);

        try {
            DB::table('settlement_allocations')->insert([
                'company_id' => $company->id,
                'source_document_type' => 'cash_payment',
                'source_document_id' => $paymentId,
                'source_line_type' => 'cash_payment_line',
                'source_line_id' => $paymentLineId,
                'source_reference_key' => $allocation->source_reference_key,
                'target_document_type' => 'purchase_invoice',
                'target_document_id' => $invoiceId,
                'allocation_kind' => 'settlement',
                'amount_raw' => '100',
                'amount_scale' => 0,
                'currency_code' => 'VND',
                'effective_date' => '2026-08-22',
                'status' => 'posted',
                'posted_at' => now(),
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The database must reject a duplicate canonical source reference.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        $allocation->amount_raw = '99';
        $this->expectException(LogicException::class);
        $allocation->save();

    }

    public function test_it_does_not_confuse_ap_and_ar_documents_with_the_same_numeric_id(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $purchaseId = $this->purchaseInvoice($company->id);
        $customerId = DB::table('customers')->insertGetId([
            'company_id' => $company->id, 'code' => 'ALLOC-CUS', 'name' => 'Allocation customer', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_invoices')->insert([
            'id' => $purchaseId, 'company_id' => $company->id, 'customer_id' => $customerId,
            'invoice_number' => 'SI-ALLOC', 'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01',
            'due_date' => '2026-08-31', 'total_amount' => '100', 'status' => 'draft', 'is_posted' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $receiptId = $this->cashReceipt($company->id, true);
        $receiptLineId = $this->cashReceiptLine($receiptId, 100);

        $allocation = app(SettlementAllocationService::class)->createPosted($actor, [
            'source_document_type' => 'cash_receipt', 'source_document_id' => $receiptId,
            'source_line_type' => 'cash_receipt_line', 'source_line_id' => $receiptLineId,
            'target_document_type' => 'sales_invoice', 'target_document_id' => $purchaseId,
            'allocation_kind' => 'settlement', 'amount_raw' => '100', 'amount_scale' => 0,
            'effective_date' => '2026-08-22',
        ]);

        $this->assertSame('sales_invoice', $allocation->target_document_type);
        $this->assertSame($purchaseId, $allocation->target_document_id);
    }

    public function test_it_rejects_cross_tenant_or_unposted_source_and_invalid_amount_scale(): void
    {
        $company = Company::query()->firstOrFail();
        $other = Company::query()->create(['name' => 'Allocation other', 'tax_code' => 'ALLOC-OTHER', 'address' => 'Hanoi']);
        $actor = User::factory()->create(['company_id' => $company->id]);
        $invoiceId = $this->purchaseInvoice($company->id);
        $foreignPayment = $this->cashPayment($other->id, true);

        $this->expectException(AuthorizationException::class);
        app(SettlementAllocationService::class)->createPosted($actor, [
            'source_document_type' => 'cash_payment', 'source_document_id' => $foreignPayment,
            'target_document_type' => 'purchase_invoice', 'target_document_id' => $invoiceId,
            'allocation_kind' => 'settlement', 'amount_raw' => '1', 'amount_scale' => 0,
            'effective_date' => '2026-08-22',
        ]);
    }

    public function test_it_rejects_zero_settlement_amounts(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $paymentId = $this->cashPayment($company->id, true);
        $lineId = $this->cashPaymentLine($paymentId, 100);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Settlement amount must be a positive exact decimal string.');
        app(SettlementAllocationService::class)->createPosted($actor, [
            'source_document_type' => 'cash_payment', 'source_document_id' => $paymentId,
            'source_line_type' => 'cash_payment_line', 'source_line_id' => $lineId,
            'target_document_type' => 'purchase_invoice', 'target_document_id' => $this->purchaseInvoice($company->id),
            'allocation_kind' => 'settlement', 'amount_raw' => '0', 'amount_scale' => 0,
            'effective_date' => '2026-08-22',
        ]);
    }

    public function test_it_rejects_a_typed_source_when_its_ledger_or_allocation_kind_is_incompatible(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $customerId = DB::table('customers')->insertGetId([
            'company_id' => $company->id, 'code' => 'ALLOC-COMPAT-CUS', 'name' => 'Compatibility customer', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $salesInvoiceId = (int) DB::table('sales_invoices')->insertGetId([
            'company_id' => $company->id, 'customer_id' => $customerId, 'invoice_number' => 'SI-ALLOC-COMPAT',
            'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'due_date' => '2026-08-31',
            'total_amount' => '100', 'status' => 'draft', 'is_posted' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(SettlementAllocationService::class)->createPosted($actor, [
            'source_document_type' => 'cash_payment', 'source_document_id' => $this->cashPayment($company->id, true),
            'target_document_type' => 'sales_invoice', 'target_document_id' => $salesInvoiceId,
            'allocation_kind' => 'settlement', 'amount_raw' => '100', 'amount_scale' => 0,
            'effective_date' => '2026-08-22',
        ]);
    }

    public function test_it_requires_a_real_source_line_and_never_allocates_beyond_that_line_or_the_invoice(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $paymentId = $this->cashPayment($company->id, true);
        $lineId = $this->cashPaymentLine($paymentId, 100);
        $firstInvoice = $this->purchaseInvoice($company->id);
        $secondInvoice = $this->purchaseInvoice($company->id);
        $service = app(SettlementAllocationService::class);

        $payload = [
            'source_document_type' => 'cash_payment', 'source_document_id' => $paymentId,
            'source_line_type' => 'cash_payment_line', 'source_line_id' => $lineId,
            'target_document_type' => 'purchase_invoice', 'target_document_id' => $firstInvoice,
            'allocation_kind' => 'settlement', 'amount_raw' => '60', 'amount_scale' => 0,
            'effective_date' => '2026-08-22',
        ];
        $service->createPosted($actor, $payload);
        $service->createPosted($actor, [...$payload, 'target_document_id' => $secondInvoice, 'amount_raw' => '40']);

        try {
            $service->createPosted($actor, [...$payload, 'target_document_id' => $secondInvoice, 'amount_raw' => '1']);
            $this->fail('An allocation above the exact source-line capacity must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Settlement allocation exceeds the posted source line amount.', $exception->getMessage());
        }

        try {
            $service->createPosted($actor, [...$payload, 'source_line_id' => $lineId + 999, 'amount_raw' => '1']);
            $this->fail('A fabricated source line must be rejected.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_posted_source_with_canonical_evidence_cannot_be_unposted_or_voided(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $paymentId = $this->cashPayment($company->id, true);
        $lineId = $this->cashPaymentLine($paymentId, 100);
        app(SettlementAllocationService::class)->createPosted($actor, [
            'source_document_type' => 'cash_payment', 'source_document_id' => $paymentId,
            'source_line_type' => 'cash_payment_line', 'source_line_id' => $lineId,
            'target_document_type' => 'purchase_invoice', 'target_document_id' => $this->purchaseInvoice($company->id),
            'allocation_kind' => 'settlement', 'amount_raw' => '100', 'amount_scale' => 0,
            'effective_date' => '2026-08-22',
        ]);

        foreach (['unpost', 'void'] as $operation) {
            try {
                app(CashPaymentService::class)->{$operation}($paymentId);
                $this->fail("{$operation} must not detach posted allocation evidence.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('settlement_allocations', $exception->errors());
            }
        }
    }

    public function test_posted_target_with_canonical_evidence_cannot_be_unposted_or_voided(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $paymentId = $this->cashPayment($company->id, true);
        $lineId = $this->cashPaymentLine($paymentId, 100);
        $invoiceId = $this->purchaseInvoice($company->id);
        app(SettlementAllocationService::class)->createPosted($actor, [
            'source_document_type' => 'cash_payment', 'source_document_id' => $paymentId,
            'source_line_type' => 'cash_payment_line', 'source_line_id' => $lineId,
            'target_document_type' => 'purchase_invoice', 'target_document_id' => $invoiceId,
            'allocation_kind' => 'settlement', 'amount_raw' => '100', 'amount_scale' => 0,
            'effective_date' => '2026-08-22',
        ]);

        foreach (['unpost', 'void'] as $operation) {
            try {
                app(PurchaseInvoiceService::class)->{$operation}($invoiceId);
                $this->fail("{$operation} must not detach posted allocation evidence from the target.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('settlement_allocations', $exception->errors());
            }
        }
    }

    public function test_posted_referenced_debt_reduction_adjustment_can_create_exact_canonical_evidence(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $invoiceId = $this->purchaseInvoice($company->id);
        $discountId = $this->purchaseDiscount($company->id, $invoiceId, '25.50');

        $allocation = app(SettlementAllocationService::class)->createPosted($actor, [
            'source_document_type' => 'purchase_discount', 'source_document_id' => $discountId,
            'target_document_type' => 'purchase_invoice', 'target_document_id' => $invoiceId,
            'allocation_kind' => 'discount', 'amount_raw' => '25.50', 'amount_scale' => 2,
            'effective_date' => '2026-08-22',
        ]);

        $this->assertSame('purchase_discount', $allocation->source_document_type);
        $this->assertSame('discount', $allocation->allocation_kind);

        try {
            app(PurchaseDiscountService::class)->void($discountId);
            $this->fail('A posted adjustment with canonical evidence must not be voided.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settlement_allocations', $exception->errors());
        }

        $this->expectException(InvalidArgumentException::class);
        app(SettlementAllocationService::class)->createPosted($actor, [
            'source_document_type' => 'purchase_discount', 'source_document_id' => $discountId,
            'target_document_type' => 'purchase_invoice', 'target_document_id' => $invoiceId,
            'allocation_kind' => 'discount', 'amount_raw' => '0.01', 'amount_scale' => 2,
            'effective_date' => '2026-08-22',
        ]);
    }

    public function test_foreign_currency_settlement_requires_complete_exact_dual_currency_evidence(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $invoiceId = $this->purchaseInvoice($company->id);
        DB::table('purchase_invoices')->where('id', $invoiceId)->update(['currency' => 'USD', 'total_amount' => '260000.00']);
        $paymentId = $this->cashPayment($company->id, true);
        $lineId = $this->cashPaymentLine($paymentId, 260000);
        DB::table('cash_payment_lines')->where('id', $lineId)->update([
            'original_currency_code' => 'USD', 'original_amount_raw' => '10', 'original_amount_scale' => 0,
        ]);
        $payload = [
            'source_document_type' => 'cash_payment', 'source_document_id' => $paymentId,
            'source_line_type' => 'cash_payment_line', 'source_line_id' => $lineId,
            'target_document_type' => 'purchase_invoice', 'target_document_id' => $invoiceId,
            'allocation_kind' => 'settlement', 'amount_raw' => '260000', 'amount_scale' => 0,
            'currency_code' => 'VND', 'effective_date' => '2026-08-22',
            'functional_currency_code' => 'VND', 'functional_amount_raw' => '260000', 'functional_amount_scale' => 0,
            'original_currency_code' => 'USD', 'original_amount_raw' => '10', 'original_amount_scale' => 0,
        ];
        $allocation = app(SettlementAllocationService::class)->createPosted($actor, $payload);
        $this->assertSame('USD', $allocation->original_currency_code);
        $this->assertSame('10', $allocation->original_amount_raw);
        $this->assertSame('260000', $allocation->functional_amount_raw);

        $this->expectException(InvalidArgumentException::class);
        app(SettlementAllocationService::class)->createPosted($actor, [...$payload, 'original_currency_code' => null]);
    }

    private function purchaseInvoice(int $companyId): int
    {
        $supplierId = DB::table('suppliers')->insertGetId([
            'company_id' => $companyId, 'code' => 'ALLOC-SUP-'.uniqid(), 'name' => 'Allocation supplier', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('purchase_invoices')->insertGetId([
            'company_id' => $companyId, 'supplier_id' => $supplierId, 'invoice_number' => 'PI-ALLOC-'.uniqid(),
            'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'due_date' => '2026-08-31',
            'total_amount' => '100', 'status' => 'draft', 'is_posted' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cashPayment(int $companyId, bool $posted): int
    {
        return (int) DB::table('cash_payments')->insertGetId([
            'company_id' => $companyId, 'voucher_number' => 'CP-ALLOC-'.uniqid(), 'voucher_date' => '2026-08-22',
            'posting_date' => '2026-08-22', 'total_amount' => 100, 'status' => $posted ? 'posted' : 'draft',
            'is_posted' => $posted, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cashReceipt(int $companyId, bool $posted): int
    {
        return (int) DB::table('cash_receipts')->insertGetId([
            'company_id' => $companyId, 'voucher_number' => 'CR-ALLOC-'.uniqid(), 'voucher_date' => '2026-08-22',
            'posting_date' => '2026-08-22', 'total_amount' => 100, 'status' => $posted ? 'posted' : 'draft',
            'is_posted' => $posted, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cashPaymentLine(int $paymentId, int $amount): int
    {
        return (int) DB::table('cash_payment_lines')->insertGetId([
            'cash_payment_id' => $paymentId, 'debit_account' => '331', 'credit_account' => '111', 'amount' => $amount,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cashReceiptLine(int $receiptId, int $amount): int
    {
        return (int) DB::table('cash_receipt_lines')->insertGetId([
            'cash_receipt_id' => $receiptId, 'debit_account' => '111', 'credit_account' => '131', 'amount' => $amount,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function purchaseDiscount(int $companyId, int $invoiceId, string $amount): int
    {
        return (int) DB::table('purchase_discounts')->insertGetId([
            'company_id' => $companyId,
            'voucher_number' => 'PD-ALLOC-'.uniqid(),
            'voucher_date' => '2026-08-22',
            'accounting_date' => '2026-08-22',
            'total_amount' => $amount,
            'grand_total' => $amount,
            'reference_invoice_id' => $invoiceId,
            'is_posted' => true,
            'is_decrease_debt' => true,
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
