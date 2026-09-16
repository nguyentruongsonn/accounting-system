<?php

namespace App\Http\Middleware;

use App\Enums\SystemVoucherType;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\InventoryStockCount;
use App\Models\Item;
use App\Models\PurchaseContract;
use App\Models\PurchaseDiscount;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\SalesDiscount;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesQuote;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class EnforceTransactionalTenant
{
    /** @var array<string, class-string<Model>> */
    private const ROUTE_MODELS = [
        'purchase/orders' => PurchaseOrder::class,
        'purchase/contracts' => PurchaseContract::class,
        'purchase/invoices' => PurchaseInvoice::class,
        'purchase/returns' => PurchaseReturn::class,
        'purchase/discounts' => PurchaseDiscount::class,
        'sales/quotes' => SalesQuote::class,
        'sales/orders' => SalesOrder::class,
        'sales/invoices' => SalesInvoice::class,
        'sales/returns' => SalesReturn::class,
        'sales/discounts' => SalesDiscount::class,
    ];

    /** @var array<string, class-string<Model>> */
    private const REFERENCE_MODELS = [
        'supplier_id' => Supplier::class,
        'customer_id' => Customer::class,
        'employee_id' => Employee::class,
        'item_id' => Item::class,
        'warehouse_id' => Warehouse::class,
        'bank_account_id' => BankAccount::class,
        'purchase_order_id' => PurchaseOrder::class,
        'purchase_contract_id' => PurchaseContract::class,
        'purchase_invoice_id' => PurchaseInvoice::class,
        'sales_quote_id' => SalesQuote::class,
        'quote_id' => SalesQuote::class,
        'sales_order_id' => SalesOrder::class,
        'sales_invoice_id' => SalesInvoice::class,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $companyId = TenantContext::companyId($request);

        // The authenticated principal is the only authority for company scope.
        $request->merge(['company_id' => $companyId]);
        $request->query->set('company_id', $companyId);

        $this->assertRouteModelBelongsToCompany($request, $companyId);
        $this->assertPayloadReferencesBelongToCompany($request, $companyId);

        return $next($request);
    }

    private function assertRouteModelBelongsToCompany(Request $request, int $companyId): void
    {
        $route = $request->route();
        if ($route === null || $route->parameters() === []) {
            return;
        }

        $modelClass = $this->routeModel($request);
        if ($modelClass === null) {
            return;
        }

        foreach ($route->parameters() as $parameter) {
            $id = $parameter instanceof Model ? $parameter->getKey() : $parameter;
            if (is_numeric($id)) {
                $modelClass::query()
                    ->where('company_id', $companyId)
                    ->whereKey((int) $id)
                    ->firstOrFail();

                return;
            }
        }
    }

    private function assertPayloadReferencesBelongToCompany(Request $request, int $companyId): void
    {
        $payload = $request->all();
        $errors = [];

        $this->validateReferenceFields($payload, $companyId, $errors);
        foreach (($payload['lines'] ?? []) as $index => $line) {
            if (is_array($line)) {
                $this->validateReferenceFields($line, $companyId, $errors, "lines.{$index}.");
            }
        }

        $referenceInvoiceClass = $request->is('api/v1/purchase/*')
            ? PurchaseInvoice::class
            : SalesInvoice::class;
        $this->validateExistingForeignId(
            $payload['reference_invoice_id'] ?? null,
            $referenceInvoiceClass,
            $companyId,
            'reference_invoice_id',
            $errors
        );

        foreach (($payload['referenced_vouchers'] ?? []) as $index => $reference) {
            if (! is_array($reference)) {
                continue;
            }

            $targetType = $reference['target_type'] ?? $reference['model'] ?? null;
            $targetId = $reference['target_id'] ?? $reference['real_id'] ?? $reference['id'] ?? null;
            $modelClass = $this->referencedVoucherModel($targetType);
            if (is_numeric($targetId) && $modelClass === null) {
                $errors["referenced_vouchers.{$index}.target_type"][] =
                    'A supported voucher target type is required when a target ID is provided.';

                continue;
            }

            if ($modelClass === null) {
                continue;
            }

            $this->validateExistingForeignId(
                $targetId,
                $modelClass,
                $companyId,
                "referenced_vouchers.{$index}.target_id",
                $errors
            );
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<string>>  $errors
     */
    private function validateReferenceFields(array $payload, int $companyId, array &$errors, string $prefix = ''): void
    {
        foreach (self::REFERENCE_MODELS as $field => $modelClass) {
            $this->validateExistingForeignId(
                $payload[$field] ?? null,
                $modelClass,
                $companyId,
                $prefix.$field,
                $errors
            );
        }
    }

    /**
     * Reject an ID only when it resolves to another company. This preserves
     * legacy optional metadata references while preventing cross-tenant links.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, list<string>>  $errors
     */
    private function validateExistingForeignId(
        mixed $id,
        string $modelClass,
        int $companyId,
        string $field,
        array &$errors
    ): void {
        if (! is_numeric($id)) {
            return;
        }

        $record = $modelClass::query()->withoutGlobalScopes()->whereKey((int) $id)->first();
        if ($record !== null && (int) $record->getAttribute('company_id') !== $companyId) {
            $errors[$field][] = 'The selected record does not belong to the active company.';
        }
    }

    /** @return class-string<Model>|null */
    private function routeModel(Request $request): ?string
    {
        foreach (self::ROUTE_MODELS as $path => $modelClass) {
            if ($request->is("api/v1/{$path}/*")) {
                return $modelClass;
            }
        }

        return null;
    }

    /** @return class-string<Model>|null */
    private function referencedVoucherModel(mixed $targetType): ?string
    {
        if (! is_string($targetType) || $targetType === '') {
            return null;
        }

        $modelClass = str_contains($targetType, '\\')
            ? $targetType
            : 'App\\Models\\'.$targetType;

        $voucherModels = array_values(array_filter(array_map(
            static fn (SystemVoucherType $type): ?string => $type->modelClass(),
            SystemVoucherType::cases()
        )));
        $voucherModels[] = InventoryStockCount::class;

        return in_array($modelClass, $voucherModels, true)
            ? $modelClass
            : null;
    }
}
