<?php

namespace App\Services;

use App\Models\BankPaymentLine;
use App\Models\BankReceiptLine;
use App\Models\CashPaymentLine;
use App\Models\CashReceiptLine;
use App\Models\SettlementAllocation;
use App\Models\SettlementAllocationCorrection;
use App\Models\User;
use App\Support\DecimalMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates an immutable, line-level correction for a posted cash/bank settlement.
 * The source payment/receipt stays posted; a separate opposite journal and a
 * canonical reversal allocation preserve both accounting and audit evidence.
 */
final class SettlementAllocationCorrectionService
{
    public function __construct(
        private readonly JournalEntryService $journals,
        private readonly SettlementAllocationService $allocations,
        private readonly AuditService $audit,
        private readonly AccountingPeriodGuard $periodGuard,
    ) {}

    public function reverse(User $actor, int $allocationId, string $reason, string $postingDate): SettlementAllocation
    {
        $companyId = (int) $actor->company_id;
        if ($companyId < 1) {
            throw new AuthorizationException('An actor company is required.');
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A correction reason is required.');
        }

        return DB::transaction(function () use ($actor, $companyId, $allocationId, $reason, $postingDate): SettlementAllocation {
            $this->periodGuard->assertOpen($companyId, $postingDate, 'đảo phân bổ thanh toán công nợ');
            $original = SettlementAllocation::withoutGlobalScope('company')
                ->where('company_id', $companyId)->whereKey($allocationId)
                ->where('status', 'posted')->where('allocation_direction', 'reduction')
                ->lockForUpdate()->firstOrFail();
            if (! in_array($original->source_document_type, ['cash_payment', 'bank_payment', 'cash_receipt', 'bank_receipt'], true)) {
                throw new InvalidArgumentException('Only posted cash or bank settlement lines use this correction workflow.');
            }
            if (SettlementAllocationCorrection::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('reverses_allocation_id', $original->getKey())
                ->exists()) {
                throw new InvalidArgumentException('The settlement allocation has already been reversed.');
            }

            [$line, $expectedLineType, $expectedScale] = $this->sourceLine($original, $companyId);
            if ($original->source_line_type !== $expectedLineType || (int) $original->source_line_id !== (int) $line->getKey()
                || (int) $original->amount_scale !== $expectedScale) {
                throw new InvalidArgumentException('The original allocation does not retain valid payment-line evidence.');
            }

            $amount = DecimalMoney::normalize((string) $original->amount_raw.($expectedScale === 0 ? '.00' : ''));
            $correction = SettlementAllocationCorrection::withoutGlobalScope('company')->create([
                'company_id' => $companyId,
                'reverses_allocation_id' => $original->getKey(),
                'source_document_type' => $original->source_document_type,
                'source_document_id' => $original->source_document_id,
                'source_line_type' => $expectedLineType,
                'source_line_id' => $line->getKey(),
                'voucher_number' => 'SAC-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6)),
                'voucher_date' => $postingDate,
                'accounting_date' => $postingDate,
                'amount' => $amount,
                'amount_scale' => $expectedScale,
                'debit_account' => (string) $line->credit_account,
                'credit_account' => (string) $line->debit_account,
                'reason' => trim($reason),
                'status' => 'draft',
                'is_posted' => false,
                'created_by' => $actor->getKey(),
            ]);

            $journal = $this->journals->createPosted([
                'company_id' => $companyId,
                'voucher_type' => 'settlement_correction',
                'voucher_number' => 'GL-'.$correction->voucher_number,
                'voucher_date' => $postingDate,
                'posting_date' => $postingDate,
                'description' => 'Đảo phân bổ thanh toán: '.trim($reason),
                'source_document_type' => SettlementAllocationCorrection::class,
                'source_document_id' => $correction->getKey(),
                'lines' => [[
                    'debit_account' => $correction->debit_account,
                    'credit_account' => $correction->credit_account,
                    'amount' => $amount,
                    'description' => 'Đảo phân bổ thanh toán '.$correction->voucher_number,
                ]],
            ]);
            $correction->forceFill([
                'journal_entry_id' => $journal->getKey(), 'status' => 'posted', 'is_posted' => true,
                'posted_by' => $actor->getKey(), 'posted_at' => now(),
            ])->save();

            $reversal = $this->allocations->createPosted($actor, [
                'source_document_type' => 'settlement_correction',
                'source_document_id' => $correction->getKey(),
                'target_document_type' => $original->target_document_type,
                'target_document_id' => $original->target_document_id,
                'allocation_kind' => $original->allocation_kind,
                'allocation_direction' => 'reversal',
                'reverses_allocation_id' => $original->getKey(),
                'amount_raw' => $original->amount_raw,
                'amount_scale' => $expectedScale,
                'currency_code' => $original->currency_code,
                'effective_date' => $postingDate,
            ]);
            $this->audit->record($correction, 'settlement_allocation_correction.posted', ['status' => 'draft'], $correction->getAttributes(), null, [
                'domain' => 'settlement_allocation', 'operation' => 'reversed', 'original_allocation_id' => $original->getKey(),
            ]);

            return $reversal;
        });
    }

    /** @return array{0: Model, 1: string, 2: int} */
    private function sourceLine(SettlementAllocation $allocation, int $companyId): array
    {
        [$lineClass, $foreignKey, $lineType, $scale] = match ($allocation->source_document_type) {
            'cash_payment' => [CashPaymentLine::class, 'cash_payment_id', 'cash_payment_line', 0],
            'bank_payment' => [BankPaymentLine::class, 'bank_payment_id', 'bank_payment_line', 2],
            'cash_receipt' => [CashReceiptLine::class, 'cash_receipt_id', 'cash_receipt_line', 0],
            'bank_receipt' => [BankReceiptLine::class, 'bank_receipt_id', 'bank_receipt_line', 2],
        };
        $headerClass = match ($allocation->source_document_type) {
            'cash_payment' => \App\Models\CashPayment::class,
            'bank_payment' => \App\Models\BankPayment::class,
            'cash_receipt' => \App\Models\CashReceipt::class,
            'bank_receipt' => \App\Models\BankReceipt::class,
        };
        $header = $headerClass::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->findOrFail((int) $allocation->source_document_id);
        if (! $header->is_posted) {
            throw new AuthorizationException('The source payment or receipt is not posted in the actor tenant.');
        }
        $line = $lineClass::query()->where($foreignKey, $header->getKey())->lockForUpdate()->findOrFail((int) $allocation->source_line_id);

        return [$line, $lineType, $scale];
    }
}
