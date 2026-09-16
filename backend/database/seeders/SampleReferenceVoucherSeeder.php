<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SampleReferenceVoucherSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('SampleReferenceVoucherSeeder is restricted to local/testing environments.');
        }

        // SIMULATED_DATA only: never use these references as a real entity or
        // statutory source dossier.  The dedicated simulation tenant must be
        // created explicitly by AcademicSimulationSeeder; never fall back to
        // the first operational tenant or create one implicitly.
        $company = Company::query()->where('name', 'SIM-ENTITY-001 — Doanh nghiệp mô phỏng')->first();
        if (!$company) {
            $this->command?->warn('SampleReferenceVoucherSeeder skipped: run AcademicSimulationSeeder first.');
            return;
        }
        $companyId = $company->id;

        $c1 = Customer::firstOrCreate(
            ['company_id' => $companyId, 'code' => 'SIM-CUSTOMER-001'],
            ['company_id' => $companyId, 'name' => 'SIM-CUSTOMER-001', 'tax_code' => null, 'address' => 'SIMULATED_DATA', 'customer_type' => 'org']
        );
        $c2 = Customer::firstOrCreate(
            ['company_id' => $companyId, 'code' => 'SIM-CUSTOMER-002'],
            ['company_id' => $companyId, 'name' => 'SIM-CUSTOMER-002', 'tax_code' => null, 'address' => 'SIMULATED_DATA', 'customer_type' => 'org']
        );

        $s1 = Supplier::firstOrCreate(
            ['company_id' => $companyId, 'code' => 'SIM-SUPPLIER-001'],
            ['company_id' => $companyId, 'name' => 'SIM-SUPPLIER-001', 'tax_code' => null, 'address' => 'SIMULATED_DATA', 'supplier_type' => 'org']
        );

        // 1. Sales Invoices
        $si1 = SalesInvoice::create([
            'company_id' => $companyId,
            'customer_id' => $c1->id,
            'customer_name' => $c1->name,
            'customer_address' => $c1->address,
            'invoice_number' => 'HDBH-2026-001',
            'invoice_date' => '2026-08-05',
            'accounting_date' => '2026-08-05',
            'due_date' => '2026-09-05',
            'sub_total' => 45000000,
            'tax_amount' => 4500000,
            'total_amount' => 49500000,
            'description' => 'Xuất bán máy chủ Server Dell PowerEdge',
            'currency' => 'VND',
            'exchange_rate' => 1,
            'is_posted' => true,
        ]);
        $si1->lines()->create([
            'description' => 'Máy chủ Dell PowerEdge R750',
            'debit_account' => '131',
            'credit_account' => '511',
            'quantity' => 1,
            'unit_price' => 45000000,
            'amount' => 45000000,
            'tax_rate' => 10,
            'tax_amount' => 4500000,
            'tax_account' => '3331',
        ]);

        $si2 = SalesInvoice::create([
            'company_id' => $companyId,
            'customer_id' => $c2->id,
            'customer_name' => $c2->name,
            'customer_address' => $c2->address,
            'invoice_number' => 'HDBH-2026-002',
            'invoice_date' => '2026-08-12',
            'accounting_date' => '2026-08-12',
            'due_date' => '2026-09-12',
            'sub_total' => 28000000,
            'tax_amount' => 2800000,
            'total_amount' => 30800000,
            'description' => 'Cung cấp bản quyền phần mềm Kế toán Quản trị',
            'currency' => 'VND',
            'exchange_rate' => 1,
            'is_posted' => true,
        ]);
        $si2->lines()->create([
            'description' => 'Phần mềm kế toán doanh nghiệp',
            'debit_account' => '131',
            'credit_account' => '511',
            'quantity' => 2,
            'unit_price' => 14000000,
            'amount' => 28000000,
            'tax_rate' => 10,
            'tax_amount' => 2800000,
            'tax_account' => '3331',
        ]);

        // 2. Purchase Invoice
        $pi1 = PurchaseInvoice::create([
            'company_id' => $companyId,
            'supplier_id' => $s1->id,
            'supplier_name' => $s1->name,
            'invoice_number' => 'HDMH-2026-001',
            'invoice_date' => '2026-08-08',
            'accounting_date' => '2026-08-08',
            'due_date' => '2026-09-08',
            'sub_total' => 18000000,
            'tax_amount' => 1800000,
            'total_amount' => 19800000,
            'description' => 'Mua thiết bị Switch Cisco Catalyst 24 Port',
            'currency' => 'VND',
            'payment_status' => 'unpaid',
            'is_posted' => true,
        ]);
        $pi1->lines()->create([
            'description' => 'Switch Cisco Catalyst 24 Port',
            'debit_account' => '156',
            'credit_account' => '331',
            'quantity' => 2,
            'unit_price' => 9000000,
            'amount' => 18000000,
            'tax_rate' => 10,
            'tax_amount' => 1800000,
            'tax_account' => '1331',
        ]);
    }
}
