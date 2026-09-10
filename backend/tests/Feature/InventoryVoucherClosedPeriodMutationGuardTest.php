<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\InventoryIssue;
use App\Models\InventoryReceipt;
use App\Models\Period;
use App\Services\InventoryIssueService;
use App\Services\InventoryReceiptService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Exercise the source services directly. Controller-only guards would leave
 * jobs and internal accounting callers able to rewrite closed inventory.
 */
class InventoryVoucherClosedPeriodMutationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    public function test_closed_period_blocks_every_inventory_source_mutation_path(): void
    {
        CarbonImmutable::setTestNow('2026-08-15 10:00:00');
        try {
            $company = $this->company('Closed inventory tenant', 'INVENTORY-CLOSED');
            $this->period($company, true);

            foreach ($this->voucherTypes() as $type) {
                $service = app($type['service']);
                $voucher = $this->source($type['model'], $company, 'CLOSED-'.$type['key']);

                $this->assertClosed(fn () => $service->create($this->payload($company, 'CREATE-'.$type['key'])));
                $this->assertClosed(fn () => $service->update($voucher->id, ['description' => 'must not persist'], $company->id));
                $this->assertClosed(fn () => $service->delete($voucher->id, $company->id));
                $this->assertClosed(fn () => $service->post($voucher->id, $company->id));
                $this->assertClosed(fn () => $service->void($voucher->id, $company->id));
                $this->assertClosed(fn () => $service->unpost($voucher->id, $company->id));
                $this->assertClosed(fn () => $service->duplicate($voucher->id, $company->id));

                $fresh = $type['model']::withoutGlobalScope('company')->findOrFail($voucher->id);
                $this->assertSame('2026-08-15', $fresh->posting_date->toDateString());
                $this->assertSame('draft', $fresh->status);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_foreign_tenant_close_does_not_block_open_inventory_source_documents(): void
    {
        $openCompany = $this->company('Open inventory tenant', 'INVENTORY-OPEN');
        $foreignClosedCompany = $this->company('Foreign closed inventory tenant', 'INVENTORY-FOREIGN');
        $this->period($foreignClosedCompany, true);

        foreach ($this->voucherTypes() as $type) {
            $voucher = app($type['service'])->create($this->payload($openCompany, 'OPEN-'.$type['key']));

            $this->assertSame($openCompany->id, $voucher->company_id);
            $this->assertSame('2026-08-15', $voucher->posting_date->toDateString());
        }
    }

    /** @return array<int, array{key: string, model: class-string<Model>, service: class-string}> */
    private function voucherTypes(): array
    {
        return [
            ['key' => 'receipt', 'model' => InventoryReceipt::class, 'service' => InventoryReceiptService::class],
            ['key' => 'issue', 'model' => InventoryIssue::class, 'service' => InventoryIssueService::class],
        ];
    }

    private function company(string $name, string $taxCode): Company
    {
        return Company::create(['name' => $name, 'tax_code' => $taxCode]);
    }

    private function period(Company $company, bool $closed): Period
    {
        $fiscal = FiscalYear::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);

        return Period::create([
            'fiscal_year_id' => $fiscal->id,
            'period' => 8,
            'period_number' => 8,
            'name' => 'August 2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => $closed ? 'closed' : 'open',
            'is_closed' => $closed,
        ]);
    }

    /** @param class-string<Model> $model */
    private function source(string $model, Company $company, string $number): Model
    {
        return $model::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'voucher_number' => $number,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'total_amount' => 0,
            'status' => 'draft',
            'is_posted' => false,
        ]);
    }

    private function payload(Company $company, string $number): array
    {
        return [
            'company_id' => $company->id,
            'voucher_number' => $number,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'lines' => [],
        ];
    }

    private function assertClosed(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a closed-period conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertStringContainsString('kỳ kế toán đã khóa', $exception->getMessage());
        }
    }
}
