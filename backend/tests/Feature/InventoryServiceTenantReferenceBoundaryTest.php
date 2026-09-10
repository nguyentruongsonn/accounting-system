<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryIssueService;
use App\Services\InventoryReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryServiceTenantReferenceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $foreignCompany;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Inventory service A', 'tax_code' => 'INV-SVC-A']);
        $this->foreignCompany = Company::create(['name' => 'Inventory service B', 'tax_code' => 'INV-SVC-B']);
        $actor = User::factory()->create();
        $this->configureAccountingTenant($actor, $this->company);
        Sanctum::actingAs($actor);

        $this->item = Item::create([
            'company_id' => $this->company->id,
            'code' => 'INV-SVC-ITEM',
            'name' => 'Inventory service item',
            'type' => 'goods',
            'inventory_account' => '1561',
        ]);
    }

    public function test_direct_inventory_services_normalize_codes_and_reject_foreign_contacts(): void
    {
        $supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'INV-SVC-SUP',
            'name' => 'Local supplier',
        ]);
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'INV-SVC-CUS',
            'name' => 'Local customer',
        ]);

        $receipt = app(InventoryReceiptService::class)->create($this->receiptPayload([
            'contact_type' => 'supplier',
            'contact_id' => $supplier->code,
        ]));
        $this->assertSame((int) $supplier->id, (int) $receipt->contact_id);

        $issue = app(InventoryIssueService::class)->create($this->issuePayload([
            'contact_type' => 'customer',
            'contact_id' => $customer->code,
        ]));
        $this->assertSame((int) $customer->id, (int) $issue->contact_id);

        $foreignSupplier = Supplier::withoutGlobalScopes()->create([
            'company_id' => $this->foreignCompany->id,
            'code' => 'INV-SVC-FOREIGN-SUP',
            'name' => 'Foreign supplier',
        ]);
        try {
            app(InventoryReceiptService::class)->create($this->receiptPayload([
                'contact_type' => 'supplier',
                'contact_id' => $foreignSupplier->id,
            ]));
            $this->fail('A direct inventory service must reject a foreign supplier reference.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('contact_id', $exception->errors());
        }

        $foreignCustomer = Customer::withoutGlobalScopes()->create([
            'company_id' => $this->foreignCompany->id,
            'code' => 'INV-SVC-FOREIGN-CUS',
            'name' => 'Foreign customer',
        ]);
        try {
            app(InventoryIssueService::class)->create($this->issuePayload([
                'contact_type' => 'customer',
                'contact_id' => $foreignCustomer->id,
            ]));
            $this->fail('A direct inventory service must reject a foreign customer reference.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('contact_id', $exception->errors());
        }
    }

    private function receiptPayload(array $overrides = []): array
    {
        return [...[
            'company_id' => $this->company->id,
            'voucher_number' => 'INV-SVC-REC-'.uniqid(),
            'voucher_date' => '2026-08-23',
            'posting_date' => '2026-08-23',
            'lines' => [[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ], ...$overrides];
    }

    private function issuePayload(array $overrides = []): array
    {
        return [...[
            'company_id' => $this->company->id,
            'voucher_number' => 'INV-SVC-ISS-'.uniqid(),
            'voucher_date' => '2026-08-23',
            'posting_date' => '2026-08-23',
            'lines' => [[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ], ...$overrides];
    }
}
