<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\PurchaseDiscountService;
use App\Services\PurchaseInvoiceService;
use App\Services\PurchaseReturnService;
use App\Services\SalesDiscountService;
use App\Services\SalesInvoiceService;
use App\Services\SalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class ReturnDiscountTenantCodeBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_commercial_code_generators_reject_a_foreign_company(): void
    {
        $company = Company::create(['name' => 'Code boundary owner '.uniqid()]);
        $foreignCompany = Company::create(['name' => 'Code boundary foreign '.uniqid()]);
        Sanctum::actingAs(User::factory()->create(['company_id' => $company->id]));

        $cases = [
            [PurchaseReturnService::class, ValidationException::class],
            [PurchaseDiscountService::class, ValidationException::class],
            [PurchaseInvoiceService::class, ValidationException::class],
            [SalesReturnService::class, AccessDeniedHttpException::class],
            [SalesDiscountService::class, ValidationException::class],
            [SalesInvoiceService::class, ValidationException::class],
        ];

        foreach ($cases as [$serviceClass, $exceptionClass]) {
            try {
                app($serviceClass)->generateNextCode($foreignCompany->id);
                $this->fail("{$serviceClass} generated a code for a foreign company.");
            } catch (\Throwable $exception) {
                $this->assertInstanceOf($exceptionClass, $exception);
            }
        }
    }
}
