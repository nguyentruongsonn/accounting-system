<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SettlementAllocationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_typed_settlement_api_requires_permission_and_is_tenant_bound(): void
    {
        $company = Company::query()->firstOrFail();
        $invoiceId = $this->invoice($company->id);
        $paymentId = $this->payment($company->id);
        $paymentLineId = $this->paymentLine($paymentId);
        $unprivileged = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($unprivileged);

        $payload = $this->payload($paymentId, $paymentLineId, $invoiceId);
        $this->postJson('/api/v1/settlement-allocations', $payload)->assertForbidden();

        foreach (['settlement.allocations.create', 'settlement.allocations.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $unprivileged->givePermissionTo(['settlement.allocations.create', 'settlement.allocations.view']);

        $created = $this->postJson('/api/v1/settlement-allocations', $payload)
            ->assertCreated()
            ->assertJsonPath('data.target_document_type', 'purchase_invoice')
            ->assertJsonPath('data.target_document_id', $invoiceId)
            ->assertJsonPath('data.amount_raw', '100');

        $this->getJson('/api/v1/settlement-allocations?target_document_type=purchase_invoice&target_document_id='.$invoiceId)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $created->json('data.id'));

        $discountId = $this->purchaseDiscount($company->id, $invoiceId);
        $this->postJson('/api/v1/settlement-allocations', [
            'source_document_type' => 'purchase_discount',
            'source_document_id' => $discountId,
            'target_document_type' => 'purchase_invoice',
            'target_document_id' => $invoiceId,
            'allocation_kind' => 'discount',
            'amount_raw' => '20.00',
            'amount_scale' => 2,
            'effective_date' => '2026-08-22',
        ])
            ->assertCreated()
            ->assertJsonPath('data.source_document_type', 'purchase_discount')
            ->assertJsonPath('data.allocation_kind', 'discount');

        $retryPaymentId = $this->payment($company->id);
        $retryPayload = $this->payload($retryPaymentId, $this->paymentLine($retryPaymentId), $invoiceId);
        $retryPayload['amount_raw'] = '50';
        $this->postJson('/api/v1/settlement-allocations', $retryPayload)->assertCreated();
        $duplicate = $this->postJson('/api/v1/settlement-allocations', $retryPayload)
            ->assertConflict()
            ->assertJsonPath('error_code', 'SETTLEMENT_ALLOCATION_DUPLICATE')
            ->assertJsonPath('error', 'The request conflicts with existing settlement allocation evidence.');
        $this->assertCorrelated($duplicate);
    }

    public function test_settlement_allocation_auth_failures_are_correlated_and_non_leaking(): void
    {
        $unauthenticated = $this->postJson('/api/v1/settlement-allocations', [])
            ->assertUnauthorized()
            ->assertJsonPath('error', 'Unauthenticated.');
        $this->assertCorrelated($unauthenticated);

        Permission::firstOrCreate(['name' => 'settlement.allocations.create', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs(User::factory()->create(['company_id' => 1]));
        $forbidden = $this->postJson('/api/v1/settlement-allocations', [])
            ->assertForbidden()
            ->assertJsonPath('error', 'This action is unauthorized.');
        $this->assertCorrelated($forbidden);
    }

    private function assertCorrelated(TestResponse $response): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', (string) $response->json('request_id'));
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-ID'));
    }

    private function payload(int $paymentId, int $paymentLineId, int $invoiceId): array
    {
        return [
            'source_document_type' => 'cash_payment', 'source_document_id' => $paymentId,
            'source_line_type' => 'cash_payment_line', 'source_line_id' => $paymentLineId,
            'target_document_type' => 'purchase_invoice', 'target_document_id' => $invoiceId,
            'allocation_kind' => 'settlement', 'amount_raw' => '100', 'amount_scale' => 0,
            'currency_code' => 'VND', 'effective_date' => '2026-08-22',
        ];
    }

    private function invoice(int $companyId): int
    {
        $supplierId = DB::table('suppliers')->insertGetId([
            'company_id' => $companyId, 'code' => 'SETTLE-API-SUP', 'name' => 'Settlement supplier', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('purchase_invoices')->insertGetId([
            'company_id' => $companyId, 'supplier_id' => $supplierId, 'invoice_number' => 'SETTLE-API-PI',
            'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'due_date' => '2026-08-31',
            'total_amount' => 300, 'status' => 'draft', 'is_posted' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function payment(int $companyId): int
    {
        return (int) DB::table('cash_payments')->insertGetId([
            'company_id' => $companyId, 'voucher_number' => 'SETTLE-API-CP-'.uniqid(), 'voucher_date' => '2026-08-22',
            'posting_date' => '2026-08-22', 'total_amount' => 100, 'status' => 'posted', 'is_posted' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function paymentLine(int $paymentId): int
    {
        return (int) DB::table('cash_payment_lines')->insertGetId([
            'cash_payment_id' => $paymentId, 'debit_account' => '331', 'credit_account' => '111', 'amount' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function purchaseDiscount(int $companyId, int $invoiceId): int
    {
        return (int) DB::table('purchase_discounts')->insertGetId([
            'company_id' => $companyId,
            'voucher_number' => 'SETTLE-API-PD-'.uniqid(),
            'voucher_date' => '2026-08-22',
            'accounting_date' => '2026-08-22',
            'total_amount' => '20.00',
            'grand_total' => '20.00',
            'reference_invoice_id' => $invoiceId,
            'is_posted' => true,
            'is_decrease_debt' => true,
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
