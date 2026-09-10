<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Item;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected $company;

    protected $supplier;

    protected $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->company = Company::create([
            'name' => 'Test Company',
            'tax_code' => '123456789',
            'address' => 'Test Address',
        ]);
        $this->configureAccountingTenant($this->user, $this->company);
        // Use the canonical accountant role required by the production
        // posting authorizer; legacy roleless fixtures must not bypass RBAC.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user->assignRole(Role::findOrCreate('accountant', 'web'));
        Sanctum::actingAs($this->user);

        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '156', 'name' => 'Inventory', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '1561', 'name' => 'Goods Inventory', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '331', 'name' => 'Accounts Payable', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '1331', 'name' => 'VAT Deductible', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'S001',
            'name' => 'Test Supplier',
        ]);

        $this->item = Item::create([
            'company_id' => $this->company->id,
            'code' => 'IT001',
            'name' => 'Test Item',
            'type' => 'inventory',
        ]);
    }

    public function test_can_create_purchase_invoice()
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-001',
            'invoice_date' => '2026-08-14',
            'due_date' => '2026-09-14',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'debit_account' => '156',
                    'credit_account' => '331',
                    'quantity' => 10,
                    'unit_price' => 1000,
                    'tax_rate' => 10,
                    'tax_account' => '1331',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/purchase/invoices', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('invoice_number', 'INV-001');

        $this->assertDatabaseHas('purchase_invoices', [
            'invoice_number' => 'INV-001',
        ]);

        $this->assertDatabaseHas('purchase_invoice_lines', [
            'item_id' => $this->item->id,
            'quantity' => 10,
            'unit_price' => 1000,
        ]);
    }

    public function test_can_post_purchase_invoice_to_gl()
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-002',
            'invoice_date' => '2026-08-14',
            'due_date' => '2026-09-14',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'debit_account' => '156',
                    'credit_account' => '331',
                    'quantity' => 10,
                    'unit_price' => 1000,
                    'tax_rate' => 10,
                    'tax_account' => '1331',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/purchase/invoices', $payload);
        $invoiceId = $response->json('id');

        $postResponse = $this->postJson("/api/v1/purchase/invoices/{$invoiceId}/post");
        $postResponse->assertStatus(200);

        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoiceId,
            'is_posted' => true,
        ]);

        $invoice = PurchaseInvoice::find($invoiceId);
        $this->assertNotNull($invoice->journal_entry_id);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $invoice->journal_entry_id,
            'status' => 'posted',
        ]);
    }

    public function test_can_post_purchase_invoice_with_line_discount_and_strictly_balance_gl()
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-DISC-001',
            'invoice_date' => '2026-08-21',
            'due_date' => '2026-09-21',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'quantity' => 10,
                    'unit_price' => 100000,
                    'amount' => 1000000,
                    'discount_rate' => 10,
                    'discount_amount' => 100000, // 100k discount -> Net 900k
                    'tax_rate' => 10,
                    'tax_amount' => 90000, // 10% of 900k net -> 90k
                    'tax_account' => '1331',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/purchase/invoices', $payload);
        $response->assertStatus(201);
        $invoiceId = $response->json('id');

        // Total amount = (1,000,000 - 100,000) + 90,000 = 990,000
        $this->assertEquals(990000, $response->json('total_amount'));

        // Post to GL
        $postResponse = $this->postJson("/api/v1/purchase/invoices/{$invoiceId}/post");
        $postResponse->assertStatus(200);

        $invoice = PurchaseInvoice::with('journalEntry.lines')->find($invoiceId);
        $this->assertTrue($invoice->is_posted);
        $this->assertNotNull($invoice->journal_entry_id);

        $je = $invoice->journalEntry;
        $this->assertEquals('posted', $je->status);

        $sumDebit = $je->lines->sum('debit_amount');
        $sumCredit = $je->lines->sum('credit_amount');

        $this->assertEquals(990000, $sumDebit, 'Total Debit must equal total amount after discount');
        $this->assertEquals(990000, $sumCredit, 'Total Credit must equal total payable');
        $this->assertEquals($sumDebit, $sumCredit, 'Total Debit must strictly equal Total Credit');

        // Check individual line debits & credits
        $debitInventory = $je->lines->firstWhere('account_code', '1561');
        $debitTax = $je->lines->firstWhere('account_code', '1331');
        $creditPayable = $je->lines->firstWhere('account_code', '331');

        $this->assertEquals(900000, $debitInventory->debit_amount, 'Debit 1561 must be net of discount');
        $this->assertEquals(90000, $debitTax->debit_amount, 'Debit 1331 must be VAT amount');
        $this->assertEquals(990000, $creditPayable->credit_amount, 'Credit 331 must be total amount payable');
    }

    public function test_can_void_purchase_invoice()
    {
        $payload = [
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-003',
            'invoice_date' => '2026-08-14',
            'due_date' => '2026-09-14',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'debit_account' => '156',
                    'credit_account' => '331',
                    'quantity' => 10,
                    'unit_price' => 1000,
                    'tax_rate' => 10,
                    'tax_account' => '1331',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/purchase/invoices', $payload);
        $invoiceId = $response->json('id');

        $this->postJson("/api/v1/purchase/invoices/{$invoiceId}/post");

        $voidResponse = $this->postJson("/api/v1/purchase/invoices/{$invoiceId}/void");
        $voidResponse->assertStatus(200);

        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoiceId,
            'is_posted' => false,
        ]);

        $invoice = PurchaseInvoice::find($invoiceId);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $invoice->journal_entry_id,
            'status' => 'voided',
        ]);
    }

    public function test_posted_purchase_invoice_cannot_be_updated_or_deleted(): void
    {
        $invoice = $this->makePostedPurchaseInvoice('INV-IMMUTABLE-POSTED');

        $update = $this->putJson("/api/v1/purchase/invoices/{$invoice->id}", [
            'description' => 'Must not replace posted evidence',
            'lines' => $this->immutablePurchaseLines(),
        ]);
        $delete = $this->deleteJson("/api/v1/purchase/invoices/{$invoice->id}");

        $this->assertSame(409, $update->status());
        $this->assertSame('Không thể sửa hóa đơn đã ghi sổ. Vui lòng bỏ ghi sổ trước khi sửa.', $update->json('error'));
        $this->assertSame(409, $delete->status());
        $this->assertSame('Không thể xóa hóa đơn đã ghi sổ. Vui lòng bỏ ghi sổ trước khi xóa.', $delete->json('error'));
        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoice->id,
            'status' => 'posted',
            'is_posted' => true,
        ]);
    }

    public function test_voided_purchase_invoice_cannot_be_updated_or_deleted(): void
    {
        $invoice = $this->makePostedPurchaseInvoice('INV-IMMUTABLE-VOIDED', 'voided', false);

        $update = $this->putJson("/api/v1/purchase/invoices/{$invoice->id}", [
            'description' => 'Must not replace voided evidence',
            'lines' => $this->immutablePurchaseLines(),
        ]);
        $delete = $this->deleteJson("/api/v1/purchase/invoices/{$invoice->id}");

        $this->assertSame(409, $update->status());
        $this->assertSame('Không thể sửa hóa đơn đã hủy.', $update->json('error'));
        $this->assertSame(409, $delete->status());
        $this->assertSame('Không thể xóa hóa đơn đã hủy.', $delete->json('error'));
        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoice->id,
            'status' => 'voided',
            'is_posted' => false,
        ]);
    }

    public function test_voided_purchase_invoice_cannot_be_posted_again(): void
    {
        $invoice = $this->makePostedPurchaseInvoice('INV-IMMUTABLE-VOIDED-REPOST', 'voided', false);

        $response = $this->postJson("/api/v1/purchase/invoices/{$invoice->id}/post");

        $response->assertStatus(409)
            ->assertJsonPath('error', 'Không thể ghi sổ hóa đơn đã hủy. Hãy nhân bản chứng từ để ghi sổ lại.');
        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoice->id,
            'status' => 'voided',
            'is_posted' => false,
        ]);
    }

    private function makePostedPurchaseInvoice(string $number, string $status = 'posted', bool $isPosted = true): PurchaseInvoice
    {
        $invoice = PurchaseInvoice::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => $number,
            'invoice_date' => '2026-08-14',
            'accounting_date' => '2026-08-14',
            'total_amount' => 1000,
            'status' => $status,
            'is_posted' => $isPosted,
        ]);

        $invoice->lines()->create([
            'item_id' => $this->item->id,
            'description' => 'Immutable purchase line',
            'debit_account' => '156',
            'credit_account' => '331',
            'quantity' => 1,
            'unit_price' => 1000,
            'amount' => 1000,
            'tax_rate' => 0,
            'tax_amount' => 0,
        ]);

        return $invoice;
    }

    private function immutablePurchaseLines(): array
    {
        return [[
            'item_id' => $this->item->id,
            'description' => 'Immutable purchase line',
            'debit_account' => '156',
            'credit_account' => '331',
            'quantity' => 1,
            'unit_price' => 1000,
            'amount' => 1000,
            'tax_rate' => 0,
            'tax_amount' => 0,
        ]];
    }
}
