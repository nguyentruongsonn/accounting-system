<?php

namespace App\Services;

use App\Models\CashPayment;
use App\Models\CashReceipt;

/** Server-side cash-book projection for S03a1/S03a2 style reports. */
final class CashBookReportService
{
    public function run(int $companyId, array $filters = []): array
    {
        $status = $filters['status'] ?? null;
        $source = $filters['source'] ?? 'both';
        $search = trim((string) ($filters['search'] ?? ''));
        $account = trim((string) ($filters['account'] ?? ''));
        $records = collect();
        if ($source !== 'payment') $records = $records->concat($this->query(CashReceipt::class, $companyId, $filters, $status, $search));
        if ($source !== 'receipt') $records = $records->concat($this->query(CashPayment::class, $companyId, $filters, $status, $search));

        $rows = $records->flatMap(function ($voucher) use ($account, $filters) {
            $lines = $voucher->lines;
            if ($account !== '') $lines = $lines->filter(fn ($line) => str_starts_with((string) $line->debit_account, $account) || str_starts_with((string) $line->credit_account, $account));
            if (($filters['exclude_bank_transfers'] ?? true) && $lines->contains(fn ($line) => str_starts_with((string) $line->debit_account, '112') || str_starts_with((string) $line->credit_account, '112'))) return [];
            if ($lines->isEmpty()) return [];
            return [[
                'id' => $voucher->id, 'source' => $voucher instanceof CashReceipt ? 'receipt' : 'payment',
                'voucher_type' => $voucher->voucher_type, 'voucher_number' => $voucher->voucher_number,
                'voucher_date' => optional($voucher->voucher_date)->toDateString(), 'posting_date' => optional($voucher->posting_date)->toDateString(),
                'description' => $voucher->reason, 'contact_name' => $voucher->contact_name ?? $voucher->payer_name ?? $voucher->receiver_name,
                'amount' => (int) $lines->sum(fn ($line) => (int) $line->amount), 'is_posted' => (bool) $voucher->is_posted,
                'status' => (bool) $voucher->is_posted ? 'posted' : ($voucher->status ?? 'draft'),
                'lines' => $lines->values()->map(fn ($line) => ['debit_account' => $line->debit_account, 'credit_account' => $line->credit_account, 'amount' => (int) $line->amount, 'description' => $line->description])->all(),
            ]];
        })->sortBy(fn ($row) => ($row['posting_date'] ?? '').'|'.$row['voucher_number'])->values();
        $receipts = (int) $rows->where('source', 'receipt')->sum('amount');
        $payments = (int) $rows->where('source', 'payment')->sum('amount');
        return ['data' => $rows->all(), 'summary' => ['total_receipts' => $receipts, 'total_payments' => $payments, 'net_cash_flow' => $receipts - $payments], 'meta' => ['company_id' => $companyId, 'date_from' => $filters['date_from'] ?? null, 'date_to' => $filters['date_to'] ?? null, 'source' => $source, 'posted_only' => $status === null, 'excluded_bank_accounts' => (bool) ($filters['exclude_bank_transfers'] ?? true)]];
    }

    private function query(string $model, int $companyId, array $filters, ?string $status, string $search)
    {
        $query = $model::with('lines')->where('company_id', $companyId);
        if (! empty($filters['date_from'])) $query->whereDate('posting_date', '>=', $filters['date_from']);
        if (! empty($filters['date_to'])) $query->whereDate('posting_date', '<=', $filters['date_to']);
        if ($status === 'posted') $query->where('is_posted', true);
        elseif ($status !== null) $query->where('status', $status)->where('is_posted', false);
        else $query->where('is_posted', true);
        if ($search !== '') $query->where(fn ($q) => $q->where('voucher_number', 'like', "%{$search}%")->orWhere('reason', 'like', "%{$search}%")->orWhere('contact_name', 'like', "%{$search}%"));
        return $query->get();
    }
}
