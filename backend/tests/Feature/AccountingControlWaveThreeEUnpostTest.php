<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\User;
use App\Services\AuditService;
use App\Services\JournalEntryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class AccountingControlWaveThreeEUnpostTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private FiscalYear $fiscalYear;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::findOrFail(1);
        $this->fiscalYear = FiscalYear::where('company_id', $this->company->id)->firstOrFail();
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        Sanctum::actingAs($this->user);

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

    public function test_closed_period_rejects_unpost_and_void(): void
    {
        $unpostEntry = $this->postedEntry('UNPOST-CLOSED');
        $voidEntry = $this->postedEntry('VOID-CLOSED');
        Period::create([
            'fiscal_year_id' => $this->fiscalYear->id,
            'period' => 8,
            'period_number' => 8,
            'name' => 'Tháng 08/2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => 'closed',
            'is_closed' => true,
        ]);

        foreach ([['unpost', $unpostEntry], ['void', $voidEntry]] as [$method, $entry]) {
            try {
                app(JournalEntryService::class)->{$method}($entry->id);
                $this->fail("Expected {$method} to fail in a closed period.");
            } catch (ConflictHttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
            $this->assertSame('posted', $entry->fresh()->status);
        }
    }

    public function test_audit_failure_rolls_back_unpost(): void
    {
        $entry = $this->postedEntry('UNPOST-AUDIT-ROLLBACK');
        $this->app->instance(AuditService::class, new class extends AuditService
        {
            public function record(
                Model $model,
                string $action,
                array $before = [],
                array $after = [],
                ?string $correlationId = null,
                array $metadata = []
            ): AuditLog {
                throw new RuntimeException('Audit unavailable');
            }
        });

        try {
            app(JournalEntryService::class)->unpost($entry->id);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }

        $this->assertSame('posted', $entry->fresh()->status);
    }

    public function test_unpost_is_idempotent_but_draft_transition_is_rejected(): void
    {
        $posted = $this->postedEntry('UNPOST-IDEMPOTENT');
        $service = app(JournalEntryService::class);

        $this->assertSame('voided', $service->unpost($posted->id)->status);
        $this->assertSame('voided', $service->unpost($posted->id)->status);
        $this->assertSame(1, AuditLog::where('model_id', $posted->id)
            ->where('action', 'journal.unposted')->count());

        $draft = $service->create($this->entryPayload('DRAFT-INVALID', 'draft'));
        $this->expectException(ConflictHttpException::class);
        $service->unpost($draft->id);
    }

    public function test_other_tenant_cannot_void_entry(): void
    {
        $otherCompany = Company::create(['name' => 'Other tenant', 'tax_code' => 'OTHER-VOID']);
        $otherYear = FiscalYear::create([
            'company_id' => $otherCompany->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        $foreign = JournalEntry::withoutGlobalScope('company')->create([
            'company_id' => $otherCompany->id,
            'fiscal_year_id' => $otherYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'FOREIGN-VOID',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Foreign entry',
            'total_amount' => 100,
            'status' => 'posted',
        ]);

        $this->expectException(ModelNotFoundException::class);
        app(JournalEntryService::class)->void($foreign->id);
    }

    private function postedEntry(string $voucherNumber): JournalEntry
    {
        return app(JournalEntryService::class)->createPosted($this->entryPayload($voucherNumber, 'posted'));
    }

    private function entryPayload(string $voucherNumber, string $status): array
    {
        return [
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => $voucherNumber,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Wave 3E transition test',
            'status' => $status,
            'lines' => [
                ['account_code' => '1111', 'debit_amount' => 100, 'credit_amount' => 0],
                ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => 100],
            ],
        ];
    }
}
