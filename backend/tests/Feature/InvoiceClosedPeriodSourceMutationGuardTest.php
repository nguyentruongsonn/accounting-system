<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Services\PurchaseInvoiceService;
use App\Services\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * The guard is intentionally exercised through the source services rather
 * than a controller-only middleware path. That protects API, queued, and
 * internal callers alike and proves a foreign tenant's close is ignored.
 */
class InvoiceClosedPeriodSourceMutationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    public function test_closed_period_blocks_sales_and_purchase_update_and_delete(): void
    {
        $company = $this->company('Closed source tenant', 'CLOSED-SOURCE-OWN');
        $sales = $this->sales($company, 'CLOSED-SALE-1', '2026-08-15');
        $purchase = $this->purchase($company, 'CLOSED-PURCHASE-1', '2026-08-15');
        $this->period($company, '2026-08-01', '2026-08-31', true);

        $salesService = app(SalesInvoiceService::class);
        $purchaseService = app(PurchaseInvoiceService::class);

        $this->assertClosed(fn () => $salesService->update($sales->id, ['description' => 'must not persist']));
        $this->assertClosed(fn () => $purchaseService->update($purchase->id, ['description' => 'must not persist']));
        $this->assertClosed(fn () => $salesService->delete($sales->id));
        $this->assertClosed(fn () => $purchaseService->delete($purchase->id));

        $this->assertSame('Sales source', SalesInvoice::withoutGlobalScope('company')->findOrFail($sales->id)->description);
        $this->assertSame('Purchase source', PurchaseInvoice::withoutGlobalScope('company')->findOrFail($purchase->id)->description);
        $this->assertDatabaseHas('sales_invoices', ['id' => $sales->id]);
        $this->assertDatabaseHas('purchase_invoices', ['id' => $purchase->id]);
    }

    public function test_open_period_allows_source_mutation_and_foreign_closed_period_has_no_effect(): void
    {
        $company = $this->company('Open source tenant', 'CLOSED-SOURCE-OPEN');
        $foreign = $this->company('Foreign closed tenant', 'CLOSED-SOURCE-FOREIGN');
        $this->period($foreign, '2026-08-01', '2026-08-31', true);

        $sales = $this->sales($company, 'OPEN-SALE-1', '2026-08-15');
        $purchase = $this->purchase($company, 'OPEN-PURCHASE-1', '2026-08-15');

        $updatedSales = app(SalesInvoiceService::class)->update($sales->id, ['description' => 'Updated in own open period']);
        $updatedPurchase = app(PurchaseInvoiceService::class)->update($purchase->id, ['description' => 'Updated in own open period']);

        $this->assertSame('Updated in own open period', $updatedSales->description);
        $this->assertSame('Updated in own open period', $updatedPurchase->description);
        $this->assertSame($company->id, $updatedSales->company_id);
        $this->assertSame($company->id, $updatedPurchase->company_id);
    }

    private function assertClosed(callable $mutation): void
    {
        try {
            $mutation();
            $this->fail('Expected a closed-period conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('kỳ kế toán đã khóa', $exception->getMessage());
        }
    }

    private function company(string $name, string $taxCode): Company
    {
        return Company::create(['name' => $name, 'tax_code' => $taxCode]);
    }

    private function period(Company $company, string $from, string $to, bool $closed): Period
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
            'start_date' => $from,
            'end_date' => $to,
            'status' => $closed ? 'closed' : 'open',
            'is_closed' => $closed,
        ]);
    }

    private function sales(Company $company, string $number, string $date): SalesInvoice
    {
        $customer = Customer::create(['company_id' => $company->id, 'code' => $number, 'name' => 'Customer '.$number]);

        return app(SalesInvoiceService::class)->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'invoice_number' => $number,
            'invoice_date' => $date,
            'accounting_date' => $date,
            'due_date' => '2026-09-15',
            'description' => 'Sales source',
            'lines' => [['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00']],
        ]);
    }

    private function purchase(Company $company, string $number, string $date): PurchaseInvoice
    {
        $supplier = Supplier::create(['company_id' => $company->id, 'code' => $number, 'name' => 'Supplier '.$number]);

        return app(PurchaseInvoiceService::class)->create([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'invoice_number' => $number,
            'invoice_date' => $date,
            'accounting_date' => $date,
            'due_date' => '2026-09-15',
            'description' => 'Purchase source',
            'lines' => [['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00']],
        ]);
    }
}
