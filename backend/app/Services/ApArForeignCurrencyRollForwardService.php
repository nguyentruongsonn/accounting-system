<?php

namespace App\Services;

use App\Exceptions\ReportDefinitionUnavailableException;
use App\Models\ApArFxRevaluation;
use App\Models\SettlementAllocation;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Exact, cutoff-based FX open-item roll-forward. Not exposed until signed. */
final class ApArForeignCurrencyRollForwardService
{
    /** @return array{original_currency:string,original_open_amount:string,functional_open_amount:string,fx_adjustment_amount:string} */
    public function forInvoice(string $ledger, int $companyId, int $invoiceId, string $cutoff): array
    {
        $companyId = $this->requireCompanyId($companyId);
        if (! in_array($ledger, ['ap', 'ar'], true)) {
            throw new \InvalidArgumentException('AP/AR ledger must be ap or ar.');
        }

        $isAp = $ledger === 'ap';
        $table = $isAp ? 'purchase_invoices' : 'sales_invoices';
        $type = $isAp ? 'purchase_invoice' : 'sales_invoice';
        $invoice = DB::table($table)->where('company_id', $companyId)->where('id', $invoiceId)->where('is_posted', true)->first();
        if ($invoice === null || strtoupper((string) $invoice->currency) === 'VND'
            || $invoice->functional_currency_code !== 'VND'
            || $invoice->functional_total_amount_raw === null || $invoice->functional_total_amount_scale === null
            || $invoice->original_total_amount_raw === null || $invoice->original_total_amount_scale === null) {
            throw new ReportDefinitionUnavailableException($isAp ? 'accounts_payable_aging.v2' : 'accounts_receivable_aging.v2');
        }
        $functionalTotal = $this->amount($invoice->functional_total_amount_raw, (int) $invoice->functional_total_amount_scale);
        $originalTotal = $this->amount($invoice->original_total_amount_raw, (int) $invoice->original_total_amount_scale);
        $rows = SettlementAllocation::withoutGlobalScope('company')->where('company_id', $companyId)->where('target_document_type', $type)->where('target_document_id', $invoiceId)->where('status', 'posted')->whereDate('effective_date', '<=', $cutoff)->get();
        $functionalAllocated = DecimalMoney::ZERO;
        $originalAllocated = DecimalMoney::ZERO;
        foreach ($rows as $row) {
            if ($row->functional_currency_code !== 'VND' || $row->functional_amount_raw === null || $row->functional_amount_scale === null || $row->original_currency_code !== strtoupper((string) $invoice->currency) || $row->original_amount_raw === null || $row->original_amount_scale === null) {
                throw new ReportDefinitionUnavailableException($isAp ? 'accounts_payable_aging.v2' : 'accounts_receivable_aging.v2');
            }
            $sign = $row->allocation_direction === 'reversal' ? '-' : '+';
            $functionalAllocated = $this->apply($functionalAllocated, $this->amount($row->functional_amount_raw, (int) $row->functional_amount_scale), $sign);
            $originalAllocated = $this->apply($originalAllocated, $this->amount($row->original_amount_raw, (int) $row->original_amount_scale), $sign);
        }
        $fx = DecimalMoney::ZERO;
        $revaluations = ApArFxRevaluation::withoutGlobalScope('company')->where('company_id', $companyId)->where('ledger', $ledger)->where('reference_document_type', $type)->where('reference_document_id', $invoiceId)->where('status', 'posted')->whereDate('accounting_date', '<=', $cutoff)->get();
        foreach ($revaluations as $row) {
            $positive = $ledger === 'ar' ? $row->effect === 'gain' : $row->effect === 'loss';
            $fx = $this->apply($fx, DecimalMoney::normalize((string) $row->adjustment_functional_amount), $positive ? '+' : '-');
        }
        return ['original_currency' => strtoupper((string) $invoice->currency), 'original_open_amount' => DecimalMoney::subtract($originalTotal, $originalAllocated), 'functional_open_amount' => DecimalMoney::add(DecimalMoney::subtract($functionalTotal, $functionalAllocated), $fx), 'fx_adjustment_amount' => $fx];
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

    private function amount(string $raw, int $scale): string
    {
        if ($scale < 0 || $scale > 2 || !preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $raw) || strlen(explode('.', $raw)[1] ?? '') > $scale) throw new \InvalidArgumentException('Invalid exact dual-currency evidence.');
        return DecimalMoney::normalize($scale === 0 ? $raw.'.00' : $raw);
    }
    private function apply(string $left, string $right, string $sign): string { return $sign === '+' ? DecimalMoney::add($left, $right) : DecimalMoney::subtract($left, $right); }
}
