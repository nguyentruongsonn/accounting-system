<?php

namespace App\Services;

use App\Models\SalesDiscount;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Support\DecimalMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Server-side source for the internal sales activity report.
 *
 * The report is deliberately limited to posted sales documents. Returns and
 * discounts are exposed as signed reductions while their source amounts stay
 * positive, so the result is auditable without inventing report templates.
 */
final class SalesManagementReportService
{
    /**
     * @return array{data: list<array<string, mixed>>, totals: array<string, string>, meta: array<string, mixed>}
     */
    public function generate(int $companyId, ?string $fromDate = null, ?string $toDate = null, ?int $customerId = null): array
    {
        $rows = [];
        $sources = [
            [SalesInvoice::class, 'sales_invoice', 'Bán hàng', 'invoice_date'],
            [SalesReturn::class, 'sales_return', 'Hàng bán trả lại', 'voucher_date'],
            [SalesDiscount::class, 'sales_discount', 'Giảm giá hàng bán', 'voucher_date'],
        ];

        foreach ($sources as [$modelClass, $sourceType, $sourceLabel, $fallbackDateColumn]) {
            /** @var class-string<Model> $modelClass */
            $query = $modelClass::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('is_posted', true)
                ->with('customer');

            if ($customerId !== null) {
                $query->where('customer_id', $customerId);
            }

            $this->applyDateFilter($query, $fromDate, $toDate, $fallbackDateColumn);

            foreach ($query->get() as $document) {
                $subTotal = $this->money($document, 'sub_total');
                $discountAmount = $this->money($document, 'discount_amount');
                $taxAmount = $this->money($document, 'tax_amount');
                $totalAmount = $this->moneyWithGrandTotalFallback($document);
                $isReduction = $sourceType !== 'sales_invoice';

                $rows[] = [
                    'id' => (int) $document->getKey(),
                    'source_type' => $sourceType,
                    'source_label' => $sourceLabel,
                    'voucher_number' => (string) ($document->getAttribute('invoice_number') ?? $document->getAttribute('voucher_number') ?? ''),
                    'voucher_date' => $this->dateValue($document, $fallbackDateColumn),
                    'accounting_date' => $this->dateValue($document, 'accounting_date') ?? $this->dateValue($document, $fallbackDateColumn),
                    'customer_id' => $document->getAttribute('customer_id') === null ? null : (int) $document->getAttribute('customer_id'),
                    'customer_name' => (string) ($document->getAttribute('customer_name') ?? $document->customer?->name ?? ''),
                    'description' => $document->getAttribute('description') ?? $document->getAttribute('reason'),
                    'sub_total' => $subTotal,
                    'discount_amount' => $discountAmount,
                    'tax_amount' => $taxAmount,
                    'total_amount' => $totalAmount,
                    'signed_sub_total' => $isReduction ? DecimalMoney::negate($subTotal) : $subTotal,
                    'signed_discount_amount' => $isReduction ? DecimalMoney::negate($discountAmount) : $discountAmount,
                    'signed_tax_amount' => $isReduction ? DecimalMoney::negate($taxAmount) : $taxAmount,
                    'signed_total_amount' => $isReduction ? DecimalMoney::negate($totalAmount) : $totalAmount,
                    'is_posted' => true,
                ];
            }
        }

        usort($rows, static function (array $left, array $right): int {
            $dateComparison = strcmp((string) ($right['accounting_date'] ?? ''), (string) ($left['accounting_date'] ?? ''));
            if ($dateComparison !== 0) {
                return $dateComparison;
            }

            $sourceComparison = strcmp((string) $left['source_type'], (string) $right['source_type']);
            if ($sourceComparison !== 0) {
                return $sourceComparison;
            }

            return ((int) $left['id']) <=> ((int) $right['id']);
        });

        return [
            'data' => $rows,
            'totals' => [
                'sub_total' => DecimalMoney::sum(array_column($rows, 'signed_sub_total')),
                'discount_amount' => DecimalMoney::sum(array_column($rows, 'signed_discount_amount')),
                'tax_amount' => DecimalMoney::sum(array_column($rows, 'signed_tax_amount')),
                'total_amount' => DecimalMoney::sum(array_column($rows, 'signed_total_amount')),
            ],
            'meta' => [
                'report_key' => 'sales_activity',
                'date_basis' => 'accounting_date',
                'source' => ['sales_invoices', 'sales_returns', 'sales_discounts'],
                'posted_only' => true,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'customer_id' => $customerId,
            ],
        ];
    }

    private function applyDateFilter(Builder $query, ?string $fromDate, ?string $toDate, string $fallbackDateColumn): void
    {
        if ($fromDate !== null) {
            $query->where(function (Builder $dateQuery) use ($fromDate, $fallbackDateColumn): void {
                $dateQuery->whereDate('accounting_date', '>=', $fromDate)
                    ->orWhere(function (Builder $fallbackQuery) use ($fromDate, $fallbackDateColumn): void {
                        $fallbackQuery->whereNull('accounting_date')->whereDate($fallbackDateColumn, '>=', $fromDate);
                    });
            });
        }

        if ($toDate !== null) {
            $query->where(function (Builder $dateQuery) use ($toDate, $fallbackDateColumn): void {
                $dateQuery->whereDate('accounting_date', '<=', $toDate)
                    ->orWhere(function (Builder $fallbackQuery) use ($toDate, $fallbackDateColumn): void {
                        $fallbackQuery->whereNull('accounting_date')->whereDate($fallbackDateColumn, '<=', $toDate);
                    });
            });
        }
    }

    private function money(Model $document, string $column): string
    {
        $raw = $document->getRawOriginal($column);
        if ($raw === null || $raw === '') {
            $raw = $document->getAttribute($column);
        }

        return DecimalMoney::normalize($raw ?? DecimalMoney::ZERO);
    }

    private function moneyWithGrandTotalFallback(Model $document): string
    {
        $total = $this->money($document, 'total_amount');
        $grandTotal = $this->money($document, 'grand_total');

        return DecimalMoney::compare($total, DecimalMoney::ZERO) === 0
            && DecimalMoney::compare($grandTotal, DecimalMoney::ZERO) !== 0
            ? $grandTotal
            : $total;
    }

    private function dateValue(Model $document, string $column): ?string
    {
        $value = $document->getAttribute($column);

        return $value === null ? null : (method_exists($value, 'toDateString') ? $value->toDateString() : (string) $value);
    }
}
