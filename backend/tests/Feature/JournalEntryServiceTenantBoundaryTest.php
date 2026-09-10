<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\JournalEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class JournalEntryServiceTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private FiscalYear $fiscalYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::findOrFail(1);
        $this->fiscalYear = FiscalYear::where('company_id', $this->company->id)->firstOrFail();
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->grantGlReportPermissions($user);
        Sanctum::actingAs($user);

        foreach ([
            ['code' => '1111', 'name' => 'Tiền mặt', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '511', 'name' => 'Doanh thu', 'type' => 'revenue', 'nature' => 'credit'],
        ] as $account) {
            ChartOfAccount::updateOrCreate(
                ['company_id' => $this->company->id, 'code' => $account['code']],
                [...$account, 'level' => 1, 'is_parent' => false, 'is_active' => true]
            );
        }
    }

    public function test_direct_service_read_and_mutation_paths_reject_a_foreign_entry(): void
    {
        $foreignCompany = Company::create(['name' => 'Foreign journal tenant', 'tax_code' => 'JOURNAL-FOREIGN']);
        $foreignYear = FiscalYear::create([
            'company_id' => $foreignCompany->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        $foreign = JournalEntry::withoutGlobalScope('company')->create([
            'company_id' => $foreignCompany->id,
            'fiscal_year_id' => $foreignYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'FOREIGN-JE-BOUNDARY',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Foreign entry',
            'total_amount' => 100,
            'status' => 'draft',
        ]);

        $service = app(JournalEntryService::class);

        foreach ([
            fn () => $service->getById($foreign->id, $this->company->id),
            fn () => $service->update($foreign->id, ['posting_date' => '2026-08-15'], $this->company->id),
            fn () => $service->post($foreign->id, $this->company->id),
            fn () => $service->duplicate($foreign->id, $this->company->id),
            fn () => $service->delete($foreign->id, $this->company->id),
        ] as $operation) {
            try {
                $operation();
                $this->fail('A journal operation crossed the tenant boundary.');
            } catch (ModelNotFoundException|ValidationException $exception) {
                if ($exception instanceof ValidationException) {
                    $this->assertArrayHasKey('company_id', $exception->errors());
                } else {
                    $this->assertTrue(true, 'Foreign journal is hidden by the tenant-scoped query.');
                }
            }
        }

        $this->assertSame('draft', $foreign->fresh()->status);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $foreign->id,
            'company_id' => $foreignCompany->id,
            'status' => 'draft',
        ]);
    }

    public function test_service_requires_trusted_context_when_no_authenticated_tenant_exists(): void
    {
        $entry = JournalEntry::create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'NO-ACTOR-JE',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Context boundary',
            'total_amount' => 0,
            'status' => 'draft',
        ]);

        Auth::forgetGuards();
        $this->expectException(ValidationException::class);
        app(JournalEntryService::class)->getById($entry->id);
    }

    public function test_voided_entry_cannot_be_posted_again(): void
    {
        $entry = $this->createDraft('VOIDED-NO-REPOST');
        $service = app(JournalEntryService::class);
        $service->post($entry->id, $this->company->id);
        $service->void($entry->id, $this->company->id);

        $this->expectException(ConflictHttpException::class);
        $service->post($entry->id, $this->company->id);
    }

    private function createDraft(string $voucherNumber): JournalEntry
    {
        $entry = app(JournalEntryService::class)->create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => $voucherNumber,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Tenant boundary test',
            'lines' => [
                ['account_code' => '1111', 'debit_amount' => 100, 'credit_amount' => 0],
                ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => 100],
            ],
        ]);

        return $entry->fresh('lines');
    }
}
