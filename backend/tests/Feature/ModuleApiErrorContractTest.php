<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankReceipt;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseDiscount;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\SalesReturn;
use App\Models\SalesInvoice;
use App\Models\SalesDiscount;
use App\Models\User;
use App\Services\BankReceiptService;
use App\Services\CashReceiptService;
use App\Services\InventoryReceiptService;
use App\Services\InventoryValuationService;
use App\Services\CostingService;
use App\Services\AuthService;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseInvoiceService;
use App\Services\PurchaseDiscountService;
use App\Services\SalesOrderService;
use App\Services\SalesQuoteService;
use App\Services\SalesReturnService;
use App\Services\SalesInvoiceService;
use App\Services\SalesDiscountService;
use App\Services\ToolsEquipmentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ModuleApiErrorContractTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'API Error Tenant', 'tax_code' => 'API-ERROR']);
        $this->company = $company;
        $user = User::factory()->create();
        $this->configureAccountingTenant($user, $company);
        foreach (['cash.receipts.post', 'bank.receipts.create', 'bank.receipts.post', 'inventory.receipts.post', 'inventory.cost-calculation.run', 'costing.allocate', 'tools.equipment.allocate', 'sales.quotes.create', 'sales.orders.create', 'purchase.returns.post', 'sales.returns.post', 'purchase.invoices.post', 'sales.invoices.post', 'purchase.discounts.post', 'sales.discounts.post'] as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
        }
        $user->givePermissionTo(['cash.receipts.post', 'bank.receipts.create', 'bank.receipts.post', 'inventory.receipts.post', 'inventory.cost-calculation.run', 'costing.allocate', 'tools.equipment.allocate', 'sales.quotes.create', 'sales.orders.create', 'purchase.returns.post', 'sales.returns.post', 'purchase.invoices.post', 'sales.invoices.post', 'purchase.discounts.post', 'sales.discounts.post']);
        Sanctum::actingAs($user);
    }

    public function test_cash_internal_failure_does_not_expose_sql_path_or_secret_and_is_correlated(): void
    {
        $internalDetail = 'SQLSTATE[23000] users.password=sentinel-secret at C:\\private\\Posting.php:42';
        $service = Mockery::mock(CashReceiptService::class);
        $service->shouldReceive('post')->once()->with(999)->andThrow(new RuntimeException($internalDetail));
        $this->app->instance(CashReceiptService::class, $service);
        Log::spy();

        $response = $this->postJson('/api/v1/cash/receipts/999/post')
            ->assertStatus(500)
            ->assertJsonPath('error', 'An unexpected error occurred.');

        $body = $response->getContent();
        $requestId = $response->json('request_id');
        $this->assertIsString($requestId);
        $this->assertTrue(Str::isUuid($requestId));
        $this->assertSame($requestId, $response->headers->get('X-Request-ID'));
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('sentinel-secret', $body);
        $this->assertStringNotContainsString('Posting.php', $body);

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Accounting API request failed.'
                && $context['request_id'] === $requestId
                && $context['exception_class'] === RuntimeException::class
                && ! str_contains(json_encode($context), 'sentinel-secret')
        );
    }

    public function test_bank_model_not_found_has_stable_404_without_model_details(): void
    {
        $exception = (new ModelNotFoundException)->setModel(BankReceipt::class, [987654]);
        $service = Mockery::mock(BankReceiptService::class);
        $service->shouldReceive('post')->once()->with(987654)->andThrow($exception);
        $this->app->instance(BankReceiptService::class, $service);

        $response = $this->postJson('/api/v1/bank/receipts/987654/post')
            ->assertNotFound()
            ->assertJsonPath('error', 'Resource not found.');

        $this->assertStringNotContainsString(BankReceipt::class, $response->getContent());
        $this->assertStringNotContainsString('987654', $response->getContent());
    }

    public function test_typed_conflict_has_stable_409_business_contract(): void
    {
        $service = Mockery::mock(InventoryReceiptService::class);
        $service->shouldReceive('post')->once()->with(77, $this->company->id)
            ->andThrow(new ConflictHttpException('Accounting period is closed.'));
        $this->app->instance(InventoryReceiptService::class, $service);

        $this->postJson('/api/v1/inventory/receipts/77/post')
            ->assertConflict()
            ->assertJsonPath('error', 'Accounting period is closed.');
    }

    public function test_legacy_known_inventory_business_error_remains_400(): void
    {
        $service = Mockery::mock(InventoryReceiptService::class);
        $service->shouldReceive('post')->once()->with(77, $this->company->id)
            ->andThrow(new RuntimeException('Voucher is already posted'));
        $this->app->instance(InventoryReceiptService::class, $service);

        $this->postJson('/api/v1/inventory/receipts/77/post')
            ->assertBadRequest()
            ->assertJsonPath('error', 'Voucher is already posted');
    }

    public function test_non_voucher_integrity_failure_is_not_reported_as_duplicate_voucher_number(): void
    {
        $service = Mockery::mock(InventoryReceiptService::class);
        $service->shouldReceive('post')->once()->with(77, $this->company->id)->andThrow(
            new \Illuminate\Database\QueryException(
                'mysql',
                'insert into inventory_movement_events (...) values (...)',
                [],
                new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry for ime_source_warehouse_type_cycle_uq', 23000),
            )
        );
        $this->app->instance(InventoryReceiptService::class, $service);

        $response = $this->postJson('/api/v1/inventory/receipts/77/post')
            ->assertStatus(500)
            ->assertJsonPath('error', 'An unexpected error occurred.');

        $this->assertStringNotContainsString('voucher_number', $response->getContent());
    }

    public function test_service_validation_exception_remains_422_with_field_errors(): void
    {
        $service = Mockery::mock(CashReceiptService::class);
        $service->shouldReceive('post')->once()->with(88)->andThrow(
            ValidationException::withMessages(['posting_date' => 'The accounting period is closed.'])
        );
        $this->app->instance(CashReceiptService::class, $service);

        $this->postJson('/api/v1/cash/receipts/88/post')
            ->assertUnprocessable()
            ->assertJsonPath('error', 'The given data was invalid.')
            ->assertJsonValidationErrors('posting_date');
    }

    public function test_bank_duplicate_integrity_failure_is_safe_422_validation_error(): void
    {
        foreach (['1121', '131'] as $code) {
            ChartOfAccount::create([
                'company_id' => $this->company->id,
                'code' => $code,
                'name' => "Account $code",
                'type' => 'asset',
                'nature' => 'debit',
                'level' => 1,
                'is_parent' => false,
            ]);
        }
        $bank = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => 'ERROR-CONTRACT-BANK',
            'bank_name' => 'Error Contract Bank',
        ]);
        $payload = [
            'bank_account_id' => $bank->id,
            'voucher_number' => 'BC-SAFE-DUPLICATE',
            'voucher_date' => '2026-08-21',
            'posting_date' => '2026-08-21',
            'lines' => [['debit_account' => '1121', 'credit_account' => '131', 'amount' => 100]],
        ];

        $this->postJson('/api/v1/bank/receipts', $payload)->assertCreated();
        $response = $this->postJson('/api/v1/bank/receipts', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('voucher_number');

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('bank_receipts_voucher_number_unique', $response->getContent());
    }

    public function test_inventory_cost_internal_failure_is_not_returned_as_a_fake_validation_message(): void
    {
        $service = Mockery::mock(InventoryValuationService::class);
        $service->shouldReceive('runCostCalculation')->once()
            ->andThrow(new RuntimeException('SQLSTATE[HY000] C:\\private\\InventoryCost.php secret=cost-secret'));
        $this->app->instance(InventoryValuationService::class, $service);

        $response = $this->postJson('/api/v1/inventory/cost-calculation/run', [])
            ->assertStatus(500)
            ->assertJsonPath('error', 'An unexpected error occurred.');

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('cost-secret', $response->getContent());
        $this->assertStringNotContainsString('InventoryCost.php', $response->getContent());
    }

    public function test_costing_internal_failure_does_not_expose_exception_details(): void
    {
        $internalDetail = 'SQLSTATE[HY000] C:\\private\\Costing.php secret=cost-secret';
        $service = Mockery::mock(CostingService::class);
        $service->shouldReceive('allocateCosts')->once()->with((int) $this->company->id, '2026-08', [])
            ->andThrow(new RuntimeException($internalDetail));
        $this->app->instance(CostingService::class, $service);

        $response = $this->postJson('/api/v1/costing/allocate', ['month' => '2026-08'])
            ->assertServerError()
            ->assertJsonPath('error', 'An unexpected error occurred.')
            ->assertJsonStructure(['request_id']);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('cost-secret', $response->getContent());
        $this->assertStringNotContainsString('Costing.php', $response->getContent());
    }

    public function test_tool_allocation_internal_failure_does_not_expose_exception_details(): void
    {
        $internalDetail = 'SQLSTATE[HY000] C:\\private\\ToolsEquipment.php secret=tool-secret';
        $service = Mockery::mock(ToolsEquipmentService::class);
        $service->shouldReceive('runMonthlyAllocation')->once()->with((int) $this->company->id, '2026-08')
            ->andThrow(new RuntimeException($internalDetail));
        $this->app->instance(ToolsEquipmentService::class, $service);

        $response = $this->postJson('/api/v1/tools/allocate', ['month' => '2026-08'])
            ->assertServerError()
            ->assertJsonPath('error', 'An unexpected error occurred.')
            ->assertJsonStructure(['request_id']);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('tool-secret', $response->getContent());
        $this->assertStringNotContainsString('ToolsEquipment.php', $response->getContent());
    }

    public function test_sales_quote_internal_failure_preserves_success_shape_without_exposing_details(): void
    {
        $internalDetail = 'SQLSTATE[HY000] C:\\private\\SalesQuote.php secret=quote-secret';
        $service = Mockery::mock(SalesQuoteService::class);
        $service->shouldReceive('create')->once()->with(Mockery::type('array'))->andThrow(new RuntimeException($internalDetail));
        $this->app->instance(SalesQuoteService::class, $service);

        $response = $this->postJson('/api/v1/sales/quotes', [])
            ->assertServerError()
            ->assertJsonPath('error', 'An unexpected error occurred.')
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['request_id']);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('quote-secret', $response->getContent());
        $this->assertStringNotContainsString('SalesQuote.php', $response->getContent());
    }

    public function test_sales_order_internal_failure_preserves_success_shape_without_exposing_details(): void
    {
        $internalDetail = 'SQLSTATE[HY000] C:\\private\\SalesOrder.php secret=order-secret';
        $service = Mockery::mock(SalesOrderService::class);
        $service->shouldReceive('create')->once()->with(Mockery::type('array'))->andThrow(new RuntimeException($internalDetail));
        $this->app->instance(SalesOrderService::class, $service);

        $response = $this->postJson('/api/v1/sales/orders', [])
            ->assertServerError()
            ->assertJsonPath('error', 'An unexpected error occurred.')
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['request_id']);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('order-secret', $response->getContent());
        $this->assertStringNotContainsString('SalesOrder.php', $response->getContent());
    }

    public function test_sales_return_internal_failure_preserves_message_shape_without_exposing_details(): void
    {
        $internalDetail = 'SQLSTATE[HY000] C:\\private\\SalesReturn.php secret=sales-return-secret';
        $return = SalesReturn::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'ERR-SR-'.uniqid(),
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
        ]);
        $service = Mockery::mock(SalesReturnService::class);
        $service->shouldReceive('post')->once()->with((int) $return->id)->andThrow(new RuntimeException($internalDetail));
        $this->app->instance(SalesReturnService::class, $service);

        $response = $this->postJson("/api/v1/sales/returns/{$return->id}/post")
            ->assertServerError()
            ->assertJsonPath('error', 'An unexpected error occurred.')
            ->assertJsonPath('message', 'An unexpected error occurred.')
            ->assertJsonStructure(['request_id']);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('sales-return-secret', $response->getContent());
        $this->assertStringNotContainsString('SalesReturn.php', $response->getContent());
    }

    public function test_purchase_return_internal_failure_preserves_message_shape_without_exposing_details(): void
    {
        $internalDetail = 'SQLSTATE[HY000] C:\\private\\PurchaseReturn.php secret=purchase-return-secret';
        $return = PurchaseReturn::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'ERR-PR-'.uniqid(),
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
        ]);
        $service = Mockery::mock(PurchaseReturnService::class);
        $service->shouldReceive('post')->once()->with((int) $return->id)->andThrow(new RuntimeException($internalDetail));
        $this->app->instance(PurchaseReturnService::class, $service);

        $response = $this->postJson("/api/v1/purchase/returns/{$return->id}/post")
            ->assertServerError()
            ->assertJsonPath('error', 'An unexpected error occurred.')
            ->assertJsonPath('message', 'An unexpected error occurred.')
            ->assertJsonStructure(['request_id']);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('purchase-return-secret', $response->getContent());
        $this->assertStringNotContainsString('PurchaseReturn.php', $response->getContent());
    }

    public function test_auth_internal_failure_does_not_expose_exception_details(): void
    {
        $internalDetail = 'SQLSTATE[HY000] C:\\private\\AuthService.php secret=auth-secret';
        $service = Mockery::mock(AuthService::class);
        $service->shouldReceive('login')->once()->with('error@example.test', 'password')->andThrow(new RuntimeException($internalDetail));
        $this->app->instance(AuthService::class, $service);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'error@example.test',
            'password' => 'password',
        ])
            ->assertServerError()
            ->assertJsonPath('error', 'An unexpected error occurred.')
            ->assertJsonPath('message', 'An unexpected error occurred.')
            ->assertJsonStructure(['request_id']);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('auth-secret', $response->getContent());
        $this->assertStringNotContainsString('AuthService.php', $response->getContent());
    }

    public function test_auth_invalid_credentials_keep_the_stable_business_message(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'missing-user@example.test',
            'password' => 'wrong-password',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error', 'Thông tin đăng nhập không chính xác.')
            ->assertJsonPath('message', 'Thông tin đăng nhập không chính xác.');
    }

    public function test_purchase_invoice_internal_failure_does_not_expose_exception_details(): void
    {
        $supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'ERR-SUP-'.uniqid(),
            'name' => 'Error supplier',
        ]);
        $invoice = PurchaseInvoice::create([
            'company_id' => $this->company->id,
            'supplier_id' => $supplier->id,
            'invoice_number' => 'ERR-PI-'.uniqid(),
            'invoice_date' => '2026-08-21',
        ]);
        $service = Mockery::mock(PurchaseInvoiceService::class);
        $service->shouldReceive('post')->once()->with((int) $invoice->id)
            ->andThrow(new RuntimeException('SQLSTATE[HY000] C:\\private\\PurchaseInvoice.php secret=purchase-invoice-secret'));
        $this->app->instance(PurchaseInvoiceService::class, $service);

        $response = $this->postJson("/api/v1/purchase/invoices/{$invoice->id}/post")
            ->assertServerError()
            ->assertJsonPath('error', 'An unexpected error occurred.')
            ->assertJsonStructure(['request_id']);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('purchase-invoice-secret', $response->getContent());
        $this->assertStringNotContainsString('PurchaseInvoice.php', $response->getContent());
    }

    public function test_sales_invoice_internal_failure_does_not_expose_exception_details(): void
    {
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'ERR-CUS-'.uniqid(),
            'name' => 'Error customer',
        ]);
        $invoice = SalesInvoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'ERR-SI-'.uniqid(),
            'invoice_date' => '2026-08-21',
        ]);
        $service = Mockery::mock(SalesInvoiceService::class);
        $service->shouldReceive('post')->once()->with((int) $invoice->id)
            ->andThrow(new RuntimeException('SQLSTATE[HY000] C:\\private\\SalesInvoice.php secret=sales-invoice-secret'));
        $this->app->instance(SalesInvoiceService::class, $service);

        $response = $this->postJson("/api/v1/sales/invoices/{$invoice->id}/post")
            ->assertServerError()
            ->assertJsonPath('error', 'An unexpected error occurred.')
            ->assertJsonStructure(['request_id']);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('sales-invoice-secret', $response->getContent());
        $this->assertStringNotContainsString('SalesInvoice.php', $response->getContent());
    }

    public function test_purchase_discount_internal_failure_preserves_message_shape_without_exposing_details(): void
    {
        $discount = PurchaseDiscount::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'ERR-PD-'.uniqid(),
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
        ]);
        $service = Mockery::mock(PurchaseDiscountService::class);
        $service->shouldReceive('post')->once()->with((int) $discount->id)
            ->andThrow(new RuntimeException('SQLSTATE[HY000] C:\\private\\PurchaseDiscount.php secret=purchase-discount-secret'));
        $this->app->instance(PurchaseDiscountService::class, $service);

        $response = $this->postJson("/api/v1/purchase/discounts/{$discount->id}/post")
            ->assertServerError()
            ->assertJsonPath('error', 'An unexpected error occurred.')
            ->assertJsonPath('message', 'An unexpected error occurred.')
            ->assertJsonStructure(['request_id']);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('purchase-discount-secret', $response->getContent());
        $this->assertStringNotContainsString('PurchaseDiscount.php', $response->getContent());
    }

    public function test_sales_discount_internal_failure_preserves_message_shape_without_exposing_details(): void
    {
        $discount = SalesDiscount::create([
            'company_id' => $this->company->id,
            'voucher_number' => 'ERR-SD-'.uniqid(),
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
        ]);
        $service = Mockery::mock(SalesDiscountService::class);
        $service->shouldReceive('post')->once()->with((int) $discount->id)
            ->andThrow(new RuntimeException('SQLSTATE[HY000] C:\\private\\SalesDiscount.php secret=sales-discount-secret'));
        $this->app->instance(SalesDiscountService::class, $service);

        $response = $this->postJson("/api/v1/sales/discounts/{$discount->id}/post")
            ->assertServerError()
            ->assertJsonPath('error', 'An unexpected error occurred.')
            ->assertJsonPath('message', 'An unexpected error occurred.')
            ->assertJsonStructure(['request_id']);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('sales-discount-secret', $response->getContent());
        $this->assertStringNotContainsString('SalesDiscount.php', $response->getContent());
    }
}
