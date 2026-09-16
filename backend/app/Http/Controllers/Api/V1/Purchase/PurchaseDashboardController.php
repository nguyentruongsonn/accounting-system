<?php

namespace App\Http\Controllers\Api\V1\Purchase;

use App\Http\Controllers\Controller;
use App\Models\PurchaseContract;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Support\DecimalMoney;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A dashboard is a reporting surface, not a place to infer accounting facts.
 * Each numeric value has a tenant-scoped source. Metrics without implemented
 * evidence are null with an availability state; zero/sample values would be
 * fabricated accounting assertions.
 */
class PurchaseDashboardController extends Controller
{
    public function stats(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $now = Carbon::now();

        $orders = PurchaseOrder::query()
            ->where('company_id', $companyId)
            ->where('status', '!=', 'cancelled');
        $contracts = PurchaseContract::query()
            ->where('company_id', $companyId)
            ->where('status', '!=', 'cancelled');
        $orderRows = (clone $orders)->get(['status', 'grand_total']);
        $contractRows = (clone $contracts)->get(['contract_value']);
        $orderTotalAmount = DecimalMoney::sum($orderRows->map(static fn ($order): string => DecimalMoney::normalize($order->grand_total ?? DecimalMoney::ZERO)));
        $orderExecutedAmount = DecimalMoney::sum($orderRows->where('status', 'completed')->map(static fn ($order): string => DecimalMoney::normalize($order->grand_total ?? DecimalMoney::ZERO)));
        $contractTotalAmount = DecimalMoney::sum($contractRows->map(static fn ($contract): string => DecimalMoney::normalize($contract->contract_value ?? DecimalMoney::ZERO)));
        // Only posted invoices are accounting-recognised purchase evidence.
        $postedInvoices = PurchaseInvoice::query()
            ->where('company_id', $companyId)
            ->where('is_posted', true);

        // AP balances must come from the canonical, typed settlement source.
        // Do not infer payment from invoice status: legacy status values can
        // be stale and cannot prove which payment was allocated to which
        // invoice. The reduction is performed with DecimalMoney before the
        // response crosses the legacy numeric JSON boundary.
        $postedInvoiceRows = (clone $postedInvoices)->get(['id', 'supplier_id', 'supplier_name', 'total_amount']);
        $invoiceIds = $postedInvoiceRows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $allocationsByInvoice = collect();
        if ($invoiceIds !== []) {
            $allocationsByInvoice = DB::table('settlement_allocations')
                ->where('company_id', $companyId)
                ->where('target_document_type', 'purchase_invoice')
                ->whereIn('target_document_id', $invoiceIds)
                ->where('status', 'posted')
                ->whereDate('effective_date', '<=', $now->toDateString())
                ->orderBy('effective_date')
                ->orderBy('id')
                ->get(['target_document_id', 'amount_raw', 'amount_scale', 'allocation_direction'])
                ->groupBy('target_document_id');
        }

        $invoiceBalances = $postedInvoiceRows->map(function ($invoice) use ($allocationsByInvoice): array {
            $total = DecimalMoney::normalize($invoice->total_amount ?? DecimalMoney::ZERO);
            $allocated = DecimalMoney::ZERO;
            foreach ($allocationsByInvoice->get($invoice->id, collect()) as $allocation) {
                $amount = $this->normalizeAllocationAmount($allocation->amount_raw, $allocation->amount_scale ?? 2);
                $allocated = $allocation->allocation_direction === 'reversal'
                    ? DecimalMoney::subtract($allocated, $amount)
                    : DecimalMoney::add($allocated, $amount);
            }

            return [
                'supplier_id' => $invoice->supplier_id !== null ? (int) $invoice->supplier_id : null,
                'supplier_name' => trim((string) ($invoice->supplier_name ?? '')) ?: 'Không xác định',
                'paid' => DecimalMoney::maxZero($allocated),
                'remaining' => DecimalMoney::maxZero(DecimalMoney::subtract($total, $allocated)),
            ];
        });

        $invoicePaidAmount = DecimalMoney::sum($invoiceBalances->pluck('paid'));
        $invoiceRemainingAmount = DecimalMoney::sum($invoiceBalances->pluck('remaining'));
        $debtBySupplier = [];
        foreach ($invoiceBalances as $balance) {
            if (DecimalMoney::compare($balance['remaining'], DecimalMoney::ZERO) <= 0) {
                continue;
            }
            $key = $balance['supplier_id'] !== null
                ? 'id:'.$balance['supplier_id']
                : 'name:'.$balance['supplier_name'];
            if (! isset($debtBySupplier[$key])) {
                $debtBySupplier[$key] = [
                    'name' => $balance['supplier_name'],
                    'amount' => DecimalMoney::ZERO,
                ];
            }
            $debtBySupplier[$key]['amount'] = DecimalMoney::add($debtBySupplier[$key]['amount'], $balance['remaining']);
        }
        $topDebtSuppliers = collect(array_values($debtBySupplier))
            ->sort(static fn (array $left, array $right): int => DecimalMoney::compare($right['amount'], $left['amount']))
            ->take(5)
            ->values();
        $topDebtTotal = DecimalMoney::sum($topDebtSuppliers->pluck('amount'));
        $topDebtSuppliers = $topDebtSuppliers->map(static fn (array $supplier): array => [
            'name' => $supplier['name'],
            'amount' => (float) $supplier['amount'],
            'amount_decimal' => $supplier['amount'],
            'percent' => DecimalMoney::compare($topDebtTotal, DecimalMoney::ZERO) > 0
                ? round(((float) $supplier['amount'] / (float) $topDebtTotal) * 100, 1)
                : 0,
        ])->values();

        $invoiceTotalAmount = DecimalMoney::sum($postedInvoiceRows->map(static fn ($invoice): string => DecimalMoney::normalize($invoice->total_amount ?? DecimalMoney::ZERO)));

        $purchaseBySupplier = [];
        foreach ($postedInvoiceRows as $invoice) {
            $name = trim((string) ($invoice->supplier_name ?? '')) ?: 'Không xác định';
            $purchaseBySupplier[$name] ??= DecimalMoney::ZERO;
            $purchaseBySupplier[$name] = DecimalMoney::add(
                $purchaseBySupplier[$name],
                DecimalMoney::normalize($invoice->total_amount ?? DecimalMoney::ZERO),
            );
        }
        $topPurchaseSuppliers = collect($purchaseBySupplier)
            ->map(static fn (string $amount, string $name): array => ['name' => $name, 'amount' => $amount])
            ->sort(static fn (array $left, array $right): int => DecimalMoney::compare($right['amount'], $left['amount']))
            ->take(5)
            ->values();
        $topPurchaseTotal = DecimalMoney::sum($topPurchaseSuppliers->pluck('amount'));
        $topPurchaseSuppliers = $topPurchaseSuppliers
            ->map(static fn (array $supplier) => [
                'name' => $supplier['name'],
                'amount' => (float) $supplier['amount'],
                'amount_decimal' => $supplier['amount'],
                'percent' => DecimalMoney::compare($topPurchaseTotal, DecimalMoney::ZERO) > 0
                    ? round(((float) $supplier['amount'] / (float) $topPurchaseTotal) * 100, 1)
                    : 0,
            ])
            ->values();

        return response()->json([
            'as_of' => $now->toIso8601String(),
            // Kept for existing API consumers; this is generated at request time.
            'as_of_time' => $now->format('H:i'),
            'currency' => 'VND',
            'orders' => [
                'total_amount' => (float) $orderTotalAmount,
                'total_amount_decimal' => $orderTotalAmount,
                'executed_amount' => (float) $orderExecutedAmount,
                'executed_amount_decimal' => $orderExecutedAmount,
                'paid_amount' => null,
                'remaining_amount' => null,
            ],
            'contracts' => [
                'total_amount' => (float) $contractTotalAmount,
                'total_amount_decimal' => $contractTotalAmount,
                'executed_amount' => null,
                'paid_amount' => null,
                'remaining_amount' => null,
            ],
            'invoices' => [
                'total_amount' => (float) $invoiceTotalAmount,
                'total_amount_decimal' => $invoiceTotalAmount,
                'paid_amount' => (float) $invoicePaidAmount,
                'paid_amount_decimal' => $invoicePaidAmount,
                'remaining_amount' => (float) $invoiceRemainingAmount,
                'remaining_amount_decimal' => $invoiceRemainingAmount,
            ],
            'top_debt_suppliers' => $topDebtSuppliers,
            'top_purchase_suppliers' => $topPurchaseSuppliers,
            'metric_availability' => [
                'orders.paid_amount' => $this->unavailable('Chưa có liên kết phân bổ thanh toán chuẩn với đơn mua hàng.'),
                'orders.remaining_amount' => $this->unavailable('Chưa có liên kết phân bổ thanh toán chuẩn với đơn mua hàng.'),
                'contracts.executed_amount' => $this->unavailable('Chưa có nguồn giá trị thực hiện hợp đồng được kiểm chứng.'),
                'contracts.paid_amount' => $this->unavailable('Lịch thanh toán hợp đồng chưa phải là chứng cứ thanh toán kế toán.'),
                'contracts.remaining_amount' => $this->unavailable('Cần nguồn thực hiện và thanh toán hợp đồng được kiểm chứng.'),
                'invoices.paid_amount' => ['status' => 'available', 'source' => 'settlement_allocations'],
                'invoices.remaining_amount' => ['status' => 'available', 'source' => 'settlement_allocations'],
                'top_debt_suppliers' => ['status' => 'available', 'source' => 'purchase_invoices + settlement_allocations'],
                'top_purchase_suppliers' => ['status' => 'available', 'source' => 'purchase_invoices.is_posted'],
            ],
        ]);
    }

    private function normalizeAllocationAmount(mixed $raw, mixed $scale): string
    {
        return (int) $scale === 0
            ? DecimalMoney::normalize((string) $raw.'.00')
            : DecimalMoney::normalize($raw);
    }

    private function unavailable(string $reason): array
    {
        return ['status' => 'unavailable', 'reason' => $reason];
    }
}
