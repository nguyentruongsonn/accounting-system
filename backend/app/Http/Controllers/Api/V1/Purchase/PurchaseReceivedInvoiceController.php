<?php

namespace App\Http\Controllers\Api\V1\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseReceivedInvoiceRequest;
use App\Http\Resources\PurchaseReceivedInvoiceResource;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseContract;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceivedInvoice;
use App\Models\InventoryReceipt;
use App\Services\AuditService;
use App\Support\DecimalMoney;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseReceivedInvoiceController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $items = PurchaseReceivedInvoice::query()
            ->with('supplier')
            ->latest('invoice_date')
            ->latest('id')
            ->get();

        return PurchaseReceivedInvoiceResource::collection($items);
    }

    public function store(StorePurchaseReceivedInvoiceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->assertSupplierReferences($data, (int) $data['supplier_id']);
        $hasReference = $this->hasReference($data);
        $subtotal = DecimalMoney::normalize($data['subtotal']);
        $vatRate = DecimalMoney::normalize($data['vat_rate'] ?? '0');
        $vatAmount = DecimalMoney::percentage($subtotal, $vatRate);
        $totalAmount = DecimalMoney::add($subtotal, $vatAmount);

        try {
            $invoice = PurchaseReceivedInvoice::create([
                'company_id' => TenantContext::companyId($request),
                'supplier_id' => $data['supplier_id'],
                'invoice_template' => $data['invoice_template'] ?? null,
                'invoice_series' => trim($data['invoice_series']),
                'invoice_number' => trim($data['invoice_number']),
                'invoice_date' => $data['invoice_date'],
                'subtotal' => $subtotal,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'total_amount' => $totalAmount,
                'status' => $hasReference ? 'mapped' : 'unmapped',
                'purchase_invoice_id' => $data['purchase_invoice_id'] ?? null,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'purchase_contract_id' => $data['purchase_contract_id'] ?? null,
                'inventory_receipt_id' => $data['inventory_receipt_id'] ?? null,
                'voucher_ref' => $hasReference ? $this->referenceLabel($data) : null,
                'description' => $data['description'] ?? null,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'Số hóa đơn đã tồn tại trong cùng ký hiệu.',
                    'errors' => ['invoice_number' => ['Số hóa đơn đã tồn tại trong cùng ký hiệu.']],
                ], 409);
            }

            throw $exception;
        }

        $this->audit->record(
            $invoice,
            'purchase_received_invoice.created',
            [],
            $invoice->only(['supplier_id', 'invoice_series', 'invoice_number', 'invoice_date', 'subtotal', 'vat_amount', 'total_amount', 'status', 'purchase_invoice_id', 'purchase_order_id', 'purchase_contract_id', 'inventory_receipt_id', 'voucher_ref']),
            null,
            ['workflow' => 'purchase_receive_invoice']
        );

        return response()->json([
            'data' => (new PurchaseReceivedInvoiceResource($invoice->load('supplier')))->resolve(),
        ], 201);
    }

    public function link(Request $request, int $id): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $data = $request->validate([
            'purchase_invoice_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('purchase_invoices', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'purchase_order_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('purchase_orders', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'purchase_contract_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('purchase_contracts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'inventory_receipt_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('inventory_receipts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
        ]);

        $received = PurchaseReceivedInvoice::query()->findOrFail($id);
        $references = $this->referenceValues($data);
        if ($references === []) {
            return response()->json([
                'message' => 'Hãy chọn ít nhất một nguồn tham chiếu để ghép hóa đơn.',
                'errors' => ['reference' => ['Cần có chứng từ mua, đơn mua, hợp đồng hoặc phiếu nhập kho.']],
            ], 422);
        }

        $this->assertSupplierReferences($data, (int) $received->supplier_id);

        $before = $received->toArray();
        $received->update([
            'purchase_invoice_id' => $data['purchase_invoice_id'] ?? null,
            'purchase_order_id' => $data['purchase_order_id'] ?? null,
            'purchase_contract_id' => $data['purchase_contract_id'] ?? null,
            'inventory_receipt_id' => $data['inventory_receipt_id'] ?? null,
            'voucher_ref' => $this->referenceLabel($data),
            'status' => 'mapped',
            'updated_by' => $request->user()?->id,
        ]);
        $received->load('supplier');

        $this->audit->record(
            $received,
            'purchase_received_invoice.linked',
            $before,
            $received->toArray(),
            null,
            ['workflow' => 'purchase_receive_invoice', 'references' => $references]
        );

        return response()->json(['data' => (new PurchaseReceivedInvoiceResource($received))->resolve()]);
    }

    private function assertSupplierReferences(array $data, int $supplierId): void
    {
        foreach (['purchase_invoice_id' => PurchaseInvoice::class, 'purchase_order_id' => PurchaseOrder::class, 'purchase_contract_id' => PurchaseContract::class] as $key => $modelClass) {
            if (! isset($data[$key])) {
                continue;
            }

            $reference = $modelClass::query()->findOrFail($data[$key]);
            if ((int) $reference->supplier_id !== $supplierId) {
                throw ValidationException::withMessages([
                    $key => ['Nhà cung cấp của hóa đơn và chứng từ tham chiếu không trùng nhau.'],
                ]);
            }
        }
    }

    private function referenceLabel(array $data): ?string
    {
        if (! empty($data['purchase_invoice_id'])) {
            $reference = PurchaseInvoice::query()->find($data['purchase_invoice_id']);
            return $reference?->voucher_number ?? $reference?->invoice_number;
        }
        if (! empty($data['purchase_order_id'])) {
            return PurchaseOrder::query()->find($data['purchase_order_id'])?->order_number;
        }
        if (! empty($data['purchase_contract_id'])) {
            return PurchaseContract::query()->find($data['purchase_contract_id'])?->contract_number;
        }
        if (! empty($data['inventory_receipt_id'])) {
            return InventoryReceipt::query()->find($data['inventory_receipt_id'])?->voucher_number;
        }

        return null;
    }

    private function hasReference(array $data): bool
    {
        return $this->referenceValues($data) !== [];
    }

    private function referenceValues(array $data): array
    {
        return array_filter([
            'purchase_invoice_id' => $data['purchase_invoice_id'] ?? null,
            'purchase_order_id' => $data['purchase_order_id'] ?? null,
            'purchase_contract_id' => $data['purchase_contract_id'] ?? null,
            'inventory_receipt_id' => $data['inventory_receipt_id'] ?? null,
        ], static fn ($value): bool => $value !== null);
    }
}
