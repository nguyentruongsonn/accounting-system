<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PurchaseReturn;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseSalesCommercialBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Supplier $supplier;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Commercial boundary test company',
            'tax_code' => 'BOUNDARY-'.uniqid(),
            'address' => 'Test address',
        ]);
        $this->user->update(['company_id' => $this->company->id]);
        $this->configureAccountingTenant($this->user, $this->company);
        Sanctum::actingAs($this->user);

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'SUP-BOUNDARY',
            'name' => 'Boundary supplier',
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'CUS-BOUNDARY',
            'name' => 'Boundary customer',
        ]);
    }

    public function test_create_intent_cannot_escalate_to_post_without_post_permission(): void
    {
        $this->user->revokePermissionTo('purchase.returns.post');
        $before = PurchaseReturn::count();

        $this->postJson('/api/v1/purchase/returns', [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'voucher_number' => 'BOUNDARY-RETURN-001',
            'voucher_date' => '2026-08-23',
            'status' => 'posted',
            'lines' => [[
                'quantity' => 1,
                'unit_price' => 100,
                'amount' => 100,
            ]],
        ])->assertForbidden();

        $this->assertSame($before, PurchaseReturn::count());
    }

    public function test_draft_sources_require_detail_lines_and_party_identity(): void
    {
        $this->postJson('/api/v1/purchase/returns', [
            'company_id' => $this->company->id,
            'voucher_number' => 'BOUNDARY-RETURN-002',
            'voucher_date' => '2026-08-23',
            'lines' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['supplier_id', 'lines']);

        $this->postJson('/api/v1/sales/invoices', [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-08-23',
            'lines' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['lines']);
    }

    public function test_update_cannot_turn_a_draft_into_a_posted_source(): void
    {
        $created = $this->postJson('/api/v1/sales/returns', [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'voucher_number' => 'BOUNDARY-RETURN-003',
            'voucher_date' => '2026-08-23',
            'lines' => [[
                'quantity' => 1,
                'unit_price' => 100,
                'amount' => 100,
            ]],
        ])->assertCreated();

        $id = (int) $created->json('data.id');
        $this->putJson("/api/v1/sales/returns/{$id}", ['status' => 'posted'])
            ->assertForbidden();

        $this->assertDatabaseHas('sales_returns', [
            'id' => $id,
            'is_posted' => false,
            'status' => 'draft',
        ]);
        $this->assertNull(SalesReturn::query()->findOrFail($id)->journal_entry_id);
    }
}
