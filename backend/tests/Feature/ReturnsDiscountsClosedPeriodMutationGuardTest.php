<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\PurchaseDiscount;
use App\Models\PurchaseReturn;
use App\Models\SalesDiscount;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseDiscountService;
use App\Services\PurchaseReturnService;
use App\Services\SalesDiscountService;
use App\Services\SalesReturnService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Exercises service-layer guards, not only HTTP middleware: queued and
 * internal callers therefore cannot mutate source evidence in a closed period.
 */
class ReturnsDiscountsClosedPeriodMutationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    public function test_closed_period_blocks_every_return_and_discount_source_mutation_path(): void
    {
        CarbonImmutable::setTestNow('2026-08-15 10:00:00');
        try {
            $company = $this->company('Closed commercial-adjustment tenant', 'COMMERCIAL-ADJUSTMENT-CLOSED');
            Sanctum::actingAs(User::factory()->create(['company_id' => $company->id]));
            $this->period($company, true);

            foreach ($this->sourceTypes() as $type) {
                $service = app($type['service']);
                $source = $this->source($type['model'], $company, 'CLOSED-'.$type['key']);

                $this->assertClosed(fn () => $service->create($this->payload($company, 'CREATE-'.$type['key'])));
                $this->assertClosed(fn () => $service->update($source->id, ['description' => 'must not persist']));
                $this->assertClosed(fn () => $service->delete($source->id));
                $this->assertClosed(fn () => $service->post($source->id));
                $this->assertClosed(fn () => $service->void($source->id));
                $this->assertClosed(fn () => $service->unpost($source->id));
                $this->assertClosed(fn () => $service->duplicate($source->id));

                $this->assertSame('2026-08-15', $source->fresh()->accounting_date->toDateString());
                $this->assertDatabaseHas($type['table'], ['id' => $source->id, 'description' => 'Closed source']);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_foreign_tenant_close_does_not_block_open_tenant_returns_or_discounts(): void
    {
        $openCompany = $this->company('Open commercial-adjustment tenant', 'COMMERCIAL-ADJUSTMENT-OPEN');
        $foreignClosedCompany = $this->company('Foreign closed tenant', 'COMMERCIAL-ADJUSTMENT-FOREIGN');
        Sanctum::actingAs(User::factory()->create(['company_id' => $openCompany->id]));
        $this->period($foreignClosedCompany, true);

        foreach ($this->sourceTypes() as $type) {
            $source = app($type['service'])->create($this->payload($openCompany, 'OPEN-'.$type['key']));

            $this->assertSame($openCompany->id, $source->company_id);
            $this->assertSame('2026-08-15', $source->accounting_date->toDateString());
        }
    }

    public function test_direct_return_and_discount_lifecycle_services_cannot_mutate_foreign_resources(): void
    {
        $owner = $this->company('Commercial adjustment owner', 'COMMERCIAL-ADJUSTMENT-OWNER');
        $foreign = $this->company('Commercial adjustment foreign', 'COMMERCIAL-ADJUSTMENT-FOREIGN');
        Sanctum::actingAs(User::factory()->create(['company_id' => $owner->id]));

        foreach ($this->sourceTypes() as $type) {
            $source = $this->source($type['model'], $foreign, 'FOREIGN-'.$type['key']);
            $service = app($type['service']);

            $getRejected = false;
            try {
                $service->getById($source->id);
            } catch (\Throwable) {
                $getRejected = true;
            }
            $this->assertTrue($getRejected, "{$type['key']} detail read must reject a foreign resource.");

            $updateRejected = false;
            try {
                $service->update($source->id, ['description' => 'direct tenant attack']);
            } catch (\Throwable) {
                $updateRejected = true;
            }
            $this->assertTrue($updateRejected, "{$type['key']} direct update must reject a foreign resource.");

            $countBeforeDuplicate = $type['model']::withoutGlobalScopes()->where('company_id', $foreign->id)->count();
            $duplicateRejected = false;
            try {
                $service->duplicate($source->id);
            } catch (\Throwable) {
                $duplicateRejected = true;
            }
            $this->assertTrue($duplicateRejected, "{$type['key']} direct duplicate must reject a foreign resource.");
            $this->assertSame($countBeforeDuplicate, $type['model']::withoutGlobalScopes()->where('company_id', $foreign->id)->count());

            $deleteRejected = false;
            try {
                $service->delete($source->id);
            } catch (\Throwable) {
                $deleteRejected = true;
            }
            $this->assertTrue($deleteRejected, "{$type['key']} direct delete must reject a foreign resource.");
            $this->assertNotNull($type['model']::withoutGlobalScopes()->find($source->id));
        }
    }

    /** @return array<int, array{key: string, model: class-string<Model>, service: class-string, table: string}> */
    private function sourceTypes(): array
    {
        return [
            ['key' => 'purchase_return', 'model' => PurchaseReturn::class, 'service' => PurchaseReturnService::class, 'table' => 'purchase_returns'],
            ['key' => 'purchase_discount', 'model' => PurchaseDiscount::class, 'service' => PurchaseDiscountService::class, 'table' => 'purchase_discounts'],
            ['key' => 'sales_return', 'model' => SalesReturn::class, 'service' => SalesReturnService::class, 'table' => 'sales_returns'],
            ['key' => 'sales_discount', 'model' => SalesDiscount::class, 'service' => SalesDiscountService::class, 'table' => 'sales_discounts'],
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
            'accounting_date' => '2026-08-15',
            'description' => 'Closed source',
            'status' => 'draft',
            'is_posted' => false,
            'sub_total' => 100,
            'total_amount' => 100,
            'grand_total' => 100,
        ]);
    }

    private function payload(Company $company, string $number): array
    {
        $supplier = Supplier::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'GUARD-SUP-'.$company->id],
            ['name' => 'Guard supplier']
        );
        $customer = Customer::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'GUARD-CUS-'.$company->id],
            ['name' => 'Guard customer']
        );

        return [
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'customer_id' => $customer->id,
            'voucher_number' => $number,
            'voucher_date' => '2026-08-15',
            'accounting_date' => '2026-08-15',
            'description' => 'Guard test',
            'lines' => [[
                'description' => 'Guard test line',
                'quantity' => 1,
                'unit_price' => 100,
                'amount' => 100,
            ]],
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
