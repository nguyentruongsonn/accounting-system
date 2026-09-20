<?php

namespace Tests\Feature\Purchase;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Period;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Services\PurchaseExpenseAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PurchaseExpenseAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocation_is_saved_and_recalculates_target_stock_value(): void
    {
        [$company, $supplier, $item] = $this->catalogue();
        $this->openPeriod($company);
        $source = $this->invoice($company, $supplier, [
            'invoice_number' => 'EXP-001',
            'voucher_type' => 'service',
            'is_purchase_expense' => true,
            'purchase_expense' => 100,
            'total_amount' => 100,
        ]);
        $target = $this->invoice($company, $supplier, [
            'invoice_number' => 'GOODS-001',
            'voucher_type' => 'goods',
            'purchase_expense' => 0,
            'total_stock_value' => 1000,
        ]);
        $line = $target->lines()->create([
            'item_id' => $item->id,
            'description' => 'Hàng hóa kiểm thử',
            'debit_account' => '156',
            'credit_account' => '331',
            'quantity' => 1,
            'unit_price' => 1000,
            'amount' => 1000,
            'purchase_expense' => 0,
            'stock_value' => 1000,
        ]);

        app(PurchaseExpenseAllocationService::class)->replaceForSource($source, [[
            'target_purchase_invoice_id' => $target->id,
            'target_purchase_invoice_line_id' => $line->id,
            'allocated_amount' => '100',
            'allocation_method' => 'value',
        ]]);

        $this->assertDatabaseHas('purchase_expense_allocations', [
            'company_id' => $company->id,
            'source_purchase_invoice_id' => $source->id,
            'target_purchase_invoice_line_id' => $line->id,
            'allocated_amount' => 100,
        ]);
        $this->assertDatabaseHas('purchase_invoice_lines', [
            'id' => $line->id,
            'purchase_expense' => 100,
            'stock_value' => 1100,
        ]);
        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $target->id,
            'purchase_expense' => 100,
            'total_stock_value' => 1100,
        ]);
    }

    public function test_allocation_cannot_exceed_source_expense(): void
    {
        [$company, $supplier] = $this->catalogue();
        $source = $this->invoice($company, $supplier, [
            'invoice_number' => 'EXP-002',
            'voucher_type' => 'service',
            'is_purchase_expense' => true,
            'purchase_expense' => 100,
            'total_amount' => 100,
        ]);

        $this->expectException(ValidationException::class);
        app(PurchaseExpenseAllocationService::class)->replaceForSource($source, [[
            'target_purchase_invoice_id' => 999,
            'target_purchase_invoice_line_id' => 999,
            'allocated_amount' => '100.01',
        ]]);

        $this->assertDatabaseCount('purchase_expense_allocations', 0);
    }

    /** @return array{0: Company, 1: Supplier, 2: Item} */
    private function catalogue(): array
    {
        $company = Company::create(['name' => 'Purchase allocation company']);
        $supplier = Supplier::create([
            'company_id' => $company->id,
            'code' => 'SUP-ALLOC-'.$company->id,
            'name' => 'Supplier allocation test',
            'is_active' => true,
        ]);
        $item = Item::create([
            'company_id' => $company->id,
            'code' => 'ITEM-ALLOC-'.$company->id,
            'name' => 'Item allocation test',
            'type' => 'Goods',
            'is_active' => true,
        ]);

        return [$company, $supplier, $item];
    }

    private function openPeriod(Company $company): void
    {
        $fiscalYear = FiscalYear::create([
            'company_id' => $company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
        Period::create([
            'fiscal_year_id' => $fiscalYear->id,
            'period' => 1,
            'period_number' => 1,
            'name' => 'Tháng 01/2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'status' => 'open',
            'is_closed' => false,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function invoice(Company $company, Supplier $supplier, array $overrides): PurchaseInvoice
    {
        return PurchaseInvoice::create(array_merge([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-'.$company->id.'-'.uniqid(),
            'invoice_date' => '2026-01-15',
            'accounting_date' => '2026-01-15',
            'sub_total' => 1000,
            'tax_amount' => 0,
            'total_amount' => 1000,
            'status' => 'draft',
            'is_posted' => false,
            'currency' => 'VND',
        ], $overrides));
    }
}
