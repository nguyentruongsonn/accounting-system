<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InventoryIssue;
use App\Models\InventoryIssueLine;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptLine;
use App\Models\Item;
use App\Models\Warehouse;
use App\Models\User;
use App\Services\InventoryMovementV2Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryMovementV2AdapterTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_CONTRACT = [
        'version' => 1,
        'sources' => ['inventory_receipt', 'inventory_issue'],
        'posting_status' => 'posted',
        'date_basis' => 'voucher_date',
        'purchase_invoices_create_movement' => false,
        'warehouse_lineage' => 'line_then_header',
    ];

    private const AMOUNT_CONTRACT = [
        'quantity' => ['source_representation' => 'integer', 'scale' => 0, 'output_representation' => 'integer_string'],
        'money' => ['source_representation' => 'integer', 'scale' => 0, 'output_representation' => 'integer_string'],
    ];

    #[DataProvider('invalidCalendarDates')]
    public function test_it_rejects_invalid_calendar_cutoffs_without_normalizing_them(string $date): void
    {
        [$company] = $this->lineage();

        $this->expectException(InvalidArgumentException::class);
        (new InventoryMovementV2Adapter)->movements(
            $company->id, $date, $date, self::SOURCE_CONTRACT, self::AMOUNT_CONTRACT,
        );
    }

    /** @return array<string, array{string}> */
    public static function invalidCalendarDates(): array
    {
        return [
            'non-leap day' => ['2026-02-29'],
            'day overflow' => ['2026-02-31'],
            'month overflow' => ['2026-13-01'],
            'zero day' => ['2026-01-00'],
        ];
    }

    public function test_it_emits_only_posted_receipt_and_issue_movements_with_inclusive_dates_and_signed_values(): void
    {
        [$company, $item, $warehouse] = $this->lineage();
        $this->receipt($company->id, $item->id, $warehouse->id, '2026-01-01', true, '10', '100');
        $this->issue($company->id, $item->id, $warehouse->id, '2026-01-31', true, '12', '125');
        $this->receipt($company->id, $item->id, $warehouse->id, '2026-01-15', false, '99', '999');

        $rows = (new InventoryMovementV2Adapter)->movements(
            $company->id, '2026-01-01', '2026-01-31', self::SOURCE_CONTRACT, self::AMOUNT_CONTRACT,
        );

        $this->assertSame([
            ['inventory_receipt', '2026-01-01', '10', '100'],
            ['inventory_issue', '2026-01-31', '-12', '-125'],
        ], array_map(static fn (array $row): array => [$row['source_type'], $row['voucher_date'], $row['quantity'], $row['amount']], $rows));
        $this->assertSame(['10', '-12'], array_column($rows, 'quantity'));
    }

    public function test_it_rejects_an_unsupported_source_or_amount_contract(): void
    {
        [$company, $item, $warehouse] = $this->lineage();
        $this->receipt($company->id, $item->id, $warehouse->id, '2026-01-01', true, '1', '1');

        $this->expectException(InvalidArgumentException::class);
        (new InventoryMovementV2Adapter)->movements(
            $company->id, '2026-01-01', '2026-01-01', array_replace(self::SOURCE_CONTRACT, ['purchase_invoices_create_movement' => true]), self::AMOUNT_CONTRACT,
        );
    }

    public function test_it_rejects_a_decimal_scale_contract_or_decimal_source_value(): void
    {
        [$company, $item, $warehouse] = $this->lineage();
        $this->receipt($company->id, $item->id, $warehouse->id, '2026-01-01', true, '1', '1');

        try {
            (new InventoryMovementV2Adapter)->movements(
                $company->id,
                '2026-01-01',
                '2026-01-01',
                self::SOURCE_CONTRACT,
                array_replace_recursive(self::AMOUNT_CONTRACT, ['money' => ['scale' => 2]]),
            );
            $this->fail('A scale-2 contract must not be accepted against INTEGER/BIGINT source columns.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $receipt = InventoryReceipt::create([
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'voucher_number' => 'PN-DECIMAL-SOURCE',
            'voucher_date' => '2026-01-01',
            'is_posted' => true,
        ]);
        InventoryReceiptLine::create(['inventory_receipt_id' => $receipt->id, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => '1.50', 'amount' => '1']);

        $this->expectException(InvalidArgumentException::class);
        (new InventoryMovementV2Adapter)->movements($company->id, '2026-01-01', '2026-01-01', self::SOURCE_CONTRACT, self::AMOUNT_CONTRACT);
    }

    public function test_it_fails_closed_for_cross_tenant_item_lineage_and_honours_header_warehouse_fallback(): void
    {
        [$company, $item, $warehouse] = $this->lineage();
        $receipt = InventoryReceipt::create([
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'voucher_number' => 'PN-HEADER-WH',
            'voucher_date' => '2026-01-02',
            'is_posted' => true,
        ]);
        InventoryReceiptLine::create(['inventory_receipt_id' => $receipt->id, 'item_id' => $item->id, 'quantity' => '2', 'amount' => '4']);

        $rows = (new InventoryMovementV2Adapter)->movements($company->id, '2026-01-02', '2026-01-02', self::SOURCE_CONTRACT, self::AMOUNT_CONTRACT);
        $this->assertSame($warehouse->id, $rows[0]['warehouse_id']);

        $otherCompany = Company::create(['name' => 'Other Company', 'tax_code' => 'V2-2', 'address' => 'Hanoi']);
        $otherItem = Item::create(['company_id' => $otherCompany->id, 'code' => 'V2-OTHER', 'name' => 'Other item']);
        InventoryReceiptLine::create(['inventory_receipt_id' => $receipt->id, 'item_id' => $otherItem->id, 'quantity' => '1', 'amount' => '1']);

        $this->expectException(InvalidArgumentException::class);
        (new InventoryMovementV2Adapter)->movements($company->id, '2026-01-02', '2026-01-02', self::SOURCE_CONTRACT, self::AMOUNT_CONTRACT);
    }

    public function test_it_fails_closed_when_a_posted_line_has_no_tenant_bound_warehouse(): void
    {
        [$company, $item] = $this->lineage();
        $receipt = InventoryReceipt::create([
            'company_id' => $company->id, 'voucher_number' => 'PN-MISSING-WH', 'voucher_date' => '2026-01-01', 'is_posted' => true,
        ]);
        InventoryReceiptLine::create(['inventory_receipt_id' => $receipt->id, 'item_id' => $item->id, 'quantity' => '1', 'amount' => '1']);

        $this->expectException(InvalidArgumentException::class);
        (new InventoryMovementV2Adapter)->movements($company->id, '2026-01-01', '2026-01-01', self::SOURCE_CONTRACT, self::AMOUNT_CONTRACT);
    }

    public function test_direct_adapter_rejects_an_authenticated_foreign_company(): void
    {
        [$company] = $this->lineage();
        $foreign = Company::create(['name' => 'V2 Foreign Company', 'tax_code' => 'V2-FOREIGN']);
        $actor = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($actor);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        (new InventoryMovementV2Adapter)->movements($foreign->id, '2026-01-01', '2026-01-01', self::SOURCE_CONTRACT, self::AMOUNT_CONTRACT);
    }

    /** @return array{Company, Item, Warehouse} */
    private function lineage(): array
    {
        $company = Company::create(['name' => 'V2 Company', 'tax_code' => 'V2-1', 'address' => 'Hanoi']);
        $item = Item::create(['company_id' => $company->id, 'code' => 'V2-ITEM-'.$company->id, 'name' => 'V2 Item']);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'V2-WH-'.$company->id, 'name' => 'V2 Warehouse']);

        return [$company, $item, $warehouse];
    }

    private function receipt(int $companyId, int $itemId, int $warehouseId, string $date, bool $posted, string $quantity, string $amount): void
    {
        $receipt = InventoryReceipt::create(['company_id' => $companyId, 'warehouse_id' => $warehouseId, 'voucher_number' => 'PN-'.uniqid(), 'voucher_date' => $date, 'is_posted' => $posted]);
        InventoryReceiptLine::create(['inventory_receipt_id' => $receipt->id, 'item_id' => $itemId, 'warehouse_id' => $warehouseId, 'quantity' => $quantity, 'amount' => $amount]);
    }

    private function issue(int $companyId, int $itemId, int $warehouseId, string $date, bool $posted, string $quantity, string $amount): void
    {
        $issue = InventoryIssue::create(['company_id' => $companyId, 'warehouse_id' => $warehouseId, 'voucher_number' => 'PX-'.uniqid(), 'voucher_date' => $date, 'is_posted' => $posted]);
        InventoryIssueLine::create(['inventory_issue_id' => $issue->id, 'item_id' => $itemId, 'warehouse_id' => $warehouseId, 'quantity' => $quantity, 'amount' => $amount]);
    }
}
