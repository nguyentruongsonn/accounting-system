<?php

namespace App\Services;

use App\Models\Period;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PeriodDraftDocumentService
{
    /**
     * Return every unposted/draft accounting document that would make a
     * period close misleading.  Operational orders and contracts are not
     * included because they do not affect the ledger until a voucher is
     * created and posted.
     *
     * @return array{count:int,documents:list<array{type:string,id:int,number:string,date:string}>}
     */
    public function forPeriod(int $companyId, Period $period): array
    {
        $definitions = [
            ['table' => 'journal_entries', 'date' => 'posting_date', 'mode' => 'status', 'values' => ['draft', 'approved'], 'type' => 'journal_entry', 'number' => 'voucher_number'],
            ['table' => 'cash_receipts', 'date' => 'posting_date', 'mode' => 'is_posted', 'type' => 'cash_receipt', 'number' => 'voucher_number'],
            ['table' => 'cash_payments', 'date' => 'posting_date', 'mode' => 'is_posted', 'type' => 'cash_payment', 'number' => 'voucher_number'],
            ['table' => 'bank_receipts', 'date' => 'posting_date', 'mode' => 'is_posted', 'type' => 'bank_receipt', 'number' => 'voucher_number'],
            ['table' => 'bank_payments', 'date' => 'posting_date', 'mode' => 'is_posted', 'type' => 'bank_payment', 'number' => 'voucher_number'],
            ['table' => 'purchase_invoices', 'date' => 'invoice_date', 'mode' => 'is_posted', 'type' => 'purchase_invoice', 'number' => 'invoice_number'],
            ['table' => 'sales_invoices', 'date' => 'invoice_date', 'mode' => 'is_posted', 'type' => 'sales_invoice', 'number' => 'invoice_number'],
            ['table' => 'inventory_receipts', 'date' => 'voucher_date', 'mode' => 'is_posted', 'type' => 'inventory_receipt', 'number' => 'voucher_number'],
            ['table' => 'inventory_issues', 'date' => 'voucher_date', 'mode' => 'is_posted', 'type' => 'inventory_issue', 'number' => 'voucher_number'],
            ['table' => 'inventory_transfers', 'date' => 'transfer_date', 'mode' => 'is_posted', 'type' => 'inventory_transfer', 'number' => 'transfer_number'],
            ['table' => 'inventory_stock_counts', 'date' => 'count_date', 'mode' => 'is_posted', 'type' => 'inventory_stock_count', 'number' => 'count_number'],
            ['table' => 'purchase_returns', 'date' => 'voucher_date', 'mode' => 'status', 'values' => ['draft'], 'type' => 'purchase_return', 'number' => 'voucher_number'],
            ['table' => 'purchase_discounts', 'date' => 'voucher_date', 'mode' => 'status', 'values' => ['draft'], 'type' => 'purchase_discount', 'number' => 'voucher_number'],
            ['table' => 'sales_returns', 'date' => 'voucher_date', 'mode' => 'status', 'values' => ['draft'], 'type' => 'sales_return', 'number' => 'voucher_number'],
            ['table' => 'sales_discounts', 'date' => 'voucher_date', 'mode' => 'status', 'values' => ['draft'], 'type' => 'sales_discount', 'number' => 'voucher_number'],
            ['table' => 'opening_balance_packages', 'date' => 'effective_date', 'mode' => 'status', 'values' => ['draft'], 'type' => 'opening_balance', 'number' => 'id'],
        ];

        $documents = [];
        foreach ($definitions as $definition) {
            if (! Schema::hasTable($definition['table'])
                || ! Schema::hasColumns($definition['table'], ['company_id', $definition['date'], $definition['mode']])) {
                continue;
            }

            $query = DB::table($definition['table'])
                ->where('company_id', $companyId)
                ->whereBetween($definition['date'], [$period->start_date->toDateString(), $period->end_date->toDateString()]);
            if ($definition['mode'] === 'status') {
                $query->whereIn('status', $definition['values']);
            } else {
                $query->where($definition['mode'], false);
            }

            foreach ($query->orderBy('id')->get(['id', $definition['number'], $definition['date']]) as $row) {
                $documents[] = [
                    'type' => $definition['type'],
                    'id' => (int) $row->id,
                    'number' => (string) ($row->{$definition['number']} ?? $row->id),
                    'date' => (string) $row->{$definition['date']},
                ];
            }
        }

        return ['count' => count($documents), 'documents' => $documents];
    }
}
