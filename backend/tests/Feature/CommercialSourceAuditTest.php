<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PurchaseInvoiceService;
use App\Services\SalesInvoiceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class CommercialSourceAuditTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Supplier $supplier;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::findOrFail(1);
        Sanctum::actingAs(User::factory()->create(['company_id' => $this->company->id]));
        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'code' => 'AUDIT-SUPPLIER',
            'name' => 'Audit supplier',
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'AUDIT-CUSTOMER',
            'name' => 'Audit customer',
        ]);
    }

    public function test_purchase_and_sales_invoices_record_source_lifecycle_events(): void
    {
        $purchase = app(PurchaseInvoiceService::class)->create($this->purchasePayload('AUDIT-PURCHASE'));
        $this->assertAudit($purchase, 'purchase_invoice.created');

        $purchase = app(PurchaseInvoiceService::class)->update($purchase->id, ['description' => 'Updated purchase audit']);
        $updatedPurchase = $this->assertAudit($purchase, 'purchase_invoice.updated');
        $this->assertSame('Hóa đơn mua hàng', $updatedPurchase->old_values['description']);
        $this->assertSame('Updated purchase audit', $updatedPurchase->new_values['description']);
        $this->assertAudit(app(PurchaseInvoiceService::class)->duplicate($purchase->id), 'purchase_invoice.duplicated');

        $sales = app(SalesInvoiceService::class)->create($this->salesPayload('AUDIT-SALES'));
        $this->assertAudit($sales, 'sales_invoice.created');

        $sales = app(SalesInvoiceService::class)->update($sales->id, ['description' => 'Updated sales audit']);
        $updatedSales = $this->assertAudit($sales, 'sales_invoice.updated');
        $this->assertSame('Updated sales audit', $updatedSales->new_values['description']);
        $this->assertAudit(app(SalesInvoiceService::class)->duplicate($sales->id), 'sales_invoice.duplicated');
    }

    public function test_source_audit_failure_rolls_back_purchase_and_sales_source_creation(): void
    {
        $this->app->instance(AuditService::class, new class extends AuditService
        {
            public function record(Model $model, string $action, array $before = [], array $after = [], ?string $correlationId = null, array $metadata = []): AuditLog
            {
                if (in_array($action, ['purchase_invoice.created', 'sales_invoice.created'], true)) {
                    throw new RuntimeException('Injected commercial source audit failure');
                }

                return parent::record($model, $action, $before, $after, $correlationId, $metadata);
            }
        });

        foreach ([
            [PurchaseInvoiceService::class, $this->purchasePayload('FAIL-PURCHASE'), PurchaseInvoice::class, 'FAIL-PURCHASE'],
            [SalesInvoiceService::class, $this->salesPayload('FAIL-SALES'), SalesInvoice::class, 'FAIL-SALES'],
        ] as [$service, $payload, $model, $number]) {
            try {
                app($service)->create($payload);
                $this->fail("{$service} did not reject a failed source audit write");
            } catch (RuntimeException $exception) {
                $this->assertSame('Injected commercial source audit failure', $exception->getMessage());
            }

            $this->assertSame(0, $model::where('company_id', $this->company->id)
                ->where($model === PurchaseInvoice::class ? 'invoice_number' : 'invoice_number', $number)
                ->count());
        }
    }

    private function purchasePayload(string $number): array
    {
        return [
            'company_id' => $this->company->id,
            'invoice_number' => $number,
            'supplier_id' => $this->supplier->id,
            'invoice_date' => '2026-08-20',
            'accounting_date' => '2026-08-20',
            'description' => 'Hóa đơn mua hàng',
            'lines' => [[
                'description' => 'Purchase audit line',
                'quantity' => 1,
                'unit_price' => 100000,
                'debit_account' => '1561',
                'credit_account' => '331',
            ]],
        ];
    }

    private function salesPayload(string $number): array
    {
        return [
            'company_id' => $this->company->id,
            'invoice_number' => $number,
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-08-20',
            'accounting_date' => '2026-08-20',
            'description' => 'Hóa đơn bán hàng',
            'lines' => [[
                'description' => 'Sales audit line',
                'quantity' => 1,
                'unit_price' => 100000,
                'debit_account' => '131',
                'credit_account' => '5111',
            ]],
        ];
    }

    private function assertAudit(Model $source, string $action): AuditLog
    {
        $audit = AuditLog::withoutGlobalScope('company')
            ->where('company_id', $this->company->id)
            ->where('model_type', $source->getMorphClass())
            ->where('model_id', $source->getKey())
            ->where('action', $action)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, $action);

        return $audit;
    }
}
