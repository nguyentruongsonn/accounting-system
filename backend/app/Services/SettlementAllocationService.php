<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BankPayment;
use App\Models\BankPaymentLine;
use App\Models\BankReceipt;
use App\Models\BankReceiptLine;
use App\Models\CashPayment;
use App\Models\CashPaymentLine;
use App\Models\CashReceipt;
use App\Models\CashReceiptLine;
use App\Models\DebtAdjustment;
use App\Models\JournalEntry;
use App\Models\PurchaseDiscount;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\SalesDiscount;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\SettlementAllocation;
use App\Models\SettlementAllocationCorrection;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Creates tenant-bound, typed, posted settlement evidence. */
final class SettlementAllocationService
{
    public function __construct(
        private readonly AccountingPeriodGuard $periodGuard,
        private readonly ApArSettlementStatusPolicy $settlementStatusPolicy,
    )
    {
    }

    /** @return Collection<int, SettlementAllocation> */
    public function forTarget(int $companyId, string $targetType, int $targetId)
    {
        $companyId = $this->requireCompanyId($companyId);
        if (! in_array($targetType, ['purchase_invoice', 'sales_invoice'], true) || $targetId < 1) {
            throw new InvalidArgumentException('Settlement allocation target is unsupported.');
        }

        return SettlementAllocation::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('target_document_type', $targetType)
            ->where('target_document_id', $targetId)
            ->where('status', 'posted')
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get();
    }

    private function requireCompanyId(int $companyId): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        if ($companyId < 1 || ($actorCompanyId !== null && (int) $actorCompanyId !== $companyId)) {
            throw new AuthorizationException('The requested company does not belong to the authenticated user.');
        }

        return $companyId;
    }

    /** @param array<string, mixed> $data */
    public function createPosted(User $actor, array $data): SettlementAllocation
    {
        $companyId = (int) $actor->company_id;
        if ($companyId < 1) {
            throw new AuthorizationException('An actor company is required.');
        }

        $sourceType = $data['source_document_type'] ?? null;
        $scale = $this->scale($data['amount_scale'] ?? null);
        $amount = $this->amount($data['amount_raw'] ?? null, $scale);
        $effectiveDate = $this->date($data['effective_date'] ?? null);
        $kind = $data['allocation_kind'] ?? null;
        if (! in_array($kind, ['settlement', 'credit_note', 'return', 'discount', 'write_off'], true)) {
            throw new InvalidArgumentException('Settlement allocation kind is unsupported.');
        }

        $direction = $data['allocation_direction'] ?? 'reduction';
        if (! in_array($direction, ['reduction', 'reversal'], true)) {
            throw new InvalidArgumentException('Settlement allocation direction is unsupported.');
        }

        return DB::transaction(function () use ($actor, $companyId, $data, $sourceType, $kind, $amount, $scale, $effectiveDate, $direction): SettlementAllocation {
            $this->periodGuard->assertOpen($companyId, $effectiveDate, 'lập phân bổ thanh toán công nợ');
            $source = $this->source($sourceType, $data['source_document_id'] ?? null, $companyId);
            $target = $this->target($data['target_document_type'] ?? null, $data['target_document_id'] ?? null, $companyId);
            $dualCurrency = $this->dualCurrencyEvidence($data, $target, $amount, $scale);
            $this->assertSourceTargetCompatibility($sourceType, $source, $data['target_document_type'], $target, $kind, $direction);
            $this->assertSourceLineCapacity(
                $companyId,
                $sourceType,
                $source,
                $data['source_line_type'] ?? null,
                $data['source_line_id'] ?? null,
                $amount,
                $scale,
            );
            $this->assertOriginalSourceLineCapacity($companyId, $sourceType, $data, $dualCurrency);
            $reversalOf = $this->reversalOf($companyId, $sourceType, $source, $target, $kind, $direction, $data['reverses_allocation_id'] ?? null, $amount, $scale);
            if ($direction === 'reduction') {
                $this->assertTargetCapacity($companyId, $data['target_document_type'], $target, $amount, $scale);
            }

            $allocation = SettlementAllocation::withoutGlobalScope('company')->create([
                'company_id' => $companyId,
                'source_document_type' => $data['source_document_type'],
                'source_document_id' => $source->getKey(),
                'source_line_type' => $data['source_line_type'] ?? null,
                'source_line_id' => $data['source_line_id'] ?? null,
                'source_reference_key' => $this->sourceReferenceKey($data, $source, $target),
                'target_document_type' => $data['target_document_type'],
                'target_document_id' => $target->getKey(),
                'allocation_kind' => $kind,
                'allocation_direction' => $direction,
                'reverses_allocation_id' => $reversalOf?->getKey(),
                'amount_raw' => $amount,
                'amount_scale' => $scale,
                'currency_code' => $this->currency($data['currency_code'] ?? null),
                ...$dualCurrency,
                'effective_date' => $effectiveDate,
                'status' => 'posted',
                'posted_at' => now(),
                'created_by' => $actor->getKey(),
            ]);
            if ($target instanceof PurchaseInvoice || $target instanceof SalesInvoice) {
                $this->settlementStatusPolicy->sync($target->fresh());
            }
            AuditLog::withoutGlobalScope('company')->create([
                'company_id' => $companyId,
                'user_id' => $actor->getKey(),
                'action' => 'settlement_allocation.posted',
                'model_type' => SettlementAllocation::class,
                'model_id' => $allocation->getKey(),
                'new_values' => [
                    'source_document_type' => $allocation->source_document_type,
                    'source_document_id' => $allocation->source_document_id,
                    'target_document_type' => $allocation->target_document_type,
                    'target_document_id' => $allocation->target_document_id,
                    'allocation_kind' => $allocation->allocation_kind,
                    'allocation_direction' => $allocation->allocation_direction,
                    'reverses_allocation_id' => $allocation->reverses_allocation_id,
                    'amount_raw' => $allocation->amount_raw,
                    'amount_scale' => $allocation->amount_scale,
                    'effective_date' => $allocation->effective_date->toDateString(),
                ],
            ]);

            return $allocation;
        });
    }

    private function source(mixed $type, mixed $id, int $companyId): Model
    {
        $model = match ($type) {
            'cash_payment' => CashPayment::class,
            'bank_payment' => BankPayment::class,
            'cash_receipt' => CashReceipt::class,
            'bank_receipt' => BankReceipt::class,
            'purchase_return' => PurchaseReturn::class,
            'purchase_discount' => PurchaseDiscount::class,
            'sales_return' => SalesReturn::class,
            'sales_discount' => SalesDiscount::class,
            'ap_debt_adjustment', 'ar_debt_adjustment' => DebtAdjustment::class,
            'journal_reversal' => JournalEntry::class,
            'settlement_correction' => SettlementAllocationCorrection::class,
            default => throw new InvalidArgumentException('Settlement source document type is unsupported.'),
        };

        $source = $model::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->find($this->id($id));
        if ($source === null) {
            throw new AuthorizationException('Settlement source is not posted in the actor tenant.');
        }
        $isPosted = $source instanceof JournalEntry ? $source->status === 'posted' : (bool) $source->is_posted;
        if (! $isPosted) {
            throw new AuthorizationException('Settlement source is not posted in the actor tenant.');
        }

        return $source;
    }

    private function assertSourceTargetCompatibility(
        mixed $sourceType,
        Model $source,
        mixed $targetType,
        Model $target,
        mixed $kind,
        string $direction,
    ): void {
        $expected = match ($sourceType) {
            'cash_payment', 'bank_payment' => ['purchase_invoice', 'settlement'],
            'cash_receipt', 'bank_receipt' => ['sales_invoice', 'settlement'],
            'purchase_return' => ['purchase_invoice', 'return'],
            'purchase_discount' => ['purchase_invoice', 'discount'],
            'sales_return' => ['sales_invoice', 'return'],
            'sales_discount' => ['sales_invoice', 'discount'],
            'ap_debt_adjustment' => ['purchase_invoice', (string) $source->adjustment_kind],
            'ar_debt_adjustment' => ['sales_invoice', (string) $source->adjustment_kind],
            'journal_reversal' => [(string) $targetType, (string) $kind],
            'settlement_correction' => [(string) $targetType, (string) $kind],
            default => throw new InvalidArgumentException('Settlement source document type is unsupported.'),
        };
        if ($targetType !== $expected[0] || $kind !== $expected[1]) {
            throw new InvalidArgumentException('Settlement source, target and allocation kind are incompatible.');
        }

        if (in_array($sourceType, ['purchase_return', 'purchase_discount', 'sales_return', 'sales_discount'], true)
            && ((int) $source->reference_invoice_id !== (int) $target->getKey() || ! $source->is_decrease_debt)) {
            throw new InvalidArgumentException('Adjustment allocation must reference its target invoice and decrease debt.');
        }

        if (in_array($sourceType, ['ap_debt_adjustment', 'ar_debt_adjustment'], true)) {
            $expectedLedger = $sourceType === 'ap_debt_adjustment' ? 'ap' : 'ar';
            if ($source->ledger !== $expectedLedger
                || $source->reference_document_type !== $targetType
                || (int) $source->reference_document_id !== (int) $target->getKey()
                || (($source->reversal_of_id === null) !== ($direction === 'reduction'))) {
                throw new InvalidArgumentException('Debt adjustment allocation does not match its typed document lineage.');
            }
        }
        if ($sourceType === 'journal_reversal' && ($direction !== 'reversal' || $source->reversal_of_id === null)) {
            throw new InvalidArgumentException('Only a posted journal reversal can reverse a settlement allocation.');
        }
        if ($sourceType === 'settlement_correction' && ($direction !== 'reversal' || $source->reverses_allocation_id === null)) {
            throw new InvalidArgumentException('Only a posted settlement correction can reverse a settlement allocation.');
        }

    }

    /**
     * A canonical payment/receipt allocation must name one concrete source
     * line.  This prevents a header amount from being spent twice across open
     * items, while leaving the legacy invoice_id field uninterpreted.
     */
    private function assertSourceLineCapacity(
        int $companyId,
        string $sourceType,
        Model $source,
        mixed $lineType,
        mixed $lineId,
        string $amount,
        int $scale,
    ): void {
        if (in_array($sourceType, ['purchase_return', 'purchase_discount', 'sales_return', 'sales_discount', 'ap_debt_adjustment', 'ar_debt_adjustment', 'journal_reversal', 'settlement_correction'], true)) {
            $this->assertAdjustmentCapacity($companyId, $sourceType, $source, $lineType, $lineId, $amount, $scale);

            return;
        }

        $definition = match ($sourceType) {
            'cash_payment' => [CashPaymentLine::class, 'cash_payment_id', 'cash_payment_line', 0],
            'cash_receipt' => [CashReceiptLine::class, 'cash_receipt_id', 'cash_receipt_line', 0],
            'bank_payment' => [BankPaymentLine::class, 'bank_payment_id', 'bank_payment_line', 2],
            'bank_receipt' => [BankReceiptLine::class, 'bank_receipt_id', 'bank_receipt_line', 2],
            default => throw new InvalidArgumentException('Settlement source document type is unsupported.'),
        };
        [$model, $foreignKey, $expectedLineType, $expectedScale] = $definition;
        if ($lineType !== $expectedLineType || $scale !== $expectedScale) {
            throw new InvalidArgumentException('Settlement allocation must identify a source line with its exact amount scale.');
        }

        $line = $model::query()
            ->where($foreignKey, $source->getKey())
            ->lockForUpdate()
            ->findOrFail($this->id($lineId));
        $available = DecimalMoney::normalize($line->getRawOriginal('amount'));
        $requested = $this->asTwoDecimal($amount, $scale);
        $allocated = DecimalMoney::sum(
            SettlementAllocation::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('source_document_type', $sourceType)
                ->where('source_document_id', $source->getKey())
                ->where('source_line_type', $expectedLineType)
                ->where('source_line_id', $line->getKey())
                ->where('status', 'posted')
                ->lockForUpdate()
                ->get(['amount_raw', 'amount_scale', 'allocation_direction'])
                ->map(fn (SettlementAllocation $row) => $this->signedAmount($row)),
        );
        if (DecimalMoney::compare(DecimalMoney::add($allocated, $requested), $available) > 0) {
            throw new InvalidArgumentException('Settlement allocation exceeds the posted source line amount.');
        }
    }

    private function assertAdjustmentCapacity(
        int $companyId,
        string $sourceType,
        Model $source,
        mixed $lineType,
        mixed $lineId,
        string $amount,
        int $scale,
    ): void {
        $expectedScale = $source instanceof SettlementAllocationCorrection ? (int) $source->amount_scale : 2;
        if ($lineType !== null || $lineId !== null || $scale !== $expectedScale) {
            throw new InvalidArgumentException('A debt-reduction adjustment must use its exact document amount at scale 2 without a payment source line.');
        }
        $sourceAmountField = $source instanceof DebtAdjustment || $source instanceof SettlementAllocationCorrection ? 'amount' : 'total_amount';
        $available = DecimalMoney::normalize($source->getRawOriginal($sourceAmountField));
        $requested = $this->asTwoDecimal($amount, $scale);
        $allocated = DecimalMoney::sum(
            SettlementAllocation::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('source_document_type', $sourceType)
                ->where('source_document_id', $source->getKey())
                ->where('status', 'posted')
                ->lockForUpdate()
                ->get(['amount_raw', 'amount_scale'])
                ->map(fn (SettlementAllocation $row) => $this->asTwoDecimal($row->amount_raw, (int) $row->amount_scale)),
        );
        if (DecimalMoney::compare(DecimalMoney::add($allocated, $requested), $available) > 0) {
            throw new InvalidArgumentException('Settlement allocation exceeds the posted adjustment amount.');
        }
    }

    private function assertTargetCapacity(int $companyId, mixed $targetType, Model $target, string $amount, int $scale): void
    {
        $available = DecimalMoney::normalize($target->getRawOriginal('total_amount'));
        $requested = $this->asTwoDecimal($amount, $scale);
        $allocated = DecimalMoney::sum(
            SettlementAllocation::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('target_document_type', $targetType)
                ->where('target_document_id', $target->getKey())
                ->where('status', 'posted')
                ->lockForUpdate()
                ->get(['amount_raw', 'amount_scale', 'allocation_direction'])
                ->map(fn (SettlementAllocation $row) => $this->signedAmount($row)),
        );
        if (DecimalMoney::compare(DecimalMoney::add($allocated, $requested), $available) > 0) {
            throw new InvalidArgumentException('Settlement allocation exceeds the posted invoice amount.');
        }
    }

    private function asTwoDecimal(string $amount, int $scale): string
    {
        if ($scale === 0) {
            return DecimalMoney::normalize($amount.'.00');
        }
        if ($scale === 2) {
            return DecimalMoney::normalize($amount);
        }

        throw new InvalidArgumentException('Settlement source amount scale is not yet supported by the canonical allocation adapter.');
    }

    /** @return array<string, string|int|null> */
    private function dualCurrencyEvidence(array $data, Model $target, string $amount, int $scale): array
    {
        $fields = ['functional_currency_code', 'functional_amount_raw', 'functional_amount_scale', 'original_currency_code', 'original_amount_raw', 'original_amount_scale'];
        $provided = array_filter($fields, static fn (string $field): bool => array_key_exists($field, $data) && $data[$field] !== null);
        if ($provided === []) {
            return array_fill_keys($fields, null);
        }
        if (count($provided) !== count($fields)) {
            throw new InvalidArgumentException('Dual-currency settlement evidence must include all original and functional currency fields.');
        }
        $functionalScale = $this->scale($data['functional_amount_scale']);
        $functionalAmount = $this->amount($data['functional_amount_raw'], $functionalScale);
        if (strtoupper((string) $data['functional_currency_code']) !== 'VND'
            || DecimalMoney::compare($this->asTwoDecimal($functionalAmount, $functionalScale), $this->asTwoDecimal($amount, $scale)) !== 0) {
            throw new InvalidArgumentException('Functional-currency settlement evidence must be VND and equal the canonical allocation amount.');
        }
        $originalScale = $this->scale($data['original_amount_scale']);
        $originalAmount = $this->amount($data['original_amount_raw'], $originalScale);
        $originalCurrency = strtoupper((string) $data['original_currency_code']);
        if (! preg_match('/^[A-Z]{3}$/', $originalCurrency) || $originalCurrency === 'VND' || DecimalMoney::compare($this->asTwoDecimal($originalAmount, $originalScale), DecimalMoney::ZERO) <= 0) {
            throw new InvalidArgumentException('Original-currency settlement evidence must contain a positive non-VND exact amount.');
        }
        if (strtoupper((string) $target->getAttribute('currency')) !== $originalCurrency) {
            throw new InvalidArgumentException('Original settlement currency must match the referenced AP/AR open item.');
        }

        return [
            'functional_currency_code' => 'VND', 'functional_amount_raw' => $functionalAmount, 'functional_amount_scale' => $functionalScale,
            'original_currency_code' => $originalCurrency, 'original_amount_raw' => $originalAmount, 'original_amount_scale' => $originalScale,
        ];
    }

    /** @param array<string, mixed> $dualCurrency */
    private function assertOriginalSourceLineCapacity(int $companyId, string $sourceType, array $data, array $dualCurrency): void
    {
        if (($dualCurrency['original_currency_code'] ?? null) === null) return;
        [$lineClass, $foreignKey, $expectedType] = match ($sourceType) {
            'cash_payment' => [CashPaymentLine::class, 'cash_payment_id', 'cash_payment_line'],
            'bank_payment' => [BankPaymentLine::class, 'bank_payment_id', 'bank_payment_line'],
            'cash_receipt' => [CashReceiptLine::class, 'cash_receipt_id', 'cash_receipt_line'],
            'bank_receipt' => [BankReceiptLine::class, 'bank_receipt_id', 'bank_receipt_line'],
            default => throw new InvalidArgumentException('Dual-currency allocation requires a typed cash or bank source line.'),
        };
        if (($data['source_line_type'] ?? null) !== $expectedType) throw new InvalidArgumentException('Dual-currency allocation must identify its exact payment source line.');
        // The source header is tenant-scoped, but this line query must also
        // bind to that exact header before locking.  Otherwise a tampered
        // source_line_id can lock an unrelated tenant/header line before the
        // foreign-key consistency check below rejects it.
        $line = $lineClass::query()
            ->where($foreignKey, $this->id($data['source_document_id'] ?? null))
            ->lockForUpdate()
            ->findOrFail($this->id($data['source_line_id'] ?? null));
        if ((int) $line->{$foreignKey} !== (int) $data['source_document_id'] || $line->original_currency_code === null || $line->original_amount_raw === null || $line->original_amount_scale === null) {
            throw new InvalidArgumentException('The payment source line has no complete original-currency evidence.');
        }
        if (strtoupper((string) $line->original_currency_code) !== $dualCurrency['original_currency_code']) throw new InvalidArgumentException('Original allocation currency must match the payment source line.');
        $available = $this->asTwoDecimal((string) $line->original_amount_raw, (int) $line->original_amount_scale);
        $requested = $this->asTwoDecimal((string) $dualCurrency['original_amount_raw'], (int) $dualCurrency['original_amount_scale']);
        $allocated = DecimalMoney::sum(SettlementAllocation::withoutGlobalScope('company')
            ->where('company_id', $companyId)->where('source_document_type', $sourceType)->where('source_document_id', $data['source_document_id'])
            ->where('source_line_type', $expectedType)->where('source_line_id', $line->getKey())->where('status', 'posted')->lockForUpdate()
            ->get(['original_amount_raw', 'original_amount_scale', 'allocation_direction'])
            ->filter(fn (SettlementAllocation $row): bool => $row->original_amount_raw !== null && $row->original_amount_scale !== null)
            ->map(fn (SettlementAllocation $row): string => $row->allocation_direction === 'reversal'
                ? DecimalMoney::subtract(DecimalMoney::ZERO, $this->asTwoDecimal((string) $row->original_amount_raw, (int) $row->original_amount_scale))
                : $this->asTwoDecimal((string) $row->original_amount_raw, (int) $row->original_amount_scale)));
        if (DecimalMoney::compare(DecimalMoney::add($allocated, $requested), $available) > 0) throw new InvalidArgumentException('Settlement allocation exceeds the posted source-line original-currency amount.');
    }

    private function signedAmount(SettlementAllocation $allocation): string
    {
        $amount = $this->asTwoDecimal($allocation->amount_raw, (int) $allocation->amount_scale);

        return $allocation->allocation_direction === 'reversal'
            ? DecimalMoney::subtract(DecimalMoney::ZERO, $amount)
            : $amount;
    }

    private function reversalOf(
        int $companyId,
        string $sourceType,
        Model $source,
        Model $target,
        string $kind,
        string $direction,
        mixed $reversesAllocationId,
        string $amount,
        int $scale,
    ): ?SettlementAllocation {
        if ($direction === 'reduction') {
            if ($reversesAllocationId !== null) {
                throw new InvalidArgumentException('A reduction allocation cannot reverse another allocation.');
            }

            return null;
        }
        if (! in_array($sourceType, ['ap_debt_adjustment', 'ar_debt_adjustment', 'journal_reversal', 'settlement_correction'], true)) {
            throw new InvalidArgumentException('Only a posted reversal debt adjustment can reverse a settlement allocation.');
        }

        $original = SettlementAllocation::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->whereKey($this->id($reversesAllocationId))
            ->where('target_document_type', $target instanceof PurchaseInvoice ? 'purchase_invoice' : 'sales_invoice')
            ->where('target_document_id', $target->getKey())
            ->where('allocation_kind', $kind)
            ->where('allocation_direction', 'reduction')
            ->where('status', 'posted')
            ->lockForUpdate()
            ->firstOrFail();
        if ($sourceType === 'settlement_correction') {
            if ((int) $source->reverses_allocation_id !== (int) $original->getKey()) {
                throw new InvalidArgumentException('Settlement correction does not belong to the original allocation.');
            }
        } elseif ($sourceType === 'journal_reversal') {
            if ($source->reversal_of_id === null) {
                throw new InvalidArgumentException('Journal reversal source lineage is missing.');
            }
            $expectedSourceClass = $this->allocationSourceClass($original->source_document_type);
            $originalJournal = JournalEntry::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->findOrFail($source->reversal_of_id);
            if ($originalJournal->source_document_type !== $expectedSourceClass
                || (int) $originalJournal->source_document_id !== (int) $original->source_document_id) {
                throw new InvalidArgumentException('Journal reversal does not belong to the original settlement source.');
            }
        } elseif ($source->reversal_of_id === null || $original->source_document_type !== $sourceType || (int) $original->source_document_id !== (int) $source->reversal_of_id) {
            throw new InvalidArgumentException('Debt adjustment reversal does not belong to the original settlement source.');
        }
        if (DecimalMoney::compare($this->asTwoDecimal($amount, $scale), $this->asTwoDecimal($original->amount_raw, (int) $original->amount_scale)) > 0) {
            throw new InvalidArgumentException('Settlement allocation reversal exceeds its original allocation amount.');
        }

        return $original;
    }

    private function allocationSourceClass(string $sourceType): string
    {
        return match ($sourceType) {
            'purchase_return' => PurchaseReturn::class,
            'purchase_discount' => PurchaseDiscount::class,
            'sales_return' => SalesReturn::class,
            'sales_discount' => SalesDiscount::class,
            default => throw new InvalidArgumentException('The original allocation source cannot be reversed through a journal reversal.'),
        };
    }

    /** @param array<string, mixed> $data */
    private function sourceReferenceKey(array $data, Model $source, Model $target): string
    {
        return implode('|', [
            (string) $data['source_document_type'],
            (string) $source->getKey(),
            $data['source_line_type'] ?? '-',
            $data['source_line_id'] ?? '-',
            (string) $data['target_document_type'],
            (string) $target->getKey(),
        ]);
    }

    private function target(mixed $type, mixed $id, int $companyId): Model
    {
        $model = match ($type) {
            'purchase_invoice' => PurchaseInvoice::class,
            'sales_invoice' => SalesInvoice::class,
            default => throw new InvalidArgumentException('Settlement target document type is unsupported.'),
        };

        $target = $model::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->find($this->id($id));
        if ($target === null) {
            throw new AuthorizationException('Settlement target is not posted in the actor tenant.');
        }
        if (! $target->is_posted) {
            throw new AuthorizationException('Settlement target is not posted in the actor tenant.');
        }

        return $target;
    }

    private function id(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException('Settlement document IDs must be positive integers.');
        }

        return (int) $value;
    }

    private function scale(mixed $value): int
    {
        if (! is_int($value) || $value < 0 || $value > 12) {
            throw new InvalidArgumentException('Settlement amount scale must be an integer from 0 through 12.');
        }

        return $value;
    }

    private function amount(mixed $value, int $scale): string
    {
        if (! is_string($value) || ! preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value)) {
            throw new InvalidArgumentException('Settlement amount must be a positive exact decimal string.');
        }
        if (preg_match('/^0(?:\.0+)?$/', $value) === 1) {
            throw new InvalidArgumentException('Settlement amount must be a positive exact decimal string.');
        }
        $fraction = explode('.', $value, 2)[1] ?? '';
        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException('Settlement amount exceeds its declared scale.');
        }

        return $value;
    }

    private function date(mixed $value): string
    {
        if (! is_string($value) || ! ($date = CarbonImmutable::createFromFormat('!Y-m-d', $value)) || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Settlement effective_date must be a valid ISO calendar date.');
        }

        return $value;
    }

    private function currency(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^[A-Z]{3}$/', $value)) {
            throw new InvalidArgumentException('Settlement currency code must be an ISO uppercase code.');
        }

        return $value;
    }
}
