<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\PurchaseDiscount;
use App\Models\PurchaseReturn;
use App\Models\SalesDiscount;
use App\Models\SalesReturn;
use App\Models\SettlementAllocation;
use App\Models\User;
use App\Services\JournalEntryService;
use App\Services\PurchaseDiscountService;
use App\Services\PurchaseReturnService;
use App\Services\SalesDiscountService;
use App\Services\SalesReturnService;
use App\Services\SettlementAllocationReversalService;
use App\Services\SettlementAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Adversarial evidence for the commercial-adjustment settlement reversal
 * branch.  It deliberately starts with a real posted return/discount,
 * original journal and canonical reduction allocation, then closes the
 * reversal date.  The conflict must occur before a reversal journal or
 * allocation can be written; the original source must remain untouched.
 */
class ReturnDiscountSettlementReversalClosedPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_closed_reversal_date_cannot_mutate_each_return_or_discount_source_or_create_reversal_evidence(): void
    {
        $company = $this->company('Commercial reversal closed', 'COMM-REV-CLOSED');
        $actor = User::factory()->create(['company_id' => $company->id]);
        $this->accounts($company->id);
        $this->fiscal($company);

        foreach ($this->variants() as $variant) {
            $targetId = $this->target($variant['ledger'], $company->id);
            $source = $this->source($variant, $company->id, $targetId);
            $journal = app(JournalEntryService::class)->createPosted([
                'company_id' => $company->id,
                'voucher_type' => $variant['key'],
                'voucher_number' => 'GL-'.$variant['key'].'-'.uniqid(),
                'voucher_date' => '2026-08-15',
                'posting_date' => '2026-08-15',
                'description' => 'Original commercial adjustment',
                'source_document_type' => $variant['model'],
                'source_document_id' => $source->id,
                'lines' => [[
                    'debit_account' => $variant['debit'],
                    'credit_account' => $variant['credit'],
                    'amount' => '100.00',
                    'description' => 'Original commercial adjustment',
                ]],
            ]);
            $source->forceFill(['journal_entry_id' => $journal->id])->save();

            $allocation = app(SettlementAllocationService::class)->createPosted($actor, [
                'source_document_type' => $variant['key'],
                'source_document_id' => $source->id,
                'target_document_type' => $variant['ledger'] === 'ap' ? 'purchase_invoice' : 'sales_invoice',
                'target_document_id' => $targetId,
                'allocation_kind' => $variant['kind'],
                'amount_raw' => '100.00',
                'amount_scale' => 2,
                'currency_code' => 'VND',
                'effective_date' => '2026-08-15',
            ]);

            $sourceBefore = $source->fresh()->getAttributes();
            $journalCountBefore = JournalEntry::withoutGlobalScope('company')->where('company_id', $company->id)->count();
            $allocationCountBefore = SettlementAllocation::withoutGlobalScope('company')->where('company_id', $company->id)->count();

            $this->period($company, true);
            try {
                app(SettlementAllocationReversalService::class)->reverse($actor, $allocation->id, 'Closed-date adversarial reversal', '2026-08-15');
                $this->fail("{$variant['key']} reversal must be rejected in a closed period.");
            } catch (ConflictHttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
                $this->assertStringContainsString('kỳ kế toán đã khóa', $exception->getMessage());
            }

            $this->assertSame($sourceBefore, $source->fresh()->getAttributes(), "{$variant['key']} source evidence changed despite closed reversal date.");
            $this->assertSame($journalCountBefore, JournalEntry::withoutGlobalScope('company')->where('company_id', $company->id)->count());
            $this->assertSame($allocationCountBefore, SettlementAllocation::withoutGlobalScope('company')->where('company_id', $company->id)->count());
            $this->assertDatabaseMissing('settlement_allocations', ['company_id' => $company->id, 'reverses_allocation_id' => $allocation->id]);

            // A distinct August period is sufficient for the first variant;
            // remove it so each following setup can create its posted evidence.
            Period::withoutGlobalScopes()->where('fiscal_year_id', $this->fiscalId($company))->delete();
        }
    }

    public function test_foreign_tenant_closed_period_neither_blocks_own_open_reversal_nor_resolves_foreign_allocation(): void
    {
        $company = $this->company('Commercial reversal open', 'COMM-REV-OPEN');
        $foreign = $this->company('Commercial reversal foreign', 'COMM-REV-FOREIGN');
        $actor = User::factory()->create(['company_id' => $company->id]);
        $this->accounts($company->id);
        $this->accounts($foreign->id);
        $this->fiscal($company);
        $this->fiscal($foreign);
        $own = $this->postedAllocation($company->id, $actor, $this->variants()[0]);
        $foreignAllocation = $this->postedAllocation($foreign->id, User::factory()->create(['company_id' => $foreign->id]), $this->variants()[1]);
        $this->period($foreign, true);

        $reversal = app(SettlementAllocationReversalService::class)->reverse($actor, $own->id, 'Own tenant remains open', '2026-08-15');
        $this->assertSame($company->id, $reversal->company_id);
        $this->assertSame('reversal', $reversal->allocation_direction);

        $before = SettlementAllocation::withoutGlobalScope('company')->where('company_id', $foreign->id)->count();
        try {
            app(SettlementAllocationReversalService::class)->reverse($actor, $foreignAllocation->id, 'Foreign allocation', '2026-08-15');
            $this->fail('A foreign allocation id must not resolve in the actor tenant.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($before, SettlementAllocation::withoutGlobalScope('company')->where('company_id', $foreign->id)->count());
    }

    public function test_closed_source_date_blocks_void_and_unpost_of_each_posted_return_or_discount_before_source_or_journal_mutates(): void
    {
        $company = $this->company('Commercial void closed', 'COMM-VOID-CLOSED');
        $this->actingAs(User::factory()->create(['company_id' => $company->id]));
        $this->accounts($company->id);
        $this->fiscal($company);

        foreach ($this->variants() as $variant) {
            $targetId = $this->target($variant['ledger'], $company->id);
            $source = $this->source($variant, $company->id, $targetId);
            $journal = app(JournalEntryService::class)->createPosted([
                'company_id' => $company->id, 'voucher_type' => $variant['key'], 'voucher_number' => 'GL-VOID-'.uniqid(),
                'voucher_date' => '2026-08-15', 'posting_date' => '2026-08-15', 'description' => 'Original adjustment',
                'source_document_type' => $variant['model'], 'source_document_id' => $source->id,
                'lines' => [['debit_account' => $variant['debit'], 'credit_account' => $variant['credit'], 'amount' => '100.00', 'description' => 'Original adjustment']],
            ]);
            $source->forceFill(['journal_entry_id' => $journal->id])->save();
            $sourceBefore = $source->fresh()->getAttributes();
            $journalBefore = $journal->fresh()->getAttributes();
            $this->period($company, true);

            foreach (['void', 'unpost'] as $operation) {
                try {
                    app($variant['service'])->{$operation}($source->id);
                    $this->fail("{$variant['key']} {$operation} must be rejected in a closed period.");
                } catch (ConflictHttpException $exception) {
                    $this->assertSame(409, $exception->getStatusCode());
                    $this->assertStringContainsString('kỳ kế toán đã khóa', $exception->getMessage());
                }
                $this->assertSame($sourceBefore, $source->fresh()->getAttributes(), "{$variant['key']} source changed after rejected {$operation}.");
                $this->assertSame($journalBefore, $journal->fresh()->getAttributes(), "{$variant['key']} journal changed after rejected {$operation}.");
            }

            Period::withoutGlobalScopes()->where('fiscal_year_id', $this->fiscalId($company))->delete();
        }
    }

    /** @return list<array{key:string,model:class-string,service:class-string,ledger:string,kind:string,debit:string,credit:string}> */
    private function variants(): array
    {
        return [
            ['key' => 'purchase_return', 'model' => PurchaseReturn::class, 'service' => PurchaseReturnService::class, 'ledger' => 'ap', 'kind' => 'return', 'debit' => '331', 'credit' => '156'],
            ['key' => 'purchase_discount', 'model' => PurchaseDiscount::class, 'service' => PurchaseDiscountService::class, 'ledger' => 'ap', 'kind' => 'discount', 'debit' => '331', 'credit' => '156'],
            ['key' => 'sales_return', 'model' => SalesReturn::class, 'service' => SalesReturnService::class, 'ledger' => 'ar', 'kind' => 'return', 'debit' => '521', 'credit' => '131'],
            ['key' => 'sales_discount', 'model' => SalesDiscount::class, 'service' => SalesDiscountService::class, 'ledger' => 'ar', 'kind' => 'discount', 'debit' => '521', 'credit' => '131'],
        ];
    }

    private function postedAllocation(int $companyId, User $actor, array $variant): SettlementAllocation
    {
        $targetId = $this->target($variant['ledger'], $companyId);
        $source = $this->source($variant, $companyId, $targetId);
        $journal = app(JournalEntryService::class)->createPosted([
            'company_id' => $companyId, 'voucher_type' => $variant['key'], 'voucher_number' => 'GL-'.uniqid(),
            'voucher_date' => '2026-08-15', 'posting_date' => '2026-08-15', 'description' => 'Original adjustment',
            'source_document_type' => $variant['model'], 'source_document_id' => $source->id,
            'lines' => [['debit_account' => $variant['debit'], 'credit_account' => $variant['credit'], 'amount' => '100.00', 'description' => 'Original adjustment']],
        ]);
        $source->forceFill(['journal_entry_id' => $journal->id])->save();
        return app(SettlementAllocationService::class)->createPosted($actor, [
            'source_document_type' => $variant['key'], 'source_document_id' => $source->id,
            'target_document_type' => $variant['ledger'] === 'ap' ? 'purchase_invoice' : 'sales_invoice',
            'target_document_id' => $targetId, 'allocation_kind' => $variant['kind'],
            'amount_raw' => '100.00', 'amount_scale' => 2, 'currency_code' => 'VND', 'effective_date' => '2026-08-15',
        ]);
    }

    private function source(array $variant, int $companyId, int $targetId): PurchaseReturn|PurchaseDiscount|SalesReturn|SalesDiscount
    {
        $model = $variant['model'];
        return $model::withoutGlobalScope('company')->create([
            'company_id' => $companyId, 'voucher_number' => strtoupper($variant['key']).'-'.uniqid(),
            'voucher_date' => '2026-08-15', 'accounting_date' => '2026-08-15', 'description' => 'Posted adjustment source',
            'sub_total' => 100, 'total_amount' => 100, 'grand_total' => 100, 'status' => 'posted', 'is_posted' => true,
            'is_decrease_debt' => true, 'reference_invoice_id' => $targetId,
        ]);
    }

    private function target(string $ledger, int $companyId): int
    {
        if ($ledger === 'ap') {
            $supplier = DB::table('suppliers')->insertGetId(['company_id' => $companyId, 'code' => 'REV-SUP-'.uniqid(), 'name' => 'Supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            return (int) DB::table('purchase_invoices')->insertGetId(['company_id' => $companyId, 'supplier_id' => $supplier, 'invoice_number' => 'REV-PI-'.uniqid(), 'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'due_date' => '2026-08-31', 'total_amount' => '100.00', 'status' => 'posted', 'is_posted' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $customer = DB::table('customers')->insertGetId(['company_id' => $companyId, 'code' => 'REV-CUS-'.uniqid(), 'name' => 'Customer', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        return (int) DB::table('sales_invoices')->insertGetId(['company_id' => $companyId, 'customer_id' => $customer, 'invoice_number' => 'REV-SI-'.uniqid(), 'invoice_date' => '2026-08-01', 'accounting_date' => '2026-08-01', 'due_date' => '2026-08-31', 'total_amount' => '100.00', 'status' => 'posted', 'is_posted' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function company(string $name, string $taxCode): Company { return Company::create(['name' => $name, 'tax_code' => $taxCode]); }

    private function accounts(int $companyId): void
    {
        foreach ([['331', 'Payable', 'liability', 'credit'], ['156', 'Inventory', 'asset', 'debit'], ['521', 'Sales reduction', 'revenue', 'debit'], ['131', 'Receivable', 'asset', 'debit']] as [$code, $name, $type, $nature]) {
            ChartOfAccount::withoutGlobalScopes()->create(['company_id' => $companyId, 'code' => $code, 'name' => $name, 'type' => $type, 'nature' => $nature, 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        }
    }

    private function period(Company $company, bool $closed): void
    {
        $fiscal = $this->fiscal($company);
        Period::withoutGlobalScopes()->create(['fiscal_year_id' => $fiscal->id, 'period' => 8, 'period_number' => 8, 'name' => 'August 2026', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => $closed ? 'closed' : 'open', 'is_closed' => $closed]);
    }

    private function fiscal(Company $company): FiscalYear
    {
        return FiscalYear::withoutGlobalScope('company')->firstOrCreate(['company_id' => $company->id, 'year' => 2026], ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
    }

    private function fiscalId(Company $company): int { return (int) FiscalYear::withoutGlobalScope('company')->where('company_id', $company->id)->where('year', 2026)->value('id'); }
}
