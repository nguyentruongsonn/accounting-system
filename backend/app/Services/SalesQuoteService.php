<?php

namespace App\Services;

use App\Models\SalesQuote;
use App\Services\Concerns\GuardsPostedDependentDocuments;
use App\Support\CommercialSourceAuditContext;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SalesQuoteService
{
    use GuardsPostedDependentDocuments;

    public function getAll(array $filters = [])
    {
        $query = $this->scopeToActorCompany(SalesQuote::with(['customer', 'employee', 'lines.item', 'references']))
            ->orderBy('quote_date', 'desc')
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
            $query->whereDate('quote_date', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate('quote_date', '<=', $filters['to_date']);
        }
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('quote_number', 'like', "%{$s}%")
                    ->orWhere('customer_name', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%");
            });
        }

        return $query->get();
    }

    public function getById(int $id): SalesQuote
    {
        return $this->scopeToActorCompany(SalesQuote::with(['lines.item', 'customer', 'employee', 'references', 'orders']))
            ->findOrFail($id);
    }

    public function create(array $data): SalesQuote
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
            $quoteNumber = $data['quote_number'] ?? $data['voucher_number'] ?? $this->generateNextCode((int) $data['company_id']);

            $quote = SalesQuote::create([
                'company_id' => (int) $data['company_id'],
                'quote_number' => $quoteNumber,
                'quote_date' => $data['quote_date'] ?? $data['voucher_date'] ?? now()->toDateString(),
                'expiry_date' => $data['expiry_date'] ?? $data['due_date'] ?? now()->addDays(30)->toDateString(),
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
                'delivery_address' => $data['delivery_address'] ?? null,
                'delivery_terms' => $data['delivery_terms'] ?? null,
                'description' => $data['description'] ?? null,
                'sub_total' => $subTotal,
                'discount_amount' => $discountAmount,
                'vat_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'grand_total' => $totalAmount,
                'status' => $data['status'] ?? 'draft',
                'currency' => $data['currency'] ?? 'VND',
                'exchange_rate' => floatval($data['exchange_rate'] ?? 1),
                'terms_and_conditions' => $data['terms_and_conditions'] ?? null,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            foreach ($preparedLines as $line) {
                $quote->lines()->create([
                    'item_id' => $line['item_id'] ?? null,
                    'item_code' => $line['item_code'] ?? null,
                    'item_name' => $line['item_name'] ?? ($line['description'] ?? null),
                    'unit' => $line['unit'] ?? null,
                    'warehouse_id' => $line['warehouse_id'] ?? null,
                    'warehouse_code' => $line['warehouse_code'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'amount' => $line['amount'],
                    'discount_rate' => $line['discount_rate'],
                    'discount_amount' => $line['discount'],
                    'tax_rate' => $line['tax_rate'],
                    'tax_amount' => $line['tax'],
                    'total_amount' => $line['total_amount'],
                    'description' => $line['description'] ?? $quote->description,
                    'note' => $line['note'] ?? null,
                ]);
            }

            if (! empty($data['referenced_vouchers'])) {
                $quote->syncReferences($data['referenced_vouchers']);
            }

            return $quote->load(['lines.item', 'customer', 'employee', 'references']);
        });
    }

    public function update(int $id, array $data): SalesQuote
    {
        return DB::transaction(function () use ($id, $data) {
            $quote = $this->scopeToActorCompany(SalesQuote::with('lines'))->findOrFail($id);

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
                $subTotal = $this->persistedMoney($quote, 'sub_total');
                $taxAmount = $this->persistedMoney($quote, 'vat_amount');
                $discountAmount = $this->persistedMoney($quote, 'discount_amount');
            }

            $totalAmount = DecimalMoney::add(
                DecimalMoney::subtract($subTotal, $discountAmount),
                $taxAmount,
            );

            $quote->update([
                'quote_number' => $data['quote_number'] ?? $quote->quote_number,
                'quote_date' => $data['quote_date'] ?? $quote->quote_date,
                'expiry_date' => $data['expiry_date'] ?? $quote->expiry_date,
                'customer_id' => $data['customer_id'] ?? $quote->customer_id,
                'customer_code' => $data['customer_code'] ?? $quote->customer_code,
                'customer_name' => $data['customer_name'] ?? $quote->customer_name,
                'customer_address' => $data['customer_address'] ?? $quote->customer_address,
                'tax_code' => $data['tax_code'] ?? $quote->tax_code,
                'contact_person' => $data['contact_person'] ?? $quote->contact_person,
                'contact_phone' => $data['contact_phone'] ?? $quote->contact_phone,
                'contact_email' => $data['contact_email'] ?? $quote->contact_email,
                'employee_id' => $data['employee_id'] ?? $quote->employee_id,
                'employee_name' => $data['employee_name'] ?? $quote->employee_name,
                'payment_terms' => $data['payment_terms'] ?? $quote->payment_terms,
                'delivery_address' => $data['delivery_address'] ?? $quote->delivery_address,
                'delivery_terms' => $data['delivery_terms'] ?? $quote->delivery_terms,
                'description' => $data['description'] ?? $quote->description,
                'sub_total' => $subTotal,
                'discount_amount' => $discountAmount,
                'vat_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'grand_total' => $totalAmount,
                'status' => $data['status'] ?? $quote->status,
                'currency' => $data['currency'] ?? $quote->currency,
                'exchange_rate' => floatval($data['exchange_rate'] ?? $quote->exchange_rate ?? 1),
                'terms_and_conditions' => $data['terms_and_conditions'] ?? $quote->terms_and_conditions,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? $quote->referenced_vouchers,
                'updated_by' => $data['updated_by'] ?? auth()->id(),
            ]);

            if ($lines !== null) {
                $quote->lines()->delete();
                foreach ($preparedLines ?? [] as $line) {
                    $quote->lines()->create([
                        'item_id' => $line['item_id'] ?? null,
                        'item_code' => $line['item_code'] ?? null,
                        'item_name' => $line['item_name'] ?? ($line['description'] ?? null),
                        'unit' => $line['unit'] ?? null,
                        'warehouse_id' => $line['warehouse_id'] ?? null,
                        'warehouse_code' => $line['warehouse_code'] ?? null,
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'amount' => $line['amount'],
                        'discount_rate' => $line['discount_rate'],
                        'discount_amount' => $line['discount'],
                        'tax_rate' => $line['tax_rate'],
                        'tax_amount' => $line['tax'],
                        'total_amount' => $line['total_amount'],
                        'description' => $line['description'] ?? $quote->description,
                        'note' => $line['note'] ?? null,
                    ]);
                }
            }

            if (isset($data['referenced_vouchers'])) {
                $quote->syncReferences($data['referenced_vouchers']);
            }

            return $quote->load(['lines.item', 'customer', 'employee', 'references']);
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
            $quote = $this->scopeToActorCompany(SalesQuote::query())->findOrFail($id);
            $this->assertNoPostedDependentDocuments((int) $quote->company_id, SalesQuote::class, (int) $quote->id);
            $quote->lines()->delete();
            $quote->references()->delete();
            $quote->delete();

            return true;
        });
    }

    public function updateStatus(int $id, string $status): SalesQuote
    {
        return DB::transaction(function () use ($id, $status) {
            $quote = $this->scopeToActorCompany(SalesQuote::query())->findOrFail($id);
            $quote->status = $status;
            $quote->save();

            return $quote;
        });
    }

    public function duplicate(int $id): SalesQuote
    {
        return DB::transaction(function () use ($id) {
            $original = $this->scopeToActorCompany(SalesQuote::with('lines'))->findOrFail($id);
            $newNumber = $this->generateNextCode($original->company_id);

            $newQuote = $original->replicate();
            $newQuote->quote_number = $newNumber;
            $newQuote->quote_date = now()->toDateString();
            $newQuote->expiry_date = now()->addDays(30)->toDateString();
            $newQuote->status = 'draft';
            CommercialSourceAuditContext::mark($newQuote, 'duplicated');
            $newQuote->save();

            foreach ($original->lines as $line) {
                $newLine = $line->replicate();
                $newLine->sales_quote_id = $newQuote->id;
                $newLine->save();
            }

            if ($original->referenced_vouchers) {
                $newQuote->syncReferences($original->referenced_vouchers);
            }

            return $newQuote->load(['lines.item', 'customer', 'employee', 'references']);
        });
    }

    public function unpost(int $id): SalesQuote
    {
        return DB::transaction(function () use ($id) {
            $quote = $this->scopeToActorCompany(SalesQuote::query())->findOrFail($id);
            $this->assertNoPostedDependentDocuments((int) $quote->company_id, SalesQuote::class, (int) $quote->id);
            $quote->status = 'draft';
            $quote->save();

            return $quote;
        });
    }

    public function generateNextCode(?int $companyId = null, ?string $prefix = 'BG'): string
    {
        $companyId = $this->resolveCompanyId(['company_id' => $companyId]);
        $year = now()->format('Y');
        $codePrefix = ($prefix ?: 'BG').'-'.$year.'-';
        $latest = SalesQuote::where('company_id', $companyId)
            ->where('quote_number', 'like', $codePrefix.'%')
            ->orderBy('id', 'desc')
            ->value('quote_number');

        if ($latest && preg_match('/'.preg_quote($codePrefix, '/').'(\d+)/', $latest, $m)) {
            $nextSeq = str_pad((int) $m[1] + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $count = SalesQuote::where('company_id', $companyId)->count() + 1;
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
