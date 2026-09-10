<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\JournalEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualJournalProductionMappingBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_manual_post_fails_before_ledger_mutation_without_approved_mapping_resolver(): void
    {
        $company = Company::create([
            'name' => 'Manual journal production boundary',
            'tax_code' => 'MANUAL-JE-PROD',
        ]);
        $actor = User::factory()->create();
        $this->configureAccountingTenant($actor, $company);
        Sanctum::actingAs($actor);

        $fiscalYear = FiscalYear::where('company_id', $company->id)
            ->where('year', 2026)
            ->firstOrFail();
        foreach ([
            ['1111', 'Tiền mặt', 'asset', 'debit'],
            ['5111', 'Doanh thu bán hàng', 'revenue', 'credit'],
        ] as [$code, $name, $type, $nature]) {
            ChartOfAccount::create([
                'company_id' => $company->id,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'nature' => $nature,
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }

        $entry = app(JournalEntryService::class)->create([
            'company_id' => $company->id,
            'fiscal_year_id' => $fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'MANUAL-JE-PROD-001',
            'voucher_date' => '2026-08-24',
            'posting_date' => '2026-08-24',
            'description' => 'Manual journal production boundary',
            'status' => 'draft',
            'lines' => [
                ['account_code' => '1111', 'debit_amount' => 100, 'credit_amount' => 0],
                ['account_code' => '5111', 'debit_amount' => 0, 'credit_amount' => 100],
            ],
        ]);

        Config::set('app.env', 'production');
        try {
            app(JournalEntryService::class)->post($entry->id, $company->id);
            $this->fail('Manual journal posting must remain unavailable without an approved mapping resolver.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('account_mappings', $exception->errors());
        } finally {
            Config::set('app.env', 'testing');
        }

        $this->assertSame('draft', JournalEntry::withoutGlobalScopes()->findOrFail($entry->id)->status);
        $this->assertDatabaseMissing('audit_logs', [
            'auditable_type' => JournalEntry::class,
            'auditable_id' => $entry->id,
            'event' => 'journal.posted',
        ]);
    }

    public function test_public_create_cannot_insert_a_posted_entry_in_production(): void
    {
        $company = Company::create([
            'name' => 'Manual journal create boundary',
            'tax_code' => 'MANUAL-JE-CREATE-PROD',
        ]);
        $actor = User::factory()->create();
        $this->configureAccountingTenant($actor, $company);
        Sanctum::actingAs($actor);

        $fiscalYear = FiscalYear::where('company_id', $company->id)
            ->where('year', 2026)
            ->firstOrFail();
        foreach ([
            ['1111', 'Tiền mặt', 'asset', 'debit'],
            ['5111', 'Doanh thu bán hàng', 'revenue', 'credit'],
        ] as [$code, $name, $type, $nature]) {
            ChartOfAccount::create([
                'company_id' => $company->id,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'nature' => $nature,
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }

        Config::set('app.env', 'production');
        try {
            app(JournalEntryService::class)->create([
                'company_id' => $company->id,
                'fiscal_year_id' => $fiscalYear->id,
                'voucher_type' => 'general_journal',
                'voucher_number' => 'MANUAL-JE-CREATE-PROD-001',
                'voucher_date' => '2026-08-24',
                'posting_date' => '2026-08-24',
                'description' => 'Direct posted create must fail closed',
                'status' => 'posted',
                'lines' => [
                    ['account_code' => '1111', 'debit_amount' => 100, 'credit_amount' => 0],
                    ['account_code' => '5111', 'debit_amount' => 0, 'credit_amount' => 100],
                ],
            ]);
            $this->fail('Public create must not insert posted accounting history in production.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        } finally {
            Config::set('app.env', 'testing');
        }

        $this->assertDatabaseMissing('journal_entries', [
            'company_id' => $company->id,
            'voucher_number' => 'MANUAL-JE-CREATE-PROD-001',
        ]);
    }
}
