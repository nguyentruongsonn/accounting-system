<?php

namespace App\Support;

use App\Models\AllocationLog;
use App\Models\AssetDisposal;
use App\Models\AssetRevaluation;
use App\Models\ApArFxRevaluation;
use App\Models\BankPayment;
use App\Models\BankReceipt;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\DebtAdjustment;
use App\Models\DepreciationLog;
use App\Models\FixedAsset;
use App\Models\InventoryIssue;
use App\Models\InventoryReceipt;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\PurchaseDiscount;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\SalesDiscount;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\SettlementAllocationCorrection;

final class SystemPostingSourceRegistry
{
    /** @var list<class-string> */
    private const SOURCE_CLASSES = [
        CashReceipt::class,
        CashPayment::class,
        BankReceipt::class,
        BankPayment::class,
        InventoryReceipt::class,
        InventoryIssue::class,
        PurchaseInvoice::class,
        PurchaseReturn::class,
        PurchaseDiscount::class,
        SalesInvoice::class,
        SalesReturn::class,
        SalesDiscount::class,
        Payroll::class,
        FixedAsset::class,
        DepreciationLog::class,
        AssetDisposal::class,
        AssetRevaluation::class,
        ApArFxRevaluation::class,
        AllocationLog::class,
        DebtAdjustment::class,
        SettlementAllocationCorrection::class,
        // Journal reversals use the original journal as their canonical source.
        JournalEntry::class,
    ];

    public static function supports(string $modelClass): bool
    {
        return in_array($modelClass, self::SOURCE_CLASSES, true);
    }

    /** @return list<class-string> */
    public static function classes(): array
    {
        return self::SOURCE_CLASSES;
    }
}
