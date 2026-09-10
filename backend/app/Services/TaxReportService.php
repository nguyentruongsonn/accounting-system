<?php

namespace App\Services;

use App\Models\JournalEntryLine;
use Carbon\Carbon;

class TaxReportService
{
    /**
     * Get data for VAT Declaration (Tờ khai thuế GTGT - Mẫu 01/GTGT)
     * Queries the General Ledger for input VAT (1331) and output VAT (33311)
     */
    public function getVATReport($company_id, $startDate, $endDate)
    {
        $startDate = Carbon::parse($startDate)->startOfDay();
        $endDate = Carbon::parse($endDate)->endOfDay();

        $inputVatQuery = JournalEntryLine::whereHas('journalEntry', function($q) use ($company_id, $startDate, $endDate) {
            $q->where('company_id', $company_id)
              ->where('status', 'posted')
              ->whereBetween('posting_date', [$startDate, $endDate]);
        })
        ->where('account_code', 'like', '1331%');

        $inputVatAmount = $inputVatQuery->sum('debit_amount') - $inputVatQuery->sum('credit_amount');

        // Thuế GTGT đầu ra (Output VAT) - Tài khoản 33311*
        $outputVatQuery = JournalEntryLine::whereHas('journalEntry', function($q) use ($company_id, $startDate, $endDate) {
            $q->where('company_id', $company_id)
              ->where('status', 'posted')
              ->whereBetween('posting_date', [$startDate, $endDate]);
        })
        ->where('account_code', 'like', '33311%');

        $outputVatAmount = $outputVatQuery->sum('credit_amount') - $outputVatQuery->sum('debit_amount');

        // Doanh thu tương ứng đầu ra (Tài khoản 511*)
        // (Đây là ước tính doanh thu chịu thuế dựa trên doanh thu đã ghi nhận cùng kỳ)
        $revenueQuery = JournalEntryLine::whereHas('journalEntry', function($q) use ($company_id, $startDate, $endDate) {
            $q->where('company_id', $company_id)
              ->where('status', 'posted')
              ->whereBetween('posting_date', [$startDate, $endDate]);
        })
        ->where('account_code', 'like', '511%');
            
        $revenueAmount = $revenueQuery->sum('credit_amount') - $revenueQuery->sum('debit_amount');

        // Thuế phải nộp trong kỳ = Đầu ra - Đầu vào
        $taxPayable = $outputVatAmount - $inputVatAmount;
        $isRefundable = $taxPayable < 0;

        return [
            'company_id' => $company_id,
            'period' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ],
            'input_vat' => [
                'total_deductible_amount' => max(0, $inputVatAmount),
            ],
            'output_vat' => [
                'total_revenue' => max(0, $revenueAmount),
                'total_tax_amount' => max(0, $outputVatAmount),
            ],
            'summary' => [
                'tax_payable' => max(0, $taxPayable), // Thuế GTGT phải nộp
                'tax_refundable_or_carried_forward' => $isRefundable ? abs($taxPayable) : 0, // Thuế GTGT còn được khấu trừ chuyển kỳ sau
            ]
        ];
    }
}
