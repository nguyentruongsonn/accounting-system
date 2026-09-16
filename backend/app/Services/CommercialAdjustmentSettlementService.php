<?php

namespace App\Services;

use App\Models\PurchaseDiscount;
use App\Models\PurchaseReturn;
use App\Models\SalesDiscount;
use App\Models\SalesReturn;
use App\Models\SettlementAllocation;
use App\Models\User;
use App\Support\DecimalMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/** Creates the canonical AP/AR open-item reduction for a posted adjustment. */
final class CommercialAdjustmentSettlementService
{
    public function __construct(private readonly SettlementAllocationService $allocations) {}

    public function createFor(Model $source): ?SettlementAllocation
    {
        $referenceInvoiceId = $source->getAttribute('reference_invoice_id');
        if ($referenceInvoiceId === null || ! (bool) $source->getAttribute('is_decrease_debt')) {
            return null;
        }

        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new AuthorizationException('A signed-in actor is required to create the adjustment allocation.');
        }

        [$sourceType, $targetType, $kind] = match ($source::class) {
            PurchaseReturn::class => ['purchase_return', 'purchase_invoice', 'return'],
            PurchaseDiscount::class => ['purchase_discount', 'purchase_invoice', 'discount'],
            SalesReturn::class => ['sales_return', 'sales_invoice', 'return'],
            SalesDiscount::class => ['sales_discount', 'sales_invoice', 'discount'],
            default => throw new \LogicException('Unsupported commercial adjustment source.'),
        };

        $amount = DecimalMoney::normalize((string) $source->getRawOriginal('total_amount'));
        if (DecimalMoney::compare($amount, DecimalMoney::ZERO) <= 0) {
            return null;
        }

        return $this->allocations->createPosted($actor, [
            'source_document_type' => $sourceType,
            'source_document_id' => (int) $source->getKey(),
            'target_document_type' => $targetType,
            'target_document_id' => (int) $referenceInvoiceId,
            'allocation_kind' => $kind,
            'amount_raw' => $amount,
            'amount_scale' => 2,
            'currency_code' => 'VND',
            'effective_date' => ($source->getAttribute('accounting_date') ?? $source->getAttribute('voucher_date'))->toDateString(),
        ]);
    }
}
