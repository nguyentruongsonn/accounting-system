<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\BankPayment;
use App\Models\BankReceipt;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\InventoryIssue;
use App\Models\InventoryReceipt;
use App\Models\Item;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditService;
use App\Services\BankPaymentService;
use App\Services\BankReceiptService;
use App\Services\CashPaymentService;
use App\Services\CashReceiptService;
use App\Services\InventoryIssueService;
use App\Services\InventoryReceiptService;
use App\Services\JournalEntryService;
use App\Services\Concerns\RecordsSourceAudit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SourceJournalAtomicityFailureInjectionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Item $item;

    private Warehouse $warehouse;

    private BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::findOrFail(1);
        $actor = User::factory()->create(['company_id' => $this->company->id]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor->assignRole(Role::findOrCreate('accountant', 'web'));
        Sanctum::actingAs($actor);

        foreach ([
            ['code' => '1111', 'name' => 'Tiền mặt', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1121', 'name' => 'Tiền gửi ngân hàng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '131', 'name' => 'Phải thu khách hàng', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '1561', 'name' => 'Giá mua hàng hóa', 'type' => 'asset', 'nature' => 'debit'],
            ['code' => '331', 'name' => 'Phải trả người bán', 'type' => 'liability', 'nature' => 'credit'],
            ['code' => '632', 'name' => 'Giá vốn hàng bán', 'type' => 'expense', 'nature' => 'debit'],
        ] as $account) {
            ChartOfAccount::updateOrCreate(
                ['company_id' => $this->company->id, 'code' => $account['code']],
                [...$account, 'level' => 2, 'is_parent' => false, 'is_active' => true]
            );
        }

        $this->item = Item::create([
            'company_id' => $this->company->id,
            'type' => 'Goods',
            'code' => 'ATOMIC-ITEM',
            'name' => 'Atomicity item',
            'unit' => 'Cái',
            'inventory_account' => '1561',
            'is_active' => true,
        ]);
        $this->warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'ATOMIC-WAREHOUSE',
            'name' => 'Atomicity warehouse',
            'default_account' => '1561',
        ]);
        // Seed one posted receipt so the inventory-issue cases reach the
        // injected journal/audit failure points instead of being rejected by
        // the normal insufficient-stock guard.
        $openingReceipt = InventoryReceipt::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'voucher_number' => 'ATOMIC-OPENING-001',
            'voucher_type' => '1. Nhập kho mua hàng',
            'voucher_date' => '2026-08-01',
            'posting_date' => '2026-08-01',
            'description' => 'Atomicity opening stock',
            'total_amount' => 100000,
            'status' => 'posted',
            'is_posted' => true,
        ]);
        $openingReceipt->lines()->create([
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'description' => 'Atomicity opening stock',
            'quantity' => 1,
            'unit_price' => 100000,
            'amount' => 100000,
            'debit_account' => '1561',
            'credit_account' => '331',
        ]);
        $this->bankAccount = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => 'ATOMIC-1121',
            'bank_name' => 'Atomicity Bank',
            'is_active' => true,
        ]);
    }

    public function test_post_rolls_back_source_and_journal_when_journal_audit_fails_for_all_six_services(): void
    {
        foreach ($this->serviceCases() as $name => $case) {
            $source = $this->makeSource($case, "POST-{$name}");
            $journalCount = JournalEntry::count();
            $lineCount = JournalEntryLine::count();
            $this->failAuditWrites();

            try {
                app($case['service'])->post($source->id);
                $this->fail("{$name}: expected injected journal audit failure during post");
            } catch (RuntimeException $exception) {
                $this->assertSame('Injected audit failure', $exception->getMessage(), $name);
            }

            $fresh = $source->fresh();
            $this->assertFalse($fresh->is_posted, "{$name}: source posting flag changed");
            $this->assertSame('draft', $fresh->status, "{$name}: source status changed");
            $this->assertNull($fresh->journal_entry_id, "{$name}: source retained a JE link");
            $this->assertSame($journalCount, JournalEntry::count(), "{$name}: orphan JE survived rollback");
            $this->assertSame($lineCount, JournalEntryLine::count(), "{$name}: orphan JE lines survived rollback");
            $this->restoreAuditWrites();
        }
    }

    public function test_post_keeps_source_unchanged_when_journal_creation_throws_for_all_six_services(): void
    {
        foreach ($this->serviceCases() as $name => $case) {
            $source = $this->makeSource($case, "CREATE-FAIL-{$name}");
            $journalCount = JournalEntry::count();
            $lineCount = JournalEntryLine::count();
            $this->failJournalMethod('createPosted');

            try {
                app($case['service'])->post($source->id);
                $this->fail("{$name}: expected injected JE creation failure");
            } catch (RuntimeException $exception) {
                $this->assertSame('Injected journal failure', $exception->getMessage(), $name);
            }

            $fresh = $source->fresh();
            $this->assertFalse($fresh->is_posted, "{$name}: source posting flag changed");
            $this->assertSame('draft', $fresh->status, "{$name}: source status changed");
            $this->assertNull($fresh->journal_entry_id, "{$name}: source retained a JE link");
            $this->assertSame($journalCount, JournalEntry::count(), "{$name}: JE count changed");
            $this->assertSame($lineCount, JournalEntryLine::count(), "{$name}: JE line count changed");
            $this->restoreJournalService();
        }
    }

    public function test_unpost_rolls_back_source_and_existing_journal_when_journal_audit_fails_for_all_six_services(): void
    {
        foreach ($this->serviceCases() as $name => $case) {
            $source = $this->makeSource($case, "UNPOST-{$name}");
            $posted = app($case['service'])->post($source->id)->fresh();
            $journal = JournalEntry::with('lines')->findOrFail($posted->journal_entry_id);
            $sourceBefore = $posted->only(['status', 'is_posted', 'journal_entry_id']);
            $journalStatus = $journal->status;
            $linesBefore = $journal->lines->map->only(['account_code', 'debit_amount', 'credit_amount'])->all();
            $this->failAuditWrites();

            try {
                app($case['service'])->unpost($posted->id);
                $this->fail("{$name}: expected injected journal audit failure during unpost");
            } catch (RuntimeException $exception) {
                $this->assertSame('Injected audit failure', $exception->getMessage(), $name);
            }

            $this->assertSame($sourceBefore, $posted->fresh()->only(['status', 'is_posted', 'journal_entry_id']), "{$name}: source changed");
            $this->assertSame($journalStatus, $journal->fresh()->status, "{$name}: JE status changed");
            $this->assertSame(
                $linesBefore,
                $journal->fresh('lines')->lines->map->only(['account_code', 'debit_amount', 'credit_amount'])->all(),
                "{$name}: JE lines changed"
            );
            $this->restoreAuditWrites();
        }
    }

    public function test_unpost_keeps_source_and_journal_unchanged_when_journal_void_throws_for_all_six_services(): void
    {
        foreach ($this->serviceCases() as $name => $case) {
            $source = $this->makeSource($case, "VOID-FAIL-{$name}");
            $posted = app($case['service'])->post($source->id)->fresh();
            $journal = JournalEntry::with('lines')->findOrFail($posted->journal_entry_id);
            $sourceBefore = $posted->only(['status', 'is_posted', 'journal_entry_id']);
            $journalStatus = $journal->status;
            $linesBefore = $journal->lines->map->only(['account_code', 'debit_amount', 'credit_amount'])->all();
            $this->failJournalMethod('void');

            try {
                app($case['service'])->unpost($posted->id);
                $this->fail("{$name}: expected injected JE void failure");
            } catch (RuntimeException $exception) {
                $this->assertSame('Injected journal failure', $exception->getMessage(), $name);
            }

            $this->assertSame($sourceBefore, $posted->fresh()->only(['status', 'is_posted', 'journal_entry_id']), "{$name}: source changed");
            $this->assertSame($journalStatus, $journal->fresh()->status, "{$name}: JE status changed");
            $this->assertSame(
                $linesBefore,
                $journal->fresh('lines')->lines->map->only(['account_code', 'debit_amount', 'credit_amount'])->all(),
                "{$name}: JE lines changed"
            );
            $this->restoreJournalService();
        }
    }

    public function test_source_audit_records_lifecycle_event_classes_for_all_six_services(): void
    {
        foreach ($this->serviceCases() as $name => $case) {
            $service = app($case['service']);
            $source = $service->create($this->createPayload($name, $case));
            $this->assertSourceAudit($source, "{$name}.created");

            $source = $service->update($source->id, ['description' => 'Updated atomicity source']);
            $this->assertSourceAudit($source, "{$name}.updated");

            $duplicate = $service->duplicate($source->id);
            $this->assertSourceAudit($duplicate, "{$name}.duplicated");

            $source = $service->post($source->id)->fresh();
            $postedAudit = $this->assertSourceAudit($source, "{$name}.posted");
            $this->assertSame($source->journal_entry_id, $postedAudit->metadata['journal_entry_id'] ?? null, $name);
            $this->assertNotNull($postedAudit->correlation_id, $name);

            $service->unpost($source->id);
            $this->assertSourceAudit($source, "{$name}.unposted");

            $service->delete($source->id);
            $this->assertSourceAudit($source, "{$name}.deleted");
        }
    }

    public function test_source_audit_does_not_import_foreign_journal_correlation(): void
    {
        $foreignCompany = Company::create(['name' => 'Foreign audit company', 'tax_code' => 'FOREIGN-AUDIT']);
        $foreignFiscalYear = FiscalYear::withoutGlobalScopes()->create([
            'company_id' => $foreignCompany->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        $foreignJournal = JournalEntry::withoutGlobalScopes()->create([
            'company_id' => $foreignCompany->id,
            'fiscal_year_id' => $foreignFiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'FOREIGN-AUDIT-JE',
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'description' => 'Foreign correlation probe',
            'status' => 'posted',
            'total_amount' => 0,
        ]);
        AuditLog::withoutGlobalScopes()->create([
            'company_id' => $foreignCompany->id,
            'correlation_id' => 'foreign-correlation-must-not-leak',
            'user_id' => null,
            'action' => 'journal.posted',
            'model_type' => $foreignJournal->getMorphClass(),
            'model_id' => $foreignJournal->id,
            'old_values' => [],
            'new_values' => [],
            'metadata' => [],
        ]);

        $source = $this->makeSource($this->serviceCases()['cash_receipt'], 'AUDIT-CORRELATION');
        $source->forceFill(['journal_entry_id' => $foreignJournal->id])->save();

        $probe = new class(app(AuditService::class))
        {
            use RecordsSourceAudit;

            public function __construct(private readonly AuditService $auditService) {}

            public function record(Model $source): void
            {
                $this->recordSourceAudit($source, 'source.audit.correlation-boundary', [], []);
            }
        };
        $probe->record($source);

        $audit = AuditLog::withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('model_id', $source->id)->where('action', 'source.audit.correlation-boundary')->sole();
        $this->assertNotSame('foreign-correlation-must-not-leak', $audit->correlation_id);
    }

    public function test_source_audit_failure_rolls_back_post_for_cash_bank_and_inventory_families(): void
    {
        foreach (['cash_receipt', 'bank_receipt', 'inventory_receipt'] as $name) {
            $case = $this->serviceCases()[$name];
            $source = $this->makeSource($case, "SOURCE-AUDIT-FAIL-{$name}");
            $journalCount = JournalEntry::count();
            $lineCount = JournalEntryLine::count();
            $this->app->instance(AuditService::class, new class extends AuditService
            {
                public function record(Model $model, string $action, array $before = [], array $after = [], ?string $correlationId = null, array $metadata = []): AuditLog
                {
                    if (! str_starts_with($action, 'journal.')) {
                        throw new RuntimeException('Injected source audit failure');
                    }

                    return parent::record($model, $action, $before, $after, $correlationId, $metadata);
                }
            });

            try {
                app($case['service'])->post($source->id);
                $this->fail("{$name}: expected source audit failure");
            } catch (RuntimeException $exception) {
                $this->assertSame('Injected source audit failure', $exception->getMessage(), $name);
            }

            $fresh = $source->fresh();
            $this->assertFalse($fresh->is_posted, $name);
            $this->assertNull($fresh->journal_entry_id, $name);
            $this->assertSame($journalCount, JournalEntry::count(), $name);
            $this->assertSame($lineCount, JournalEntryLine::count(), $name);
            $this->restoreAuditWrites();
        }
    }

    private function serviceCases(): array
    {
        return [
            'cash_receipt' => ['model' => CashReceipt::class, 'service' => CashReceiptService::class, 'debit' => '1111', 'credit' => '131'],
            'cash_payment' => ['model' => CashPayment::class, 'service' => CashPaymentService::class, 'debit' => '331', 'credit' => '1111'],
            'bank_receipt' => ['model' => BankReceipt::class, 'service' => BankReceiptService::class, 'debit' => '1121', 'credit' => '131'],
            'bank_payment' => ['model' => BankPayment::class, 'service' => BankPaymentService::class, 'debit' => '331', 'credit' => '1121'],
            'inventory_receipt' => ['model' => InventoryReceipt::class, 'service' => InventoryReceiptService::class, 'debit' => '1561', 'credit' => '331', 'inventory' => true],
            'inventory_issue' => ['model' => InventoryIssue::class, 'service' => InventoryIssueService::class, 'debit' => '632', 'credit' => '1561', 'inventory' => true],
        ];
    }

    private function makeSource(array $case, string $voucherNumber): Model
    {
        $model = $case['model'];
        $amountColumn = in_array($model, [BankReceipt::class, BankPayment::class], true) ? 'amount' : 'total_amount';
        $source = $model::create([
            'company_id' => $this->company->id,
            'voucher_number' => $voucherNumber,
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'description' => 'Atomicity failure injection',
            'reason' => 'Atomicity failure injection',
            $amountColumn => 100000,
            'status' => 'draft',
            'is_posted' => false,
            ...in_array($model, [BankReceipt::class, BankPayment::class], true)
                ? ['bank_account_id' => $this->bankAccount->id]
                : (($case['inventory'] ?? false) ? ['warehouse_id' => $this->warehouse->id] : []),
        ]);

        $line = [
            'description' => 'Atomicity line',
            'debit_account' => $case['debit'],
            'credit_account' => $case['credit'],
            'amount' => 100000,
        ];
        if ($case['inventory'] ?? false) {
            $line += [
                'item_id' => $this->item->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 1,
                'unit_price' => 100000,
            ];
        }
        $source->lines()->create($line);

        return $source;
    }

    private function createPayload(string $name, array $case): array
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'LIFE-'.$name,
            'voucher_date' => '2026-08-20',
            'posting_date' => '2026-08-20',
            'description' => 'Source audit lifecycle',
            'reason' => 'Source audit lifecycle',
            'lines' => [[
                'description' => 'Lifecycle line',
                'debit_account' => $case['debit'],
                'credit_account' => $case['credit'],
                'amount' => 100000,
                ...($case['inventory'] ?? false ? [
                    'item_id' => $this->item->id,
                    'warehouse_id' => $this->warehouse->id,
                    'quantity' => 1,
                    'unit_price' => 100000,
                ] : []),
            ]],
        ];
        if ($case['inventory'] ?? false) {
            $payload['warehouse_id'] = $this->warehouse->id;
        }
        if (str_starts_with($name, 'bank_')) {
            $payload['bank_account_id'] = $this->bankAccount->id;
        }

        return $payload;
    }

    private function assertSourceAudit(Model $source, string $event): AuditLog
    {
        $audit = AuditLog::withoutGlobalScope('company')
            ->where('company_id', $this->company->id)
            ->where('model_type', $source->getMorphClass())
            ->where('model_id', $source->id)
            ->where('action', $event)
            ->latest('id')
            ->first();
        $this->assertNotNull($audit, $event);

        return $audit;
    }

    private function failAuditWrites(): void
    {
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
                throw new RuntimeException('Injected audit failure');
            }
        });
    }

    private function restoreAuditWrites(): void
    {
        $this->app->instance(AuditService::class, new AuditService);
    }

    private function failJournalMethod(string $method): void
    {
        $journalService = \Mockery::mock(JournalEntryService::class);
        $journalService->shouldReceive($method)->once()->andThrow(new RuntimeException('Injected journal failure'));
        $this->app->instance(JournalEntryService::class, $journalService);
    }

    private function restoreJournalService(): void
    {
        $this->app->forgetInstance(JournalEntryService::class);
    }
}
