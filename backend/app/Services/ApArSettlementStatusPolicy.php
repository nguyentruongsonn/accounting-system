<?php

namespace App\Services;

use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\DB;

/**
 * One tenant-bound AP/AR settlement reducer used by voucher status and aging.
 *
 * Legacy invoice_id vouchers still get their historical status update. Once
 * canonical settlement allocations exist, those immutable rows are the source
 * of truth and always override that legacy status.
 */
final class ApArSettlementStatusPolicy
{
    public function allocatedAmount(PurchaseInvoice|SalesInvoice $invoice): string
    {
        return $this->reduce($invoice)['allocated'];
    }

    public function outstandingAmount(PurchaseInvoice|SalesInvoice $invoice): string
    {
        return $this->reduce($invoice)['outstanding'];
    }

    /**
     * Return the open amount as it was known at a report cutoff. This keeps
     * future-dated settlements out of historical AP/AR reports while
     * preserving the existing current-status behavior for callers that do
     * not provide a cutoff.
     */
    public function outstandingAmountAt(PurchaseInvoice|SalesInvoice $invoice, ?string $asOfDate): string
    {
        return $this->reduce($invoice, $asOfDate)['outstanding'];
    }

    public function statusFor(PurchaseInvoice|SalesInvoice $invoice): string
    {
        $reduced = $this->reduce($invoice);
        if (DecimalMoney::compare($reduced['allocated'], DecimalMoney::ZERO) <= 0) {
            return 'Unpaid';
        }

        return DecimalMoney::compare($reduced['outstanding'], DecimalMoney::ZERO) <= 0
            ? 'Paid'
            : 'Partially Paid';
    }

    public function sync(PurchaseInvoice|SalesInvoice $invoice): void
    {
        $rows = $this->rows($invoice);
        if ($rows->isEmpty()) {
            return;
        }

        $status = $this->statusFor($invoice);
        $updates = ['status' => $status];
        if (array_key_exists('payment_status', $invoice->getAttributes())) {
            $updates['payment_status'] = $status;
        }
        $invoice->forceFill($updates)->saveQuietly();
    }

    /** @return array{allocated:string,outstanding:string} */
    private function reduce(PurchaseInvoice|SalesInvoice $invoice, ?string $asOfDate = null): array
    {
        $total = DecimalMoney::normalize($invoice->getRawOriginal('total_amount') ?? $invoice->total_amount);
        $allocated = DecimalMoney::ZERO;
        foreach ($this->rows($invoice, $asOfDate) as $row) {
            $amount = $this->amount($row->amount_raw, (int) $row->amount_scale);
            $allocated = $row->allocation_direction === 'reversal'
                ? DecimalMoney::subtract($allocated, $amount)
                : DecimalMoney::add($allocated, $amount);
        }

        $allocated = DecimalMoney::maxZero($allocated);

        return ['allocated' => $allocated, 'outstanding' => DecimalMoney::maxZero(DecimalMoney::subtract($total, $allocated))];
    }

    private function rows(PurchaseInvoice|SalesInvoice $invoice, ?string $asOfDate = null)
    {
        return DB::table('settlement_allocations')
            ->where('company_id', (int) $invoice->company_id)
            ->where('target_document_type', $invoice instanceof PurchaseInvoice ? 'purchase_invoice' : 'sales_invoice')
            ->where('target_document_id', (int) $invoice->getKey())
            ->where('status', 'posted')
            ->when($asOfDate !== null, fn ($query) => $query->whereDate('effective_date', '<=', $asOfDate))
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get(['amount_raw', 'amount_scale', 'allocation_direction']);
    }

    private function amount(mixed $raw, int $scale): string
    {
        if ($scale === 0) {
            return DecimalMoney::normalize((string) $raw.'.00');
        }

        return DecimalMoney::normalize($raw);
    }
}
