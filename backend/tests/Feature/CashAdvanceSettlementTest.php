<?php

namespace Tests\Feature;

use App\Models\CashAdvanceSettlement;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashAdvanceSettlementTest extends TestCase
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

    public function test_can_create_and_submit_draft_without_voucher_link(): void
    {
        $response = $this->postJson('/api/v1/cash/advance-settlements', $this->payload());
        $response->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.refund_amount', '250000.00');
        $id = $response->json('data.id');
        $this->postJson("/api/v1/cash/advance-settlements/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->assertDatabaseHas('cash_advance_settlements', ['id' => $id, 'status' => 'submitted', 'extra_amount' => 0]);
    }

    public function test_draft_can_be_updated_and_deleted(): void
    {
        $request = CashAdvanceSettlement::create($this->payload());
        $this->putJson("/api/v1/cash/advance-settlements/{$request->id}", $this->payload(['actual_spent' => 1200000]))->assertOk()->assertJsonPath('data.extra_amount', '200000.00');
        $this->deleteJson("/api/v1/cash/advance-settlements/{$request->id}")->assertOk();
        $this->assertDatabaseMissing('cash_advance_settlements', ['id' => $request->id]);
    }

    public function test_foreign_settlement_is_not_visible(): void
    {
        $foreign = Company::create(['name' => 'Foreign advance', 'tax_code' => 'FOREIGN-ADV-001', 'address' => 'B']);
        $request = CashAdvanceSettlement::create(array_replace($this->payload(), ['company_id' => $foreign->id]));
        $this->getJson("/api/v1/cash/advance-settlements/{$request->id}")->assertNotFound();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['company_id' => $this->company->id, 'settlement_number' => 'QT-001', 'settlement_date' => '2026-08-26', 'employee_name' => 'Nguyen Van B', 'department' => 'Hanh chinh', 'advance_amount' => 1000000, 'actual_spent' => 750000, 'reason' => 'Quyet toan chi phi cong tac'], $overrides);
    }
}
