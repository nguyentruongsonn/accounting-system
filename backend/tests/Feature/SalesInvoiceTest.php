<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\SalesInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceTest extends TestCase
{
    use RefreshDatabase;

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
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user->assignRole(Role::findOrCreate('accountant', 'web'));
        Sanctum::actingAs($this->user);

        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '131', 'name' => 'Accounts Receivable', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '511', 'name' => 'Revenue', 'type' => 'revenue', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '3331', 'name' => 'VAT Payable', 'type' => 'liability', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '33311', 'name' => 'Output VAT Payable', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '156', 'name' => 'Inventory', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '632', 'name' => 'COGS', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'C001',
            'name' => 'Test Customer',
        ]);

        $this->item = Item::create([
            'company_id' => $this->company->id,
            'code' => 'IT001',
            'name' => 'Test Item',
            'type' => 'inventory',
            'cost_price' => 500,
            'sales_price' => 1000,
        ]);
    }

    public function test_can_create_sales_invoice()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'SI-001',
            'invoice_date' => '2026-08-14',
            'accounting_date' => '2026-08-14',
            'due_date' => '2026-09-14',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'debit_account' => '131',
                    'credit_account' => '511',
                    'quantity' => 5,
                    'unit_price' => 1000,
                    'tax_rate' => 10,
                    'tax_account' => '3331',
                    'inventory_account' => '156',
                    'cogs_account' => '632',
                    'cogs_price' => 500,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/sales/invoices', $payload);
        $response->dump();
        $response->assertStatus(201)
            ->assertJsonPath('data.invoice_number', 'SI-001');

        $this->assertDatabaseHas('sales_invoices', [
            'invoice_number' => 'SI-001',
        ]);

        $this->assertDatabaseHas('sales_invoice_lines', [
            'item_id' => $this->item->id,
            'quantity' => 5,
            'unit_price' => 1000,
        ]);
    }

    public function test_can_post_sales_invoice_to_gl()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'SI-002',
            'invoice_date' => '2026-08-14',
            'accounting_date' => '2026-08-14',
            'due_date' => '2026-09-14',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'debit_account' => '131',
                    'credit_account' => '511',
                    'quantity' => 5,
                    'unit_price' => 1000,
                    'tax_rate' => 10,
                    'tax_account' => '3331',
                    'inventory_account' => '156',
                    'cogs_account' => '632',
                    'cogs_price' => 500,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/sales/invoices', $payload);
        $invoiceId = $response->json('data.id');

        $postResponse = $this->postJson("/api/v1/sales/invoices/{$invoiceId}/post");
        $postResponse->assertStatus(200);

        $this->assertDatabaseHas('sales_invoices', [
            'id' => $invoiceId,
            'is_posted' => true,
        ]);

        $invoice = SalesInvoice::find($invoiceId);
        $this->assertNotNull($invoice->journal_entry_id);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $invoice->journal_entry_id,
            'status' => 'posted',
        ]);
    }

    /**
     * A fully discounted/free-sample sales invoice is an explicit zero-net
     * exception: it may be marked posted for source-document/audit purposes,
     * but must not manufacture an all-zero journal entry.
     */
    public function test_zero_net_sales_invoice_records_posted_without_journal_evidence(): void
    {
        $invoice = app(\App\Services\SalesInvoiceService::class)->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'SI-FREE-001',
            'invoice_date' => '2026-08-14',
            'accounting_date' => '2026-08-14',
            'lines' => [[
                'item_id' => $this->item->id,
                'debit_account' => '131',
                'credit_account' => '511',
                'quantity' => 1,
                'unit_price' => 1000,
                'discount_rate' => 100,
                'discount_amount' => 1000,
                'tax_rate' => 0,
                'tax_amount' => 0,
            ]],
        ]);

        $this->assertEquals(0, $invoice->total_amount);

        $posted = app(\App\Services\SalesInvoiceService::class)->post($invoice->id);

        $this->assertTrue((bool) $posted->is_posted);
        $this->assertSame('posted', $posted->status);
        $this->assertNull($posted->journal_entry_id);
        $this->assertDatabaseMissing('journal_entries', [
            'source_document_type' => SalesInvoice::class,
            'source_document_id' => $invoice->id,
            'status' => 'posted',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $this->company->id,
            'model_type' => $posted->getMorphClass(),
            'model_id' => $posted->id,
            'action' => 'sales_invoice.posted_without_journal',
        ]);
    }

    public function test_can_void_sales_invoice()
    {
        $payload = [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'SI-003',
            'invoice_date' => '2026-08-14',
            'accounting_date' => '2026-08-14',
            'due_date' => '2026-09-14',
            'lines' => [
                [
                    'item_id' => $this->item->id,
                    'debit_account' => '131',
                    'credit_account' => '511',
                    'quantity' => 5,
                    'unit_price' => 1000,
                    'tax_rate' => 10,
                    'tax_account' => '3331',
                    'inventory_account' => '156',
                    'cogs_account' => '632',
                    'cogs_price' => 500,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/sales/invoices', $payload);
        $invoiceId = $response->json('data.id');

        $this->postJson("/api/v1/sales/invoices/{$invoiceId}/post");

        $voidResponse = $this->postJson("/api/v1/sales/invoices/{$invoiceId}/void");
        $voidResponse->assertStatus(200);

        $this->assertDatabaseHas('sales_invoices', [
            'id' => $invoiceId,
            'is_posted' => false,
        ]);

        $invoice = SalesInvoice::find($invoiceId);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $invoice->journal_entry_id,
            'status' => 'voided',
        ]);
    }

    public function test_posted_sales_invoice_cannot_be_updated_or_deleted(): void
    {
        $invoice = $this->makePostedSalesInvoice('SI-IMMUTABLE-POSTED');

        $update = $this->putJson("/api/v1/sales/invoices/{$invoice->id}", [
            'description' => 'Must not replace posted evidence',
            'lines' => $this->immutableSalesLines(),
        ]);
        $delete = $this->deleteJson("/api/v1/sales/invoices/{$invoice->id}");

        $this->assertSame(409, $update->status());
        $this->assertSame('Không thể sửa hóa đơn đã ghi sổ. Vui lòng bỏ ghi sổ trước khi sửa.', $update->json('error'));
        $this->assertSame(409, $delete->status());
        $this->assertSame('Không thể xóa hóa đơn đã ghi sổ. Vui lòng bỏ ghi sổ trước khi xóa.', $delete->json('error'));
        $this->assertDatabaseHas('sales_invoices', [
            'id' => $invoice->id,
            'status' => 'posted',
            'is_posted' => true,
        ]);
    }

    public function test_voided_sales_invoice_cannot_be_updated_or_deleted(): void
    {
        $invoice = $this->makePostedSalesInvoice('SI-IMMUTABLE-VOIDED', 'voided', false);

        $update = $this->putJson("/api/v1/sales/invoices/{$invoice->id}", [
            'description' => 'Must not replace voided evidence',
            'lines' => $this->immutableSalesLines(),
        ]);
        $delete = $this->deleteJson("/api/v1/sales/invoices/{$invoice->id}");

        $this->assertSame(409, $update->status());
        $this->assertSame('Không thể sửa hóa đơn đã hủy.', $update->json('error'));
        $this->assertSame(409, $delete->status());
        $this->assertSame('Không thể xóa hóa đơn đã hủy.', $delete->json('error'));
        $this->assertDatabaseHas('sales_invoices', [
            'id' => $invoice->id,
            'status' => 'voided',
            'is_posted' => false,
        ]);
    }

    public function test_voided_sales_invoice_cannot_be_posted_again(): void
    {
        $invoice = $this->makePostedSalesInvoice('SI-IMMUTABLE-VOIDED-REPOST', 'voided', false);

        $response = $this->postJson("/api/v1/sales/invoices/{$invoice->id}/post");

        $response->assertStatus(409)
            ->assertJsonPath('error', 'Không thể ghi sổ hóa đơn đã hủy. Hãy nhân bản chứng từ để ghi sổ lại.');
        $this->assertDatabaseHas('sales_invoices', [
            'id' => $invoice->id,
            'status' => 'voided',
            'is_posted' => false,
        ]);
    }

    private function makePostedSalesInvoice(string $number, string $status = 'posted', bool $isPosted = true): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => $number,
            'invoice_date' => '2026-08-14',
            'accounting_date' => '2026-08-14',
            'total_amount' => 1000,
            'status' => $status,
            'is_posted' => $isPosted,
        ]);

        $invoice->lines()->create([
            'item_id' => $this->item->id,
            'description' => 'Immutable sales line',
            'debit_account' => '131',
            'credit_account' => '511',
            'inventory_account' => '156',
            'cogs_account' => '632',
            'cogs_debit_account' => '632',
            'cogs_credit_account' => '156',
            'quantity' => 1,
            'unit_price' => 1000,
            'amount' => 1000,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'cogs_price' => 500,
            'cogs_unit_price' => 500,
            'cogs_amount' => 500,
        ]);

        return $invoice;
    }

    private function immutableSalesLines(): array
    {
        return [[
            'item_id' => $this->item->id,
            'description' => 'Immutable sales line',
            'debit_account' => '131',
            'credit_account' => '511',
            'inventory_account' => '156',
            'cogs_account' => '632',
            'cogs_debit_account' => '632',
            'cogs_credit_account' => '156',
            'quantity' => 1,
            'unit_price' => 1000,
            'amount' => 1000,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'cogs_price' => 500,
            'cogs_unit_price' => 500,
            'cogs_amount' => 500,
        ]];
    }
}
