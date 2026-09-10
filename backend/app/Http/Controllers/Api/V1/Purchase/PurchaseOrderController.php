<?php

namespace App\Http\Controllers\Api\V1\Purchase;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Services\PostedDependentDocumentGuard;
use App\Support\DecimalMoney;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseOrder::with(['lines', 'supplier', 'employee'])
            ->where('company_id', TenantContext::companyId($request))
            ->orderBy('id', 'desc');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('order_number', 'like', "%{$s}%")
                    ->orWhere('supplier_name', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%");
            });
        }

        return response()->json($query->get()->map(fn (PurchaseOrder $order) => $this->serializeOrder($order))->values());
    }

    public function nextCode(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $nextSequence = 1;

        // Counting rows can reuse a document number after a deletion and also
        // counts manually numbered orders. Only advance from existing codes
        // in this endpoint's own prefix; the final number remains client/API
        // contract data and is still validated by the store workflow.
        foreach (PurchaseOrder::where('company_id', $companyId)->pluck('order_number') as $orderNumber) {
            if (preg_match('/^ĐMH(\d+)$/u', (string) $orderNumber, $matches) === 1) {
                $nextSequence = max($nextSequence, ((int) $matches[1]) + 1);
            }
        }

        $code = 'ĐMH'.str_pad((string) $nextSequence, 5, '0', STR_PAD_LEFT);

        return response()->json([
            'code' => $code,
            'next_code' => $code,
            'data' => ['code' => $code, 'next_code' => $code],
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'order_number' => 'required',
            'order_date' => 'required',
        ]);

        return DB::transaction(function () use ($request) {
            $data = $request->except('lines');
            $data['company_id'] = TenantContext::companyId($request);
            $lines = $request->input('lines', []);
            $preparedLines = $this->prepareLines(is_array($lines) ? $lines : []);
            $totals = $this->totals($preparedLines, $data);
            $data['sub_total'] = $totals['sub_total'];
            $data['discount_amount'] = $totals['discount_amount'];
            $data['vat_amount'] = $totals['vat_amount'];
            $data['total_amount'] = $totals['total_amount'];
            $data['grand_total'] = $totals['total_amount'];

            $order = PurchaseOrder::create($data);

            if ($preparedLines !== []) {
                foreach ($preparedLines as $line) {
                    $line['purchase_order_id'] = $order->id;
                    PurchaseOrderLine::create($line);
                }
            }

            return response()->json($this->serializeOrder($order->load('lines')), 201);
        });
    }

    public function show(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        $order = PurchaseOrder::with(['lines', 'supplier', 'employee'])
            ->where('company_id', $companyId)
            ->findOrFail($id);

        return response()->json($this->serializeOrder($order));
    }

    public function update(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);

        return DB::transaction(function () use ($request, $companyId, $id) {
            $order = PurchaseOrder::where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($id);

            // company_id is server-owned; accepting it here could move an
            // existing order to another tenant after the scoped lookup.
            $data = $request->except(['lines', 'company_id']);
            // A downstream invoice may legitimately move the order's workflow
            // status to completed. Protect the source document's accounting
            // content and lines, while keeping this non-accounting status
            // transition available after conversion.
            $materialFields = array_diff(array_keys($data), ['status']);
            if ($request->has('lines') || $materialFields !== []) {
                app(PostedDependentDocumentGuard::class)->assertNone($companyId, PurchaseOrder::class, (int) $order->id);
            }

            if ($request->has('lines') && is_array($request->lines)) {
                $preparedLines = $this->prepareLines($request->lines);
                $totals = $this->totals($preparedLines, $data);
                $data['sub_total'] = $totals['sub_total'];
                $data['discount_amount'] = $totals['discount_amount'];
                $data['vat_amount'] = $totals['vat_amount'];
                $data['total_amount'] = $totals['total_amount'];
                $data['grand_total'] = $totals['total_amount'];
            } else {
                $preparedLines = null;
            }
            $order->update($data);

            if ($preparedLines !== null) {
                $order->lines()->delete();
                foreach ($preparedLines as $line) {
                    $line['purchase_order_id'] = $order->id;
                    PurchaseOrderLine::create($line);
                }
            }

            return response()->json($this->serializeOrder($order->load('lines')));
        });
    }

    public function destroy(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);

        DB::transaction(function () use ($id, $companyId) {
            $order = PurchaseOrder::where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            app(PostedDependentDocumentGuard::class)->assertNone($companyId, PurchaseOrder::class, (int) $order->id);
            $order->lines()->delete();
            $order->delete();
        });

        return response()->json(['message' => 'Đã xóa đơn mua hàng thành công']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function prepareLines(array $lines): array
    {
        return array_map(function (array $line): array {
            $amounts = $this->lineAmounts($line);

            return array_merge($line, [
                'quantity' => $amounts['quantity'],
                'unit_price' => $amounts['unit_price'],
                'amount' => $amounts['amount'],
                'discount_rate' => $amounts['discount_rate'],
                'discount_amount' => $amounts['discount'],
                'tax_rate' => $amounts['tax_rate'],
                'tax_amount' => $amounts['tax'],
                'total_amount' => $amounts['total_amount'],
            ]);
        }, $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $header
     * @return array{sub_total:string,discount_amount:string,vat_amount:string,total_amount:string}
     */
    private function totals(array $lines, array $header): array
    {
        $subTotal = DecimalMoney::ZERO;
        $lineDiscount = DecimalMoney::ZERO;
        $vatAmount = DecimalMoney::ZERO;

        foreach ($lines as $line) {
            $subTotal = DecimalMoney::add($subTotal, $line['amount']);
            $lineDiscount = DecimalMoney::add($lineDiscount, $line['discount_amount']);
            $vatAmount = DecimalMoney::add($vatAmount, $line['tax_amount']);
        }

        $discountAmount = array_key_exists('discount_amount', $header)
            ? $this->money($header['discount_amount'])
            : $lineDiscount;
        $totalAmount = DecimalMoney::add(
            DecimalMoney::subtract($subTotal, $discountAmount),
            $vatAmount,
        );

        return [
            'sub_total' => $subTotal,
            'discount_amount' => $discountAmount,
            'vat_amount' => $vatAmount,
            'total_amount' => $totalAmount,
        ];
    }

    /**
     * @return array{quantity:string,unit_price:string,amount:string,discount_rate:string,discount:string,tax_rate:string,tax:string,total_amount:string}
     */
    private function lineAmounts(array $line): array
    {
        $quantity = $this->money($line['quantity'] ?? '1');
        $unitPrice = $this->money($line['unit_price'] ?? '0');
        $rawAmount = trim((string) ($line['amount'] ?? ''));
        $amount = $rawAmount !== ''
            ? $this->money($rawAmount)
            : DecimalMoney::multiply($quantity, $unitPrice);
        $discountRate = $this->money($line['discount_rate'] ?? '0');
        $rawDiscount = trim((string) ($line['discount_amount'] ?? ''));
        $discount = $rawDiscount !== ''
            ? $this->money($rawDiscount)
            : DecimalMoney::percentage($amount, $discountRate);
        $netAmount = DecimalMoney::subtract($amount, $discount);
        $taxRate = $this->money($line['tax_rate'] ?? '0');
        $rawTax = trim((string) ($line['tax_amount'] ?? ''));
        $tax = $rawTax !== ''
            ? $this->money($rawTax)
            : DecimalMoney::percentage($netAmount, $taxRate);

        return [
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $amount,
            'discount_rate' => $discountRate,
            'discount' => $discount,
            'tax_rate' => $taxRate,
            'tax' => $tax,
            'total_amount' => DecimalMoney::add($netAmount, $tax),
        ];
    }

    private function money(mixed $value): string
    {
        return DecimalMoney::normalize($value ?? DecimalMoney::ZERO);
    }

    /**
     * Keep the legacy numeric representation for whole-number totals while
     * retaining decimal strings whenever cents are present.  The companion
     * *_decimal fields provide an exact, lossless contract for new clients.
     *
     * @return array<string, mixed>
     */
    private function serializeOrder(PurchaseOrder $order): array
    {
        $payload = $order->toArray();
        $moneyFields = ['sub_total', 'discount_amount', 'vat_amount', 'total_amount', 'grand_total'];

        foreach ($moneyFields as $field) {
            if (! array_key_exists($field, $payload) || $payload[$field] === null) {
                continue;
            }

            $decimal = $this->money($payload[$field]);
            $payload[$field.'_decimal'] = $decimal;
            $payload[$field] = $this->legacyNumber($decimal);
        }

        if (isset($payload['lines']) && is_array($payload['lines'])) {
            $lineMoneyFields = ['unit_price', 'amount', 'discount_amount', 'tax_amount', 'total_amount'];
            foreach ($payload['lines'] as &$line) {
                foreach ($lineMoneyFields as $field) {
                    if (! array_key_exists($field, $line) || $line[$field] === null) {
                        continue;
                    }

                    $decimal = $this->money($line[$field]);
                    $line[$field.'_decimal'] = $decimal;
                    $line[$field] = $this->legacyNumber($decimal);
                }
            }
            unset($line);
        }

        return $payload;
    }

    private function legacyNumber(string $decimal): int|float|string
    {
        if (str_ends_with($decimal, '.00')) {
            return (int) substr($decimal, 0, -3);
        }

        return $decimal;
    }
}
