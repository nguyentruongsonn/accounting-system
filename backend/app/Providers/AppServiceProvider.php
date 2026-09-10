<?php

namespace App\Providers;

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
use App\Observers\CommercialSourceAuditObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Production must keep every accounting enforcement control enabled even
     * when a stale config cache was built from a diagnostic test environment.
     * This list mirrors the 18 ACCOUNTING_ENFORCE_* keys in config/accounting.php.
     * It selects no account mapping; it only protects the fail-closed boundary.
     *
     * @var list<string>
     */
    private const PRODUCTION_FAIL_CLOSED_ACCOUNTING_FLAGS = [
        'accounting.enforce_purchase_invoice_posting_policy',
        'accounting.enforce_sales_invoice_posting_policy',
        'accounting.enforce_purchase_invoice_posting_approval',
        'accounting.enforce_purchase_invoice_posting_dimensions',
        'accounting.enforce_purchase_invoice_posting_account_mappings',
        'accounting.enforce_sales_invoice_posting_approval',
        'accounting.enforce_sales_invoice_posting_dimensions',
        'accounting.enforce_sales_invoice_posting_account_mappings',
        'accounting.enforce_cash_bank_posting_policy',
        'accounting.enforce_cash_bank_posting_approval',
        'accounting.enforce_cash_bank_posting_account_mappings',
        'accounting.enforce_voucher_reference_account_mappings',
        'accounting.enforce_inventory_posting_account_mappings',
        'accounting.enforce_inventory_stock_availability',
        'accounting.enforce_fixed_asset_posting_account_mappings',
        'accounting.enforce_return_discount_posting_account_mappings',
        'accounting.enforce_costing_integer_allocation_evidence',
        'accounting.enforce_period_close_signoff',
        'accounting.enforce_period_close_account_mappings',
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Read the current process/.env value directly instead of relying only
        // on config('app.env'); the latter may itself come from a stale cached
        // config generated under the legacy test environment.
        $runtimeEnvironment = strtolower((string) env('APP_ENV', 'production'));
        if (in_array($runtimeEnvironment, ['production', 'prod'], true)) {
            foreach (self::PRODUCTION_FAIL_CLOSED_ACCOUNTING_FLAGS as $flag) {
                config([$flag => true]);
            }
        }

        // JsonResource::withoutWrapping() removed to ensure all API responses
        // include the standard "data" wrapper expected by E2E tests and frontend.

        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email', ''));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        // Commercial sources have a single, append-only audit policy regardless
        // of whether they are mutated through a service or a legacy controller.
        $observer = app(CommercialSourceAuditObserver::class);
        foreach ([
            PurchaseOrder::class, PurchaseContract::class, PurchaseInvoice::class,
            PurchaseReturn::class, PurchaseDiscount::class, SalesQuote::class,
            SalesOrder::class, SalesInvoice::class, SalesReturn::class,
            SalesDiscount::class,
        ] as $model) {
            $model::observe($observer);
        }
    }
}
