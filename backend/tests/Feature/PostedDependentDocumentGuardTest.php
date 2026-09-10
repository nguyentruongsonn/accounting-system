<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\User;
use App\Services\JournalEntryService;
use App\Services\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PostedDependentDocumentGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_posted_invoice_cannot_be_unposted_when_a_posted_return_depends_on_it(): void
    {
        $company = Company::create(['name' => 'Dependent document tenant']);
        $actor = User::factory()->create(['company_id' => $company->id]);
        $actor->assignRole(Role::findOrCreate('admin', 'web'));
        Sanctum::actingAs($actor);

        $customer = Customer::create([
            'company_id' => $company->id,
            'code' => 'CUS-DEPENDENCY',
            'name' => 'Khách hàng phụ thuộc',
        ]);

        $invoice = SalesInvoice::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'invoice_number' => 'HD-DEPENDENCY-001',
            'invoice_date' => '2026-08-20',
            'accounting_date' => '2026-08-20',
            'total_amount' => 1000,
            'is_posted' => true,
            'status' => 'posted',
        ]);

        $return = SalesReturn::create([
            'company_id' => $company->id,
            'voucher_number' => 'TL-DEPENDENCY-001',
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'reference_invoice_id' => $invoice->id,
            'total_amount' => 100,
            'is_posted' => true,
            'status' => 'posted',
        ]);
        $return->syncReferences([[
            'target_type' => SalesInvoice::class,
            'target_id' => $invoice->id,
            'voucher_type' => 'Hàng bán bị trả lại',
            'voucher_number' => $invoice->invoice_number,
            'voucher_date' => $invoice->invoice_date->toDateString(),
            'total_amount' => $invoice->total_amount,
        ]]);

        try {
            app(SalesInvoiceService::class)->unpost($invoice->id);
            $this->fail('A posted invoice with a posted dependent must be blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dependent_documents', $exception->errors());
            $this->assertStringContainsString('TL-DEPENDENCY-001', implode(' ', $exception->errors()['dependent_documents']));
        }

        $invoice->refresh();
        $this->assertTrue((bool) $invoice->is_posted);
        $this->assertSame('posted', $invoice->status);
        $this->assertDatabaseHas('voucher_references', [
            'source_type' => SalesReturn::class,
            'source_id' => $return->id,
            'target_type' => SalesInvoice::class,
            'target_id' => $invoice->id,
        ]);
    }

    public function test_posted_journal_entry_cannot_be_voided_when_a_posted_journal_depends_on_it(): void
    {
        $company = Company::create(['name' => 'Journal dependency tenant']);
        $actor = User::factory()->create(['company_id' => $company->id]);
        $actor->assignRole(Role::findOrCreate('admin', 'web'));
        Sanctum::actingAs($actor);

        $fiscalYear = FiscalYear::create([
            'company_id' => $company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
            'created_by' => $actor->id,
        ]);

        $source = JournalEntry::create([
            'company_id' => $company->id,
            'fiscal_year_id' => $fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'GJ-DEPENDENCY-001',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'description' => 'Bút toán nguồn',
            'total_amount' => 1000,
            'status' => 'posted',
        ]);

        $dependent = JournalEntry::create([
            'company_id' => $company->id,
            'fiscal_year_id' => $fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'GJ-DEPENDENCY-002',
            'voucher_date' => '2026-08-21',
            'posting_date' => '2026-08-21',
            'description' => 'Bút toán phụ thuộc',
            'total_amount' => 1000,
            'status' => 'posted',
        ]);
        $dependent->syncReferences([[
            'target_type' => JournalEntry::class,
            'target_id' => $source->id,
            'voucher_type' => 'Bút toán liên quan',
            'voucher_number' => $source->voucher_number,
            'voucher_date' => '2026-08-20',
            'total_amount' => 1000,
        ]]);

        $this->expectException(ValidationException::class);
        try {
            app(JournalEntryService::class)->void($source->id, $company->id);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dependent_documents', $exception->errors());
            $this->assertStringContainsString('GJ-DEPENDENCY-002', implode(' ', $exception->errors()['dependent_documents']));
            throw $exception;
        }
    }
}
