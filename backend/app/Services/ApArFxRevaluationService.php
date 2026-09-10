<?php

namespace App\Services;

use App\Models\ApArFxRevaluation;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\DecimalMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Typed, immutable AP/AR FX remeasurement evidence.
 *
 * It intentionally does not alter settlement allocations yet: the current
 * invoice schema has no approved original-currency open-item roll-forward.
 * This source makes the inputs and its GL impact auditable until that mapping
 * is supplied and the aging definition is approved.
 */
final class ApArFxRevaluationService
{
    public function __construct(
        private readonly JournalEntryService $journals,
        private readonly AuditService $audit,
        private readonly AccountingPeriodGuard $periodGuard,
    ) {}

    /** @param array<string,mixed> $data */
    public function create(User $actor, array $data): ApArFxRevaluation
    {
        $companyId = $this->companyId($actor);
        return DB::transaction(function () use ($actor, $companyId, $data): ApArFxRevaluation {
            $ledger = $this->ledger($data['ledger'] ?? null);
            $voucherDate = $this->date($data['voucher_date'] ?? null);
            $accountingDate = $this->date($data['accounting_date'] ?? null);
            $this->periodGuard->assertOpen($companyId, $accountingDate, 'lập chứng từ đánh giá lại tỷ giá công nợ');
            $reference = $this->reference($ledger, $data['reference_document_id'] ?? null, $companyId, false);
            $originalCurrency = $this->currency($data['original_currency'] ?? null);
            $this->assertReferenceCurrency($reference, $originalCurrency);
            [$carrying, $revalued, $adjustment, $effect] = $this->amounts($data, $ledger);
            $this->accounts($ledger, $effect, (string) ($data['debit_account'] ?? ''), (string) ($data['credit_account'] ?? ''));

            $row = ApArFxRevaluation::withoutGlobalScope('company')->create([
                'company_id' => $companyId, 'ledger' => $ledger,
                'reference_document_type' => $ledger === 'ap' ? 'purchase_invoice' : 'sales_invoice',
                'reference_document_id' => $reference->getKey(),
                'voucher_number' => $this->voucher($data['voucher_number'] ?? null, $companyId),
                'voucher_date' => $voucherDate,
                'accounting_date' => $accountingDate,
                'original_currency' => $originalCurrency,
                'foreign_open_amount_raw' => $this->exactRaw($data['foreign_open_amount_raw'] ?? null, $data['foreign_open_amount_scale'] ?? null, 'foreign_open_amount'),
                'foreign_open_amount_scale' => (int) $data['foreign_open_amount_scale'],
                'closing_exchange_rate_raw' => $this->exactRaw($data['closing_exchange_rate_raw'] ?? null, $data['closing_exchange_rate_scale'] ?? null, 'closing_exchange_rate'),
                'closing_exchange_rate_scale' => (int) $data['closing_exchange_rate_scale'],
                'carrying_functional_amount' => $carrying, 'revalued_functional_amount' => $revalued,
                'adjustment_functional_amount' => $adjustment, 'effect' => $effect,
                'debit_account' => $data['debit_account'], 'credit_account' => $data['credit_account'],
                'reason' => trim((string) $data['reason']), 'status' => 'draft', 'is_posted' => false, 'created_by' => $actor->getKey(),
            ]);
            $this->audit->record($row, 'ap_ar_fx_revaluation.created', [], $row->getAttributes(), null, ['domain' => 'ap_ar_fx_revaluation', 'operation' => 'created']);
            return $row;
        });
    }

    public function post(User $actor, int $id): ApArFxRevaluation
    {
        $companyId = $this->companyId($actor);
        return DB::transaction(function () use ($actor, $companyId, $id): ApArFxRevaluation {
            $row = ApArFxRevaluation::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($id);
            if ((int) $row->company_id !== $companyId || $row->is_posted || $row->status !== 'draft') throw new AuthorizationException('The FX revaluation is not a postable draft in the actor tenant.');
            $this->periodGuard->assertOpen($companyId, $row->accounting_date ?? $row->voucher_date, 'ghi sổ chứng từ đánh giá lại tỷ giá công nợ');
            $this->reference($row->ledger, $row->reference_document_id, $companyId, true);
            $journal = $this->journals->createPosted([
                'company_id' => $companyId, 'voucher_type' => 'ap_ar_fx_revaluation', 'voucher_number' => 'GL-'.$row->voucher_number,
                'voucher_date' => $row->voucher_date->toDateString(), 'posting_date' => $row->accounting_date->toDateString(),
                'description' => $row->reason, 'source_document_type' => ApArFxRevaluation::class, 'source_document_id' => $row->getKey(),
                'lines' => [['debit_account' => $row->debit_account, 'credit_account' => $row->credit_account, 'amount' => (string) $row->adjustment_functional_amount, 'description' => $row->reason]],
            ]);
            $row->forceFill(['journal_entry_id' => $journal->getKey(), 'status' => 'posted', 'is_posted' => true, 'posted_by' => $actor->getKey(), 'posted_at' => now()])->save();
            $this->audit->record($row, 'ap_ar_fx_revaluation.posted', ['status' => 'draft'], $row->getAttributes(), null, ['domain' => 'ap_ar_fx_revaluation', 'operation' => 'posted']);
            return $row->fresh();
        });
    }

    public function reverse(User $actor, int $id, string $voucherNumber, string $date, string $reason): ApArFxRevaluation
    {
        $companyId = $this->companyId($actor);
        return DB::transaction(function () use ($actor, $companyId, $id, $voucherNumber, $date, $reason): ApArFxRevaluation {
            $original = ApArFxRevaluation::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($id);
            if ((int) $original->company_id !== $companyId || ! $original->is_posted) throw new AuthorizationException('The FX revaluation is not posted in the actor tenant.');
            if (ApArFxRevaluation::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('reversal_of_id', $original->getKey())
                ->exists()) throw new InvalidArgumentException('An FX revaluation may only be reversed once.');
            $reversalDate = $this->date($date);
            $this->periodGuard->assertOpen($companyId, $reversalDate, 'đảo chứng từ đánh giá lại tỷ giá công nợ');
            $reversal = ApArFxRevaluation::withoutGlobalScope('company')->create(array_merge($original->only([
                'company_id','ledger','reference_document_type','reference_document_id','original_currency','foreign_open_amount_raw','foreign_open_amount_scale','closing_exchange_rate_raw','closing_exchange_rate_scale','carrying_functional_amount','revalued_functional_amount','adjustment_functional_amount'
            ]), ['reversal_of_id' => $original->getKey(), 'voucher_number' => $this->voucher($voucherNumber, $companyId), 'voucher_date' => $reversalDate, 'accounting_date' => $reversalDate, 'effect' => $original->effect === 'gain' ? 'loss' : 'gain', 'debit_account' => $original->credit_account, 'credit_account' => $original->debit_account, 'reason' => trim($reason), 'status' => 'draft', 'is_posted' => false, 'created_by' => $actor->getKey()]));
            return $this->post($actor, $reversal->getKey());
        });
    }

    private function companyId(User $actor): int { if ((int) $actor->company_id < 1) throw new AuthorizationException('An actor company is required.'); return (int) $actor->company_id; }
    private function ledger(mixed $value): string { if (!in_array($value, ['ap','ar'], true)) throw new InvalidArgumentException('FX revaluation ledger must be ap or ar.'); return $value; }
    private function reference(string $ledger, mixed $id, int $companyId, bool $posted): Model { $class = $ledger === 'ap' ? PurchaseInvoice::class : SalesInvoice::class; $row = $class::withoutGlobalScope('company')->where('company_id', $companyId)->lockForUpdate()->find((int) $id); if ($row === null || ($posted && !$row->is_posted)) throw new AuthorizationException('The referenced open item is not posted in the actor tenant.'); return $row; }
    private function assertReferenceCurrency(Model $reference, string $currency): void { if (strtoupper((string) $reference->getAttribute('currency')) !== $currency) throw ValidationException::withMessages(['original_currency' => ['The FX revaluation currency must match the referenced open-item document.']]); }
    /** @return array{0:string,1:string,2:string,3:string} */ private function amounts(array $data, string $ledger): array { $carrying=$this->money($data['carrying_functional_amount']??null); $revalued=$this->money($data['revalued_functional_amount']??null); $adjustment=$this->money($data['adjustment_functional_amount']??null); $difference=DecimalMoney::abs(DecimalMoney::subtract($revalued,$carrying)); if (DecimalMoney::compare($difference, DecimalMoney::ZERO)<=0 || DecimalMoney::compare($adjustment,$difference)!==0) throw ValidationException::withMessages(['adjustment_functional_amount'=>['Adjustment must exactly equal the non-zero difference between carrying and revalued functional amount.']]); $increased=DecimalMoney::compare($revalued,$carrying)>0; $effect=$ledger==='ar' ? ($increased?'gain':'loss') : ($increased?'loss':'gain'); return [$carrying,$revalued,$adjustment,$effect]; }
    private function accounts(string $ledger,string $effect,string $debit,string $credit): void { $control=$ledger==='ap'?'331':'131'; $valid=($ledger==='ap' && (($effect==='loss'&&str_starts_with($credit,$control))||($effect==='gain'&&str_starts_with($debit,$control))))||($ledger==='ar' && (($effect==='gain'&&str_starts_with($debit,$control))||($effect==='loss'&&str_starts_with($credit,$control)))); if(!$valid || $debit===$credit) throw ValidationException::withMessages(['debit_account'=>['Accounts must place the AP/AR control account on the side implied by the exact FX remeasurement effect.']]); }
    private function money(mixed $value): string { if(!is_string($value)||!preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/',$value)) throw ValidationException::withMessages(['functional_amount'=>['Functional amounts must be positive exact decimals with at most two places.']]); $value=DecimalMoney::normalize($value); if(DecimalMoney::compare($value,DecimalMoney::ZERO)<0) throw ValidationException::withMessages(['functional_amount'=>['Functional amounts must not be negative.']]); return $value; }
    private function exactRaw(mixed $raw,mixed $scale,string $field): string { if(!is_string($raw)||filter_var($scale,FILTER_VALIDATE_INT)===false||(int)$scale<0||(int)$scale>12||!preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/',$raw)||strlen(explode('.',$raw)[1]??'')>(int)$scale) throw ValidationException::withMessages([$field=>['A non-negative exact raw value and its declared scale are required.']]); return $raw; }
    private function currency(mixed $value): string { $value=is_string($value)?strtoupper(trim($value)):''; if(!preg_match('/^[A-Z]{3}$/',$value)||$value==='VND') throw ValidationException::withMessages(['original_currency'=>['A non-VND ISO currency is required for FX revaluation.']]); return $value; }
    private function date(mixed $value): string { if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$value)||strtotime($value)===false) throw ValidationException::withMessages(['accounting_date'=>['A valid ISO accounting date is required.']]); return $value; }
    private function voucher(mixed $value,int $companyId): string { $value=is_string($value)?trim($value):''; if($value==='') $value='FX-'.str_pad((string)(ApArFxRevaluation::withoutGlobalScope('company')->where('company_id',$companyId)->count()+1),6,'0',STR_PAD_LEFT); if(mb_strlen($value)>80) throw ValidationException::withMessages(['voucher_number'=>['Voucher number is too long.']]); return $value; }
}
