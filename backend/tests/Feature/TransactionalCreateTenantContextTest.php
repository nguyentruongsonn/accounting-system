<?php

namespace Tests\Feature;

use App\Services\FixedAssetService;
use App\Services\PurchaseDiscountService;
use App\Services\PurchaseInvoiceService;
use App\Services\PurchaseReturnService;
use App\Services\SalesDiscountService;
use App\Services\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransactionalCreateTenantContextTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Service callers cannot silently fall back to tenant 1 when a trusted
     * company context is absent. HTTP callers get this context from the
     * transactional_tenant middleware; direct callers must supply it.
     */
    public function test_create_and_code_paths_fail_closed_without_company_context(): void
    {
        $this->assertValidationFailure(
            fn () => app(FixedAssetService::class)->generateNextCode(),
            'fixed asset code generation'
        );
        $this->assertValidationFailure(
            fn () => app(PurchaseDiscountService::class)->create([]),
            'purchase discount creation'
        );
        $this->assertValidationFailure(
            fn () => app(PurchaseInvoiceService::class)->create([]),
            'purchase invoice creation'
        );
        $this->assertValidationFailure(
            fn () => app(PurchaseReturnService::class)->create([]),
            'purchase return creation'
        );
        $this->assertValidationFailure(
            fn () => app(SalesDiscountService::class)->create([]),
            'sales discount creation'
        );
        $this->assertValidationFailure(
            fn () => app(SalesInvoiceService::class)->create([]),
            'sales invoice creation'
        );
    }

    private function assertValidationFailure(callable $operation, string $label): void
    {
        try {
            $operation();
            $this->fail("Expected {$label} to require an explicit company context.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company_id', $exception->errors(), $label);
        }
    }
}
