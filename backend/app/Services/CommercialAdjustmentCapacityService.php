<?php

namespace App\Services;

use App\Models\PurchaseDiscount;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\SalesDiscount;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Support\DecimalMoney;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Validates that commercial adjustments stay attached to a posted invoice and
 * cannot consume more quantity or value than the original document leaves.
 * The invoice row is locked for the duration of the caller's posting
 * transaction so two concurrent returns cannot both spend the same balance.
 */
final class CommercialAdjustmentCapacityService
{
    public function assertPurchaseReturn(PurchaseReturn $adjustment): void
    {
        if ($adjustment->reference_invoice_id === null) {
            return;
        }

        /** @var PurchaseInvoice|null $invoice */
        $invoice = PurchaseInvoice::withoutGlobalScope('company')
            ->where('company_id', $adjustment->company_id)
            ->with('lines')
            ->lockForUpdate()
            ->find($adjustment->reference_invoice_id);
        $this->assertInvoice($invoice, $adjustment->supplier_id, 'supplier', 'reference_invoice_id');

        if ($adjustment->is_outward) {
            $this->assertReturnQuantities(
                $adjustment->lines,
                $invoice->lines,
                $this->postedReturnQuantities('purchase_returns', 'purchase_return_lines', 'purchase_return_id', $adjustment, 'is_outward'),
            );
        }
    }

    public function assertSalesReturn(SalesReturn $adjustment): void
    {
        if ($adjustment->reference_invoice_id === null) {
            return;
        }

        /** @var SalesInvoice|null $invoice */
        $invoice = SalesInvoice::withoutGlobalScope('company')
            ->where('company_id', $adjustment->company_id)
            ->with('lines')
            ->lockForUpdate()
            ->find($adjustment->reference_invoice_id);
        $this->assertInvoice($invoice, $adjustment->customer_id, 'customer', 'reference_invoice_id');

        if ($adjustment->is_inward) {
            $this->assertReturnQuantities(
                $adjustment->lines,
                $invoice->lines,
                $this->postedReturnQuantities('sales_returns', 'sales_return_lines', 'sales_return_id', $adjustment, 'is_inward'),
            );
        }
    }

    public function assertPurchaseDiscount(PurchaseDiscount $adjustment): void
    {
        if ($adjustment->reference_invoice_id === null || ! $adjustment->is_decrease_debt) {
            return;
        }

        /** @var PurchaseInvoice|null $invoice */
        $invoice = PurchaseInvoice::withoutGlobalScope('company')
            ->where('company_id', $adjustment->company_id)
            ->lockForUpdate()
            ->find($adjustment->reference_invoice_id);
        $this->assertInvoice($invoice, $adjustment->supplier_id, 'supplier', 'reference_invoice_id');
        $this->assertDiscountValue($adjustment, 'purchase_discounts', $invoice);
    }

    public function assertSalesDiscount(SalesDiscount $adjustment): void
    {
        if ($adjustment->reference_invoice_id === null || ! $adjustment->is_decrease_debt) {
            return;
        }

        /** @var SalesInvoice|null $invoice */
        $invoice = SalesInvoice::withoutGlobalScope('company')
            ->where('company_id', $adjustment->company_id)
            ->lockForUpdate()
            ->find($adjustment->reference_invoice_id);
        $this->assertInvoice($invoice, $adjustment->customer_id, 'customer', 'reference_invoice_id');
        $this->assertDiscountValue($adjustment, 'sales_discounts', $invoice);
    }

    private function assertInvoice(?Model $invoice, mixed $partyId, string $party, string $field): void
    {
        if ($invoice === null || ! (bool) $invoice->is_posted) {
            throw ValidationException::withMessages([$field => 'Chỉ được tham chiếu hóa đơn đã ghi sổ trong cùng công ty.']);
        }

        if ($partyId !== null && (int) $invoice->getAttribute($party.'_id') !== (int) $partyId) {
            throw ValidationException::withMessages([$field => 'Đối tượng của chứng từ điều chỉnh phải trùng với hóa đơn gốc.']);
        }
    }

    /** @param iterable<int, Model> $adjustmentLines @param iterable<int, Model> $invoiceLines @param array<string, BigDecimal> $postedByItem */
    private function assertReturnQuantities(iterable $adjustmentLines, iterable $invoiceLines, array $postedByItem): void
    {
        $invoiceByItem = [];
        foreach ($invoiceLines as $line) {
            if ($line->item_id === null) {
                continue;
            }
            $itemId = (string) $line->item_id;
            $invoiceByItem[$itemId] = ($invoiceByItem[$itemId] ?? BigDecimal::zero()->toScale(4))
                ->plus($this->quantity($line->quantity));
        }

        $errors = [];
        foreach ($adjustmentLines as $index => $line) {
            if ($line->item_id === null) {
                $errors["lines.{$index}.item_id"] = 'Dòng trả hàng phải chọn mặt hàng để đối chiếu hóa đơn gốc.';

                continue;
            }
            try {
                $quantity = $this->quantity($line->quantity);
            } catch (\Throwable) {
                $errors["lines.{$index}.quantity"] = 'Số lượng trả phải là số dương hợp lệ.';

                continue;
            }
            if (! $quantity->isPositive()) {
                $errors["lines.{$index}.quantity"] = 'Số lượng trả phải lớn hơn 0.';

                continue;
            }
            $itemId = (string) $line->item_id;
            $invoiced = $invoiceByItem[$itemId] ?? BigDecimal::zero()->toScale(4);
            $posted = $postedByItem[$itemId] ?? BigDecimal::zero()->toScale(4);
            if ($invoiced->isZero()) {
                $errors["lines.{$index}.item_id"] = 'Mặt hàng không tồn tại trong hóa đơn gốc.';

                continue;
            }
            if ($posted->plus($quantity)->isGreaterThan($invoiced)) {
                $remaining = $invoiced->minus($posted)->toScale(4)->__toString();
                $errors["lines.{$index}.quantity"] = "Số lượng còn được trả cho mặt hàng này là {$remaining}.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<string, BigDecimal> */
    private function postedReturnQuantities(string $headerTable, string $lineTable, string $foreignKey, Model $adjustment, string $movementColumn): array
    {
        $rows = DB::table("{$lineTable} as lines")
            ->join("{$headerTable} as headers", "lines.{$foreignKey}", '=', 'headers.id')
            ->where('headers.company_id', $adjustment->company_id)
            ->where('headers.reference_invoice_id', $adjustment->reference_invoice_id)
            ->where('headers.is_posted', true)
            ->where('headers.'.$movementColumn, true)
            ->where('headers.id', '<>', $adjustment->getKey())
            ->whereNotNull('lines.item_id')
            ->select(['lines.item_id', 'lines.quantity'])
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $itemId = (string) $row->item_id;
            $result[$itemId] = ($result[$itemId] ?? BigDecimal::zero()->toScale(4))->plus($this->quantity($row->quantity));
        }

        return $result;
    }

    private function assertDiscountValue(Model $adjustment, string $table, PurchaseInvoice|SalesInvoice $invoice): void
    {
        $existingDiscounts = DB::table($table)
            ->where('company_id', $adjustment->company_id)
            ->where('reference_invoice_id', $invoice->getKey())
            ->where('is_posted', true)
            ->where('is_decrease_debt', true)
            ->where('id', '<>', $adjustment->getKey())
            ->pluck('total_amount')
            ->map(static fn ($value): string => (string) $value)
            ->all();
        $returnTable = $invoice instanceof PurchaseInvoice ? 'purchase_returns' : 'sales_returns';
        $existingReturns = DB::table($returnTable)
            ->where('company_id', $adjustment->company_id)
            ->where('reference_invoice_id', $invoice->getKey())
            ->where('is_posted', true)
            ->where('is_decrease_debt', true)
            ->pluck('total_amount')
            ->map(static fn ($value): string => (string) $value)
            ->all();
        $used = DecimalMoney::sum([...$existingDiscounts, ...$existingReturns]);
        $requested = DecimalMoney::normalize($adjustment->getRawOriginal('total_amount'));
        $available = DecimalMoney::normalize($invoice->getRawOriginal('total_amount'));
        if (DecimalMoney::compare(DecimalMoney::add($used, $requested), $available) > 0) {
            throw ValidationException::withMessages([
                'total_amount' => 'Số tiền giảm giá vượt quá phần còn được giảm của hóa đơn gốc.',
            ]);
        }
    }

    private function quantity(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? 0))->toScale(4);
    }
}
