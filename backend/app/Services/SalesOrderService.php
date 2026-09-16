<?php

namespace App\Services;

use App\Models\SalesOrder;
use App\Services\Concerns\GuardsPostedDependentDocuments;
use App\Support\CommercialSourceAuditContext;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SalesOrderService
{
    use GuardsPostedDependentDocuments;

    public function getAll(array $filters = [])
    {
        $query = $this->scopeToActorCompany(SalesOrder::with(['customer', 'employee', 'lines.item', 'references']))
            ->orderBy('order_date', 'desc')
            ->orderBy('id', 'desc');

        if (! empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['from_date'])) {
            $query->whereDate('order_date', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate('order_date', '<=', $filters['to_date']);
        }
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('order_number', 'like', "%{$s}%")
                    ->orWhere('customer_name', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%");
            });
        }

        return $query->get();
    }

    public function getById(int $id): SalesOrder
    {
        return $this->scopeToActorCompany(SalesOrder::with(['lines.item', 'customer', 'employee', 'references', 'quote']))
            ->findOrFail($id);
    }

    public function create(array $data): SalesOrder
    {
        $data['company_id'] = $this->resolveCompanyId($data);

        return DB::transaction(function () use ($data) {
            $subTotal = DecimalMoney::ZERO;
            $taxAmount = DecimalMoney::ZERO;
            $discountAmount = $this->money($data['discount_amount'] ?? DecimalMoney::ZERO);
            $lines = $data['lines'] ?? [];
            $preparedLines = [];

            foreach ($lines as $line) {
                $amounts = $this->lineAmounts($line);
                $preparedLines[] = array_merge($line, $amounts);
                $discountAmount = DecimalMoney::add($discountAmount, $amounts['discount']);
                $subTotal = DecimalMoney::add($subTotal, $amounts['amount']);
                $taxAmount = DecimalMoney::add($taxAmount, $amounts['tax']);
            }

            $totalAmount = DecimalMoney::add(
                DecimalMoney::subtract($subTotal, $discountAmount),
                $taxAmount,
            );
            $orderNumber = $data['order_number'] ?? $data['voucher_number'] ?? $this->generateNextCode((int) $data['company_id']);

            $order = SalesOrder::create([
                'company_id' => (int) $data['company_id'],
                'sales_quote_id' => $data['sales_quote_id'] ?? ($data['quote_id'] ?? null),
                'quote_id' => $data['quote_id'] ?? ($data['sales_quote_id'] ?? null),
                'order_number' => $orderNumber,
                'order_date' => $data['order_date'] ?? $data['voucher_date'] ?? now()->toDateString(),
                'delivery_date' => $data['delivery_date'] ?? $data['due_date'] ?? now()->addDays(15)->toDateString(),
                'customer_id' => $data['customer_id'] ?? null,
                'customer_code' => $data['customer_code'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_address' => $data['customer_address'] ?? null,
                'tax_code' => $data['tax_code'] ?? null,
                'contact_person' => $data['contact_person'] ?? $data['receiver_name'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'employee_name' => $data['employee_name'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'due_days' => intval($data['due_days'] ?? 30),
                'delivery_address' => $data['delivery_address'] ?? null,
                'other_terms' => $data['other_terms'] ?? null,
                'description' => $data['description'] ?? null,
                'sub_total' => $subTotal,
                'discount_amount' => $discountAmount,
                'vat_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'grand_total' => $totalAmount,
                'status' => $data['status'] ?? 'pending',
                'delivery_status' => $data['delivery_status'] ?? 'not_delivered',
                'invoice_status' => $data['invoice_status'] ?? 'not_invoiced',
                'currency' => $data['currency'] ?? 'VND',
                'exchange_rate' => floatval($data['exchange_rate'] ?? 1),
                'referenced_vouchers' => $data['referenced_vouchers'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            foreach ($preparedLines as $line) {
                $order->lines()->create([
                    'item_id' => $line['item_id'] ?? null,
                    'item_code' => $line['item_code'] ?? null,
                    'item_name' => $line['item_name'] ?? ($line['description'] ?? null),
                    'unit' => $line['unit'] ?? null,
                    'warehouse_id' => $line['warehouse_id'] ?? null,
                    'warehouse_code' => $line['warehouse_code'] ?? null,
                    'quantity' => $line['quantity'],
                    'delivered_quantity' => $this->money($line['delivered_quantity'] ?? '0'),
                    'invoiced_quantity' => $this->money($line['invoiced_quantity'] ?? '0'),
                    'unit_price' => $line['unit_price'],
                    'amount' => $line['amount'],
                    'discount_rate' => $line['discount_rate'],
                    'discount_amount' => $line['discount'],
                    'tax_rate' => $line['tax_rate'],
                    'tax_amount' => $line['tax'],
                    'total_amount' => $line['total_amount'],
                    'delivery_date' => $line['delivery_date'] ?? $order->delivery_date,
                    'description' => $line['description'] ?? $order->description,
                    'note' => $line['note'] ?? null,
                ]);
            }

            if (! empty($data['referenced_vouchers'])) {
                $order->syncReferences($data['referenced_vouchers']);
            }

            return $order->load(['lines.item', 'customer', 'employee', 'references']);
        });
    }

    public function update(int $id, array $data): SalesOrder
    {
        return DB::transaction(function () use ($id, $data) {
            $order = $this->scopeToActorCompany(SalesOrder::with('lines'))->findOrFail($id);

            $subTotal = DecimalMoney::ZERO;
            $taxAmount = DecimalMoney::ZERO;
            $discountAmount = $this->money($data['discount_amount'] ?? DecimalMoney::ZERO);
            $lines = $data['lines'] ?? null;
            $preparedLines = null;

            if ($lines !== null) {
                $preparedLines = [];
                foreach ($lines as $line) {
                    $amounts = $this->lineAmounts($line);
                    $preparedLines[] = array_merge($line, $amounts);
                    $discountAmount = DecimalMoney::add($discountAmount, $amounts['discount']);
                    $subTotal = DecimalMoney::add($subTotal, $amounts['amount']);
                    $taxAmount = DecimalMoney::add($taxAmount, $amounts['tax']);
                }
            } else {
                $subTotal = $this->persistedMoney($order, 'sub_total');
                $taxAmount = $this->persistedMoney($order, 'vat_amount');
                $discountAmount = $this->persistedMoney($order, 'discount_amount');
            }

            $totalAmount = DecimalMoney::add(
                DecimalMoney::subtract($subTotal, $discountAmount),
                $taxAmount,
            );

            $order->update([
                'sales_quote_id' => $data['sales_quote_id'] ?? ($data['quote_id'] ?? $order->sales_quote_id),
                'quote_id' => $data['quote_id'] ?? ($data['sales_quote_id'] ?? $order->quote_id),
                'order_number' => $data['order_number'] ?? $order->order_number,
                'order_date' => $data['order_date'] ?? $order->order_date,
                'delivery_date' => $data['delivery_date'] ?? $order->delivery_date,
                'customer_id' => $data['customer_id'] ?? $order->customer_id,
                'customer_code' => $data['customer_code'] ?? $order->customer_code,
                'customer_name' => $data['customer_name'] ?? $order->customer_name,
                'customer_address' => $data['customer_address'] ?? $order->customer_address,
                'tax_code' => $data['tax_code'] ?? $order->tax_code,
                'contact_person' => $data['contact_person'] ?? $order->contact_person,
                'contact_phone' => $data['contact_phone'] ?? $order->contact_phone,
                'contact_email' => $data['contact_email'] ?? $order->contact_email,
                'employee_id' => $data['employee_id'] ?? $order->employee_id,
                'employee_name' => $data['employee_name'] ?? $order->employee_name,
                'payment_terms' => $data['payment_terms'] ?? $order->payment_terms,
                'due_days' => intval($data['due_days'] ?? $order->due_days),
                'delivery_address' => $data['delivery_address'] ?? $order->delivery_address,
                'other_terms' => $data['other_terms'] ?? $order->other_terms,
                'description' => $data['description'] ?? $order->description,
                'sub_total' => $subTotal,
                'discount_amount' => $discountAmount,
                'vat_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'grand_total' => $totalAmount,
                'status' => $data['status'] ?? $order->status,
                'delivery_status' => $data['delivery_status'] ?? $order->delivery_status,
                'invoice_status' => $data['invoice_status'] ?? $order->invoice_status,
                'currency' => $data['currency'] ?? $order->currency,
                'exchange_rate' => floatval($data['exchange_rate'] ?? $order->exchange_rate ?? 1),
                'referenced_vouchers' => $data['referenced_vouchers'] ?? $order->referenced_vouchers,
                'updated_by' => $data['updated_by'] ?? auth()->id(),
            ]);

            if ($lines !== null) {
                $order->lines()->delete();
                foreach ($preparedLines ?? [] as $line) {
                    $order->lines()->create([
                        'item_id' => $line['item_id'] ?? null,
                        'item_code' => $line['item_code'] ?? null,
                        'item_name' => $line['item_name'] ?? ($line['description'] ?? null),
                        'unit' => $line['unit'] ?? null,
                        'warehouse_id' => $line['warehouse_id'] ?? null,
                        'warehouse_code' => $line['warehouse_code'] ?? null,
                        'quantity' => $line['quantity'],
                        'delivered_quantity' => $this->money($line['delivered_quantity'] ?? '0'),
                        'invoiced_quantity' => $this->money($line['invoiced_quantity'] ?? '0'),
                        'unit_price' => $line['unit_price'],
                        'amount' => $line['amount'],
                        'discount_rate' => $line['discount_rate'],
                        'discount_amount' => $line['discount'],
                        'tax_rate' => $line['tax_rate'],
                        'tax_amount' => $line['tax'],
                        'total_amount' => $line['total_amount'],
                        'delivery_date' => $line['delivery_date'] ?? $order->delivery_date,
                        'description' => $line['description'] ?? $order->description,
                        'note' => $line['note'] ?? null,
                    ]);
                }
            }

            if (isset($data['referenced_vouchers'])) {
                $order->syncReferences($data['referenced_vouchers']);
            }

            return $order->load(['lines.item', 'customer', 'employee', 'references']);
        });
    }

    private function money(mixed $value): string
    {
        return DecimalMoney::normalize($value ?? DecimalMoney::ZERO);
    }

    private function persistedMoney(object $model, string $attribute): string
    {
        return $this->money($model->getRawOriginal($attribute) ?? DecimalMoney::ZERO);
    }

    /**
     * @return array{quantity:string,unit_price:string,amount:string,discount_rate:string,discount:string,net_amount:string,tax_rate:string,tax:string,total_amount:string}
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
            'net_amount' => $netAmount,
            'tax_rate' => $taxRate,
            'tax' => $tax,
            'total_amount' => DecimalMoney::add($netAmount, $tax),
        ];
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $order = $this->scopeToActorCompany(SalesOrder::query())->findOrFail($id);
            $this->assertNoPostedDependentDocuments((int) $order->company_id, SalesOrder::class, (int) $order->id);
            $order->lines()->delete();
            $order->references()->delete();
            $order->delete();

            return true;
        });
    }

    public function updateStatus(int $id, string $status): SalesOrder
    {
        return DB::transaction(function () use ($id, $status) {
            $order = $this->scopeToActorCompany(SalesOrder::query())->findOrFail($id);
            $order->status = $status;
            $order->save();

            return $order;
        });
    }

    public function duplicate(int $id): SalesOrder
    {
        return DB::transaction(function () use ($id) {
            $original = $this->scopeToActorCompany(SalesOrder::with('lines'))->findOrFail($id);
            $newNumber = $this->generateNextCode($original->company_id);

            $newOrder = $original->replicate();
            $newOrder->order_number = $newNumber;
            $newOrder->order_date = now()->toDateString();
            $newOrder->delivery_date = now()->addDays(15)->toDateString();
            $newOrder->status = 'pending';
            $newOrder->delivery_status = 'not_delivered';
            $newOrder->invoice_status = 'not_invoiced';
            CommercialSourceAuditContext::mark($newOrder, 'duplicated');
            $newOrder->save();

            foreach ($original->lines as $line) {
                $newLine = $line->replicate();
                $newLine->sales_order_id = $newOrder->id;
                $newLine->delivered_quantity = 0;
                $newLine->invoiced_quantity = 0;
                $newLine->save();
            }

            if ($original->referenced_vouchers) {
                $newOrder->syncReferences($original->referenced_vouchers);
            }

            return $newOrder->load(['lines.item', 'customer', 'employee', 'references']);
        });
    }

    public function unpost(int $id): SalesOrder
    {
        return DB::transaction(function () use ($id) {
            $order = $this->scopeToActorCompany(SalesOrder::query())->findOrFail($id);
            $this->assertNoPostedDependentDocuments((int) $order->company_id, SalesOrder::class, (int) $order->id);
            $order->status = 'draft';
            $order->save();

            return $order;
        });
    }

    public function generateNextCode(?int $companyId = null, ?string $prefix = 'DDH'): string
    {
        $companyId = $this->resolveCompanyId(['company_id' => $companyId]);
        $year = now()->format('Y');
        $codePrefix = ($prefix ?: 'DDH').'-'.$year.'-';
        $latest = SalesOrder::where('company_id', $companyId)
            ->where('order_number', 'like', $codePrefix.'%')
            ->orderBy('id', 'desc')
            ->value('order_number');

        if ($latest && preg_match('/'.preg_quote($codePrefix, '/').'(\d+)/', $latest, $m)) {
            $nextSeq = str_pad((int) $m[1] + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $count = SalesOrder::where('company_id', $companyId)->count() + 1;
            $nextSeq = str_pad($count, 4, '0', STR_PAD_LEFT);
        }

        return $codePrefix.$nextSeq;
    }

    private function resolveCompanyId(array $data): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        $requestedCompanyId = $data['company_id'] ?? null;
        if ($actorCompanyId === null) {
            throw new AccessDeniedHttpException('Authenticated user is not assigned to a company.');
        }
        if ($requestedCompanyId !== null && (int) $requestedCompanyId !== (int) $actorCompanyId) {
            throw new AccessDeniedHttpException('The requested company does not belong to the authenticated user.');
        }

        return (int) $actorCompanyId;
    }

    /**
     * Do not rely solely on the model global scope: service callers may invoke
     * these methods outside an HTTP request, and a missing actor company must
     * fail closed rather than turning a document id into a cross-tenant read.
     */
    private function scopeToActorCompany($query)
    {
        $actorCompanyId = auth()->user()?->company_id;
        if ($actorCompanyId === null) {
            throw new AccessDeniedHttpException('Authenticated user is not assigned to a company.');
        }

        return $query->where('company_id', (int) $actorCompanyId);
    }
}
