<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseInvoiceQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PurchaseInvoiceQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_is_scoped_to_authenticated_company(): void
    {
        [$companyA, $supplierA] = $this->catalogue('A');
        [$companyB, $supplierB] = $this->catalogue('B');
        $this->invoice($companyA, $supplierA, 'HDMH-2026-0001');
        $this->invoice($companyB, $supplierB, 'HDMH-2026-0002');

        $user = User::factory()->create(['company_id' => $companyA->id]);
        $this->actingAs($user, 'sanctum');

        $service = app(PurchaseInvoiceQueryService::class);

        $this->assertCount(1, $service->getAll($companyA->id));

        $this->expectException(ValidationException::class);
        $service->getAll($companyB->id);
    }

    public function test_detail_cannot_cross_company_boundary(): void
    {
        [$companyA, $supplierA] = $this->catalogue('A');
        [$companyB, $supplierB] = $this->catalogue('B');
        $foreignInvoice = $this->invoice($companyB, $supplierB, 'HDMH-2026-0001');

        $user = User::factory()->create(['company_id' => $companyA->id]);
        $this->actingAs($user, 'sanctum');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(PurchaseInvoiceQueryService::class)->getById($foreignInvoice->id);
    }

    public function test_next_code_uses_company_scoped_sequence(): void
    {
        [$company, $supplier] = $this->catalogue('A');
        $this->invoice($company, $supplier, 'HDMH-2026-0003');

        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user, 'sanctum');

        $this->assertSame(
            'HDMH-2026-0004',
            app(PurchaseInvoiceQueryService::class)->generateNextCode($company->id),
        );
    }

    public function test_company_context_is_required_without_authenticated_user(): void
    {
        $this->expectException(ValidationException::class);

        app(PurchaseInvoiceQueryService::class)->getAll();
    }

    /** @return array{0: Company, 1: Supplier} */
    private function catalogue(string $suffix): array
    {
        $company = Company::create(['name' => 'Purchase query company '.$suffix]);
        $supplier = Supplier::create([
            'company_id' => $company->id,
            'code' => 'SUP-QUERY-'.$suffix,
            'name' => 'Purchase query supplier '.$suffix,
            'is_active' => true,
        ]);

        return [$company, $supplier];
    }

    private function invoice(Company $company, Supplier $supplier, string $number): PurchaseInvoice
    {
        return PurchaseInvoice::create([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'invoice_number' => $number,
            'invoice_date' => '2026-01-15',
            'accounting_date' => '2026-01-15',
            'sub_total' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'status' => 'draft',
            'is_posted' => false,
            'currency' => 'VND',
        ]);
    }
}
