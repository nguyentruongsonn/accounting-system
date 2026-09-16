<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Support\DecimalMoney;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class ARAgingService
{
    public function __construct(private readonly ApArSettlementStatusPolicy $settlements) {}

    public function generateReport(int $companyId, ?string $asOfDate = null): array
    {
        $companyId = $this->requireCompanyId($companyId);
        $cutoff = $this->cutoff($asOfDate);
        $customers = Customer::where('company_id', $companyId)->get();

        $invoices = SalesInvoice::where('company_id', $companyId)
            ->where('is_posted', true)
            ->where(function ($query): void {
                $query->whereNull('status')->orWhereNotIn('status', ['voided', 'cancelled', 'canceled']);
            })
            ->whereRaw('COALESCE(accounting_date, invoice_date) <= ?', [$cutoff->toDateString()])
            ->get();

        $agingReport = [];

        foreach ($customers as $customer) {
            $customerInvoices = $invoices->where('customer_id', $customer->id);

            $total_due = DecimalMoney::ZERO;
            $current = DecimalMoney::ZERO;
            $days_1_30 = DecimalMoney::ZERO;
            $days_31_60 = DecimalMoney::ZERO;
            $days_over_60 = DecimalMoney::ZERO;

            foreach ($customerInvoices as $inv) {
                $unpaidAmount = $this->settlements->outstandingAmountAt($inv, $cutoff->toDateString());
                if (DecimalMoney::compare($unpaidAmount, DecimalMoney::ZERO) <= 0) {
                    continue;
                }
                $total_due = DecimalMoney::add($total_due, $unpaidAmount);

                $dueDate = Carbon::parse($inv->due_date ?? $inv->accounting_date ?? $inv->invoice_date);

                if ($dueDate->greaterThanOrEqualTo($cutoff)) {
                    $current = DecimalMoney::add($current, $unpaidAmount);
                } else {
                    $daysPastDue = $cutoff->diffInDays($dueDate);
                    if ($daysPastDue <= 30) {
                        $days_1_30 = DecimalMoney::add($days_1_30, $unpaidAmount);
                    } elseif ($daysPastDue <= 60) {
                        $days_31_60 = DecimalMoney::add($days_31_60, $unpaidAmount);
                    } else {
                        $days_over_60 = DecimalMoney::add($days_over_60, $unpaidAmount);
                    }
                }
            }

            if (DecimalMoney::compare($total_due, DecimalMoney::ZERO) > 0) {
                $agingReport[] = [
                    'customer_id' => $customer->id,
                    'customer_code' => $customer->code,
                    'customer_name' => $customer->name,
                    'total_due' => $total_due,
                    'current' => $current,
                    'days_1_30' => $days_1_30,
                    'days_31_60' => $days_31_60,
                    'days_over_60' => $days_over_60,
                ];
            }
        }

        return $agingReport;
    }

    private function requireCompanyId(int $companyId): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        if ($companyId < 1 || ($actorCompanyId !== null && (int) $actorCompanyId !== $companyId)) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return $companyId;
    }

    private function cutoff(?string $asOfDate): Carbon
    {
        if ($asOfDate === null || trim($asOfDate) === '') {
            return Carbon::today();
        }
        try {
            $cutoff = Carbon::createFromFormat('!Y-m-d', $asOfDate);
        } catch (\Throwable) {
            $cutoff = false;
        }
        if ($cutoff === false || $cutoff->format('Y-m-d') !== $asOfDate) {
            throw ValidationException::withMessages(['as_of_date' => 'Ngày chốt phải đúng định dạng YYYY-MM-DD.']);
        }

        return $cutoff;
    }
}
