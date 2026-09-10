<?php

namespace App\Services;

use App\Models\CashPayment;
use App\Models\PurchaseInvoice;
use App\Models\SettlementAllocation;
use App\Models\User;
use App\Support\DecimalMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the purchase-invoice -> cash-payment -> AP allocation workflow.
 *
 * A payment is deliberately created and posted before its typed settlement
 * row is written, but both operations run inside this service transaction. A
 * failed allocation therefore cannot leave an unallocated cash voucher in
 * the books.
 */
final class PurchaseInvoicePaymentService
{
    public function __construct(
        private readonly CashPaymentService $cashPayments,
        private readonly SettlementAllocationService $allocations,
        private readonly ApArSettlementStatusPolicy $settlements,
    ) {}

    /**
     * Return posted purchase invoices with a positive open balance.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function outstanding(int $companyId, ?int $supplierId = null, ?string $asOfDate = null): Collection
    {
        $this->assertTenant($companyId);
        $cutoff = $this->normaliseCutoff($asOfDate);

        return PurchaseInvoice::query()
            ->with('supplier')
            ->where('company_id', $companyId)
            ->where('is_posted', true)
            ->where(function ($query): void {
                $query->whereNull('status')->orWhereNotIn('status', ['voided', 'cancelled', 'canceled']);
            })
            ->when($supplierId !== null, fn ($query) => $query->where('supplier_id', $supplierId))
            ->whereRaw('COALESCE(accounting_date, invoice_date) <= ?', [$cutoff])
            ->orderByRaw('COALESCE(due_date, accounting_date, invoice_date) asc')
            ->orderBy('id')
            ->get()
            ->map(function (PurchaseInvoice $invoice) use ($cutoff): ?array {
                $total = DecimalMoney::normalize($invoice->getRawOriginal('total_amount') ?? $invoice->total_amount);
                $remaining = $this->settlements->outstandingAmountAt($invoice, $cutoff);
                if (DecimalMoney::compare($remaining, DecimalMoney::ZERO) <= 0) {
                    return null;
                }

                return [
                    'id' => (int) $invoice->id,
                    'voucher_number' => $invoice->voucher_number ?? $invoice->invoice_number,
                    'invoice_number' => $invoice->invoice_number,
                    'voucher_date' => optional($invoice->voucher_date ?? $invoice->invoice_date)->toDateString(),
                    'invoice_date' => optional($invoice->invoice_date)->toDateString(),
                    'accounting_date' => optional($invoice->accounting_date ?? $invoice->invoice_date)->toDateString(),
                    'due_date' => optional($invoice->due_date ?? $invoice->accounting_date ?? $invoice->invoice_date)->toDateString(),
                    'description' => $invoice->description,
                    'supplier_id' => (int) $invoice->supplier_id,
                    'supplier_name' => $invoice->supplier_name ?? $invoice->supplier?->name,
                    'currency' => $invoice->currency ?? 'VND',
                    'total_amount' => $total,
                    'allocated_amount' => DecimalMoney::subtract($total, $remaining),
                    'remaining_debt' => $remaining,
                    'status' => 'open',
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Create a posted cash payment and allocate it to one posted invoice.
     *
     * @param  array<string, mixed>  $data
     * @return array{payment:CashPayment,allocation:SettlementAllocation,invoice:PurchaseInvoice}
     */
    public function pay(User $actor, int $invoiceId, array $data): array
    {
        $companyId = (int) $actor->company_id;
        $this->assertTenant($companyId);

        return DB::transaction(function () use ($actor, $companyId, $invoiceId, $data): array {
            /** @var PurchaseInvoice $invoice */
            $invoice = PurchaseInvoice::query()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($invoiceId);
            if (! $invoice->is_posted || in_array(strtolower((string) $invoice->status), ['voided', 'cancelled', 'canceled'], true)) {
                throw ValidationException::withMessages([
                    'invoice_id' => 'Chỉ được thanh toán hóa đơn mua hàng đã ghi sổ và chưa hủy.',
                ]);
            }

            $amount = $this->paymentAmount($data['amount_raw'] ?? null, $this->settlementScale($data['amount_scale'] ?? 0));
            $remaining = $this->settlements->outstandingAmount($invoice);
            if (DecimalMoney::compare($amount, $remaining) > 0) {
                throw ValidationException::withMessages([
                    'amount_raw' => 'Số tiền thanh toán không được vượt quá số còn phải trả của hóa đơn.',
                    'remaining_debt' => $remaining,
                ]);
            }

            $voucherDate = $this->requiredDate($data['voucher_date'] ?? null, 'voucher_date');
            $postingDate = $this->requiredDate($data['posting_date'] ?? $voucherDate, 'posting_date');
            $voucherNumber = trim((string) ($data['voucher_number'] ?? ''));
            if ($voucherNumber === '') {
                throw ValidationException::withMessages(['voucher_number' => 'Số phiếu chi là bắt buộc để chống gửi lặp.']);
            }

            $payment = $this->cashPayments->create([
                'company_id' => $companyId,
                'voucher_type' => '8. Chi khác',
                'contact_type' => 'supplier',
                'contact_id' => (int) $invoice->supplier_id,
                'contact_name' => $invoice->supplier_name ?? $invoice->supplier?->name,
                'receiver_name' => $data['receiver_name'] ?? $invoice->supplier_name ?? $invoice->supplier?->name,
                'voucher_number' => $voucherNumber,
                'voucher_date' => $voucherDate,
                'posting_date' => $postingDate,
                'reason' => $data['description'] ?? ('Thanh toán hóa đơn '.$invoice->invoice_number),
                'currency' => $data['currency'] ?? ($invoice->currency ?? 'VND'),
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'auto_post' => true,
                'lines' => [[
                    'description' => $data['description'] ?? ('Thanh toán hóa đơn '.$invoice->invoice_number),
                    'debit_account' => trim((string) ($data['debit_account'] ?? '')),
                    'credit_account' => trim((string) ($data['credit_account'] ?? '')),
                    'amount' => $this->integerAmount($amount),
                    'line_contact_id' => (int) $invoice->supplier_id,
                    'line_contact_name' => $invoice->supplier_name ?? $invoice->supplier?->name,
                    'invoice_id' => $invoice->id,
                ]],
            ]);
            $line = $payment->lines->first();
            if ($line === null) {
                throw ValidationException::withMessages(['amount_raw' => 'Phiếu chi không có dòng thanh toán hợp lệ.']);
            }

            $allocation = $this->allocations->createPosted($actor, [
                'source_document_type' => 'cash_payment',
                'source_document_id' => $payment->id,
                'source_line_type' => 'cash_payment_line',
                'source_line_id' => $line->id,
                'target_document_type' => 'purchase_invoice',
                'target_document_id' => $invoice->id,
                'allocation_kind' => 'settlement',
                'amount_raw' => $this->integerAmount($amount),
                'amount_scale' => 0,
                'currency_code' => strtoupper((string) ($data['currency'] ?? $invoice->currency ?? 'VND')),
                'effective_date' => $postingDate,
            ]);

            return [
                'payment' => $payment->fresh(['lines', 'references']),
                'allocation' => $allocation,
                'invoice' => $invoice->fresh(),
            ];
        });
    }

    private function assertTenant(int $companyId): void
    {
        $actorCompanyId = auth()->user()?->company_id;
        if ($companyId < 1 || ($actorCompanyId !== null && (int) $actorCompanyId !== $companyId)) {
            throw new AuthorizationException('The requested company does not belong to the authenticated user.');
        }
    }

    private function normaliseCutoff(?string $date): string
    {
        if ($date === null || trim($date) === '') {
            return now()->toDateString();
        }
        $value = trim($date);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages(['as_of_date' => 'Ngày chốt phải đúng định dạng YYYY-MM-DD.']);
        }

        return $value;
    }

    private function settlementScale(mixed $scale): int
    {
        if ((int) $scale !== 0) {
            throw ValidationException::withMessages(['amount_scale' => 'Thanh toán tiền mặt chỉ dùng số tiền nguyên VND (scale 0).']);
        }

        return 0;
    }

    private function paymentAmount(mixed $raw, int $scale): string
    {
        if ($raw === null || trim((string) $raw) === '') {
            throw ValidationException::withMessages(['amount_raw' => 'Số tiền thanh toán là bắt buộc.']);
        }
        $rawValue = trim((string) $raw);
        if ($scale === 0 && ! preg_match('/^(?:0|[1-9]\d*)$/', $rawValue)) {
            throw ValidationException::withMessages(['amount_raw' => 'Số tiền thanh toán phải là số nguyên VND.']);
        }
        $amount = DecimalMoney::normalize($rawValue);
        if (DecimalMoney::compare($amount, DecimalMoney::ZERO) <= 0) {
            throw ValidationException::withMessages(['amount_raw' => 'Số tiền thanh toán phải lớn hơn 0.']);
        }

        return $amount;
    }

    private function integerAmount(string $amount): string
    {
        $integer = substr($amount, 0, -3);
        if ($integer === '' || ! ctype_digit($integer) || (int) $integer < 1) {
            throw ValidationException::withMessages(['amount_raw' => 'Số tiền thanh toán vượt định dạng VND hợp lệ.']);
        }

        return $integer;
    }

    private function requiredDate(mixed $date, string $field): string
    {
        $value = trim((string) $date);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$field => 'Ngày phải đúng định dạng YYYY-MM-DD.']);
        }

        return $value;
    }
}
