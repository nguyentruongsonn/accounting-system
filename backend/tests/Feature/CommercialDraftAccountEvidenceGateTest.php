<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Supplier;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Draft intake must not persist guessed account mappings when the production
 * posting-mapping control is enabled. These tests intentionally use direct
 * service calls so the invariant is also covered outside HTTP validation.
 */
class CommercialDraftAccountEvidenceGateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Customer $customer;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Commercial draft gate '.uniqid()]);
        $user = User::factory()->create(['company_id' => $this->company->id]);
        Sanctum::actingAs($user);
        $this->configureAccountingTenant($user, $this->company);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'code' => 'C-'.uniqid(), 'name' => 'Gate customer']);
        $this->supplier = Supplier::create(['company_id' => $this->company->id, 'code' => 'S-'.uniqid(), 'name' => 'Gate supplier']);

        config()->set('accounting.enforce_sales_invoice_posting_account_mappings', true);
        config()->set('accounting.enforce_purchase_invoice_posting_account_mappings', true);
        config()->set('accounting.enforce_return_discount_posting_account_mappings', true);
    }

    #[DataProvider('documentTypes')]
    public function test_create_rejects_missing_accounts_before_any_document_or_line_is_written(string $type): void
    {
        [$service, $table] = $this->serviceAndTable($type);
        $before = $this->countRows($table);

        try {
            app($service)->create($this->payload($type, false));
            $this->fail('Missing account evidence must be rejected before draft creation.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $this->assertSame($before, $this->countRows($table));
    }

    #[DataProvider('documentTypes')]
    public function test_update_rejects_missing_accounts_before_header_or_lines_are_mutated(string $type): void
    {
        [$service, $table] = $this->serviceAndTable($type);
        $this->disableDraftMappingControls();
        $document = app($service)->create($this->payload($type, true));
        $lineBefore = $document->lines()->first();
        $headerBefore = $document->getRawOriginal('description');

        $this->enableDraftMappingControls();

        try {
            app($service)->update($document->id, [
                'description' => 'must not be persisted',
                'lines' => [$this->line($type, false)],
            ]);
            $this->fail('Missing account evidence must be rejected before draft update.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $fresh = $document->fresh(['lines']);
        $this->assertNotNull($fresh);
        $this->assertSame($headerBefore, $fresh->getRawOriginal('description'));
        $this->assertSame($lineBefore->id, $fresh->lines->first()->id);
        $this->assertSame($lineBefore->getRawOriginal('debit_account'), $fresh->lines->first()->getRawOriginal('debit_account'));
        $this->assertSame($lineBefore->getRawOriginal('credit_account'), $fresh->lines->first()->getRawOriginal('credit_account'));
        $this->assertSame(1, $fresh->lines->count());
        $this->assertSame(1, $this->countRows($table));
    }

    public function test_positive_tax_requires_explicit_tax_account_even_when_amount_is_derived(): void
    {
        $payload = $this->payload('sales_discount', true);
        $payload['lines'][0]['tax_rate'] = '10';
        unset($payload['lines'][0]['tax_account']);
        $before = $this->countRows('sales_discounts');

        $this->expectException(ValidationException::class);
        try {
            app(SalesDiscountService::class)->create($payload);
        } finally {
            $this->assertSame($before, $this->countRows('sales_discounts'));
        }
    }

    public function test_export_slip_requires_cogs_and_inventory_evidence_without_selecting_accounts(): void
    {
        $payload = $this->payload('sales_invoice', true);
        $payload['is_export_slip'] = true;
        unset($payload['lines'][0]['cogs_account'], $payload['lines'][0]['inventory_account']);
        $before = $this->countRows('sales_invoices');

        $this->expectException(ValidationException::class);
        try {
            app(SalesInvoiceService::class)->create($payload);
        } finally {
            $this->assertSame($before, $this->countRows('sales_invoices'));
        }
    }

    #[DataProvider('documentTypes')]
    public function test_header_only_update_cannot_leave_a_legacy_persisted_line_without_evidence(string $type): void
    {
        [$service, $table] = $this->serviceAndTable($type);
        $this->disableDraftMappingControls();
        $document = app($service)->create($this->payload($type, false));
        $headerBefore = $document->getRawOriginal('description');
        $this->enableDraftMappingControls();

        try {
            app($service)->update($document->id, ['description' => 'must not update legacy line']);
            $this->fail('Header-only update must validate the persisted line evidence.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $fresh = $document->fresh(['lines']);
        $this->assertSame($headerBefore, $fresh->getRawOriginal('description'));
        $this->assertSame(1, $this->countRows($table));
        $this->assertSame($document->lines->first()->getRawOriginal('debit_account'), $fresh->lines->first()->getRawOriginal('debit_account'));
        $this->assertSame($document->lines->first()->getRawOriginal('credit_account'), $fresh->lines->first()->getRawOriginal('credit_account'));
    }

    #[DataProvider('documentTypes')]
    public function test_positive_derived_tax_requires_tax_account_even_when_amount_is_omitted(string $type): void
    {
        [$service, $table] = $this->serviceAndTable($type);
        $payload = $this->payload($type, true);
        $payload['lines'][0]['tax_rate'] = '10';
        // A zero discount is commonly serialized even when amount is omitted;
        // it must not hide the positive quantity × unit-price tax base.
        $payload['lines'][0]['discount_amount'] = '0';
        unset($payload['lines'][0]['amount'], $payload['lines'][0]['tax_amount'], $payload['lines'][0]['tax_account']);
        $before = $this->countRows($table);

        try {
            app($service)->create($payload);
            $this->fail('A positive derived tax amount must require explicit tax-account evidence.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $this->assertSame($before, $this->countRows($table));
    }

    public static function documentTypes(): array
    {
        return [
            'sales invoice' => ['sales_invoice'],
            'purchase invoice' => ['purchase_invoice'],
            'sales return' => ['sales_return'],
            'purchase return' => ['purchase_return'],
            'sales discount' => ['sales_discount'],
            'purchase discount' => ['purchase_discount'],
        ];
    }

    private function payload(string $type, bool $explicit): array
    {
        $line = $this->line($type, $explicit);
        $common = ['lines' => [$line]];

        return match ($type) {
            'sales_invoice' => $common + [
                'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
                'invoice_number' => 'SI-GATE-'.uniqid(), 'invoice_date' => '2026-08-22',
            ],
            'purchase_invoice' => $common + [
                'company_id' => $this->company->id, 'supplier_id' => $this->supplier->id,
                'invoice_number' => 'PI-GATE-'.uniqid(), 'invoice_date' => '2026-08-22',
            ],
            'sales_return' => $common + [
                'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
                'voucher_number' => 'SR-GATE-'.uniqid(), 'voucher_date' => '2026-08-22', 'is_inward' => false,
            ],
            'purchase_return' => $common + [
                'company_id' => $this->company->id, 'supplier_id' => $this->supplier->id,
                'voucher_number' => 'PR-GATE-'.uniqid(), 'voucher_date' => '2026-08-22', 'is_outward' => false,
            ],
            'sales_discount' => $common + [
                'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
                'voucher_number' => 'SD-GATE-'.uniqid(), 'voucher_date' => '2026-08-22',
            ],
            'purchase_discount' => $common + [
                'company_id' => $this->company->id, 'supplier_id' => $this->supplier->id,
                'voucher_number' => 'PD-GATE-'.uniqid(), 'voucher_date' => '2026-08-22',
            ],
        };
    }

    private function line(string $type, bool $explicit): array
    {
        $base = ['quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '0'];
        if (! $explicit) {
            return $base;
        }

        return match ($type) {
            'sales_invoice' => $base + ['debit_account' => '131', 'credit_account' => '5111'],
            'purchase_invoice' => $base + ['debit_account' => '1561', 'credit_account' => '331'],
            'sales_return' => $base + ['debit_account' => '5212', 'credit_account' => '131'],
            'purchase_return' => $base + ['debit_account' => '331', 'credit_account' => '1561'],
            'sales_discount' => $base + ['debit_account' => '5213', 'credit_account' => '131'],
            'purchase_discount' => $base + ['debit_account' => '331', 'credit_account' => '1561'],
        };
    }

    private function serviceAndTable(string $type): array
    {
        return match ($type) {
            'sales_invoice' => [SalesInvoiceService::class, 'sales_invoices'],
            'purchase_invoice' => [PurchaseInvoiceService::class, 'purchase_invoices'],
            'sales_return' => [SalesReturnService::class, 'sales_returns'],
            'purchase_return' => [PurchaseReturnService::class, 'purchase_returns'],
            'sales_discount' => [SalesDiscountService::class, 'sales_discounts'],
            'purchase_discount' => [PurchaseDiscountService::class, 'purchase_discounts'],
        };
    }

    private function disableDraftMappingControls(): void
    {
        config()->set('accounting.enforce_sales_invoice_posting_account_mappings', false);
        config()->set('accounting.enforce_purchase_invoice_posting_account_mappings', false);
        config()->set('accounting.enforce_return_discount_posting_account_mappings', false);
    }

    private function enableDraftMappingControls(): void
    {
        config()->set('accounting.enforce_sales_invoice_posting_account_mappings', true);
        config()->set('accounting.enforce_purchase_invoice_posting_account_mappings', true);
        config()->set('accounting.enforce_return_discount_posting_account_mappings', true);
    }

    private function countRows(string $table): int
    {
        return (int) \DB::table($table)->where('company_id', $this->company->id)->count();
    }
}
