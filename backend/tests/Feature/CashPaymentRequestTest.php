<?php

namespace Tests\Feature;

use App\Models\CashPaymentRequest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashPaymentRequestTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::findOrFail(1);
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        Sanctum::actingAs($this->user);
        $this->configureAccountingTenant($this->user, $this->company);
    }

    public function test_can_create_and_list_a_draft_request(): void
    {
        $response = $this->postJson('/api/v1/cash/payment-requests', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.request_number', 'DNC-001');
        $this->assertDatabaseHas('cash_payment_requests', [
            'id' => $response->json('data.id'),
            'company_id' => $this->company->id,
            'status' => 'draft',
        ]);
        $this->getJson('/api/v1/cash/payment-requests')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_can_submit_request_without_creating_a_cash_voucher(): void
    {
        $request = CashPaymentRequest::create($this->payload() + ['company_id' => $this->company->id]);

        $this->postJson("/api/v1/cash/payment-requests/{$request->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.id', $request->id)
            ->assertJsonPath('data.status', 'submitted');
        $this->assertDatabaseHas('cash_payment_requests', ['id' => $request->id, 'status' => 'submitted']);
        $this->assertDatabaseMissing('cash_payments', ['description' => 'Internal request DNC-001']);
    }

    public function test_draft_can_be_updated_and_deleted(): void
    {
        $request = CashPaymentRequest::create($this->payload() + ['company_id' => $this->company->id]);
        $this->putJson("/api/v1/cash/payment-requests/{$request->id}", $this->payload(['reason' => 'Updated reason']))
            ->assertOk()->assertJsonPath('data.reason', 'Updated reason');
        $this->deleteJson("/api/v1/cash/payment-requests/{$request->id}")->assertOk();
        $this->assertDatabaseMissing('cash_payment_requests', ['id' => $request->id]);
    }

    public function test_foreign_request_is_not_visible_or_mutable(): void
    {
        $foreignCompany = Company::create(['name' => 'Foreign', 'tax_code' => 'FOREIGN-CASH-REQUEST', 'address' => 'B']);
        $request = CashPaymentRequest::create(array_replace($this->payload(['request_number' => 'FOREIGN-001']), ['company_id' => $foreignCompany->id]));

        $this->getJson("/api/v1/cash/payment-requests/{$request->id}")->assertNotFound();
        $this->postJson("/api/v1/cash/payment-requests/{$request->id}/submit")->assertNotFound();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->company->id,
            'request_number' => 'DNC-001',
            'request_date' => '2026-08-26',
            'requester_name' => 'Nguyen Van A',
            'department' => 'Ke toan',
            'reason' => 'Thanh toan chi phi van phong',
            'amount' => 1250000,
            'deadline' => '2026-08-30',
        ], $overrides);
    }
}
