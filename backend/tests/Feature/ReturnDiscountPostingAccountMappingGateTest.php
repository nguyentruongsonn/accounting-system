<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PurchaseDiscount;
use App\Models\PurchaseReturn;
use App\Models\SalesDiscount;
use App\Models\SalesReturn;
use App\Models\User;
use App\Services\PurchaseDiscountService;
use App\Services\PurchaseReturnService;
use App\Services\SalesDiscountService;
use App\Services\SalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReturnDiscountPostingAccountMappingGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every commercial adjustment posting path must fail closed while its
     * legacy COA defaults have no owner-approved resolver. The fixture uses
     * headers only intentionally: the gate must run before line/account or
     * journal mutation and does not require invented mappings.
     */
    #[DataProvider('postingCases')]
    public function test_return_discount_posting_fails_closed_without_mapping(
        string $modelClass,
        string $serviceClass,
        string $voucherPrefix,
    ): void {
        $company = Company::create(['name' => 'Return discount mapping gate '.uniqid()]);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user);
        config()->set('accounting.enforce_return_discount_posting_account_mappings', true);

        $document = $modelClass::create([
            'company_id' => $company->id,
            'voucher_number' => $voucherPrefix.'-'.uniqid(),
            'voucher_date' => '2026-08-21',
            'accounting_date' => '2026-08-21',
            'total_amount' => '100.00',
            'is_posted' => false,
            'status' => 'draft',
        ]);

        try {
            app($serviceClass)->post($document->id);
            $this->fail('Return/discount posting must fail closed without approved account mappings.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('account_mappings', $exception->errors());
        }

        $this->assertDatabaseHas($document->getTable(), [
            'id' => $document->id,
            'company_id' => $company->id,
            'is_posted' => false,
            'journal_entry_id' => null,
        ]);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public static function postingCases(): array
    {
        return [
            'purchase return' => [PurchaseReturn::class, PurchaseReturnService::class, 'PR-GATE'],
            'purchase discount' => [PurchaseDiscount::class, PurchaseDiscountService::class, 'PD-GATE'],
            'sales return' => [SalesReturn::class, SalesReturnService::class, 'SR-GATE'],
            'sales discount' => [SalesDiscount::class, SalesDiscountService::class, 'SD-GATE'],
        ];
    }
}
