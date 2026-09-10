<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PurchaseReturn;
use App\Models\SalesReturn;
use App\Models\User;
use App\Services\PurchaseReturnService;
use App\Services\SalesOrderService;
use App\Services\SalesQuoteService;
use App\Services\SalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class SalesTransactionalTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_number_generation_and_creation_reject_foreign_company_even_when_called_in_process(): void
    {
        $foreignCompany = Company::create([
            'name' => 'Foreign sales company',
            'tax_code' => 'FOREIGN-SALES',
            'address' => 'Foreign',
        ]);
        $actor = User::factory()->create(['company_id' => 1]);
        Sanctum::actingAs($actor);

        foreach ([
            SalesQuoteService::class,
            SalesOrderService::class,
            SalesReturnService::class,
        ] as $serviceClass) {
            $service = app($serviceClass);

            try {
                $service->generateNextCode($foreignCompany->id);
                $this->fail("{$serviceClass} generated a number for a foreign company.");
            } catch (AccessDeniedHttpException) {
                $this->assertTrue(true);
            }

            try {
                $service->create(['company_id' => $foreignCompany->id]);
                $this->fail("{$serviceClass} created a document for a foreign company.");
            } catch (AccessDeniedHttpException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_return_lifecycle_methods_scope_foreign_sources_to_authenticated_company(): void
    {
        $foreignCompany = Company::create([
            'name' => 'Foreign return company',
            'tax_code' => 'FOREIGN-RETURN',
        ]);
        $actor = User::factory()->create(['company_id' => 1]);
        Sanctum::actingAs($actor);

        $salesReturn = SalesReturn::withoutGlobalScope('company')->create([
            'company_id' => $foreignCompany->id,
            'voucher_number' => 'FOREIGN-SR-001',
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'status' => 'draft',
            'is_posted' => false,
        ]);
        $purchaseReturn = PurchaseReturn::withoutGlobalScope('company')->create([
            'company_id' => $foreignCompany->id,
            'voucher_number' => 'FOREIGN-PR-001',
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'status' => 'draft',
            'is_posted' => false,
        ]);

        foreach ([
            [SalesReturnService::class, $salesReturn->id],
            [PurchaseReturnService::class, $purchaseReturn->id],
        ] as [$serviceClass, $id]) {
            $service = app($serviceClass);
            foreach (['getById', 'update', 'delete', 'post', 'unpost', 'void', 'duplicate'] as $method) {
                try {
                    $method === 'update'
                        ? $service->{$method}($id, [])
                        : $service->{$method}($id);
                    $this->fail("{$serviceClass}::{$method} resolved a foreign return.");
                } catch (ModelNotFoundException) {
                    $this->assertTrue(true);
                }
            }
        }

        $this->assertDatabaseHas('sales_returns', ['id' => $salesReturn->id, 'company_id' => $foreignCompany->id]);
        $this->assertDatabaseHas('purchase_returns', ['id' => $purchaseReturn->id, 'company_id' => $foreignCompany->id]);
    }
}
