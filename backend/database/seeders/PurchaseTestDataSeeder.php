<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\Item;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use Illuminate\Support\Facades\DB;

class PurchaseTestDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('PurchaseTestDataSeeder is restricted to local/testing environments.');
        }

        // SIMULATED_DATA only: seed records are isolated pilot fixtures.  The
        // dedicated simulation tenant must be created explicitly; do not use
        // the first operational tenant or create a hidden tenant as fallback.
        $company = Company::query()->where('name', 'SIM-ENTITY-001 — Doanh nghiệp mô phỏng')->first();
        if (!$company) {
            $this->command?->warn('PurchaseTestDataSeeder skipped: run AcademicSimulationSeeder first.');
            return;
        }
        $companyId = $company->id;

        // 1. Suppliers
        $suppliers = [
            [
                'company_id' => $companyId,
                'code' => 'NCC00001',
                'name' => 'SIM-SUPPLIER-001',
                'tax_code' => null,
                'address' => 'SIMULATED_DATA',
                'phone' => null,
                'contact_person_name' => null,
                'is_active' => true,
            ],
            [
                'company_id' => $companyId,
                'code' => 'NCC00002',
                'name' => 'SIM-SUPPLIER-002',
                'tax_code' => null,
                'address' => 'SIMULATED_DATA',
                'phone' => null,
                'contact_person_name' => null,
                'is_active' => true,
            ],
            [
                'company_id' => $companyId,
                'code' => 'NCC00003',
                'name' => 'SIM-SUPPLIER-003',
                'tax_code' => null,
                'address' => 'SIMULATED_DATA',
                'phone' => null,
                'contact_person_name' => null,
                'is_active' => true,
            ],
            [
                'company_id' => $companyId,
                'code' => 'NCC00004',
                'name' => 'SIM-SUPPLIER-004',
                'tax_code' => null,
                'address' => 'SIMULATED_DATA',
                'phone' => null,
                'contact_person_name' => null,
                'is_active' => true,
            ],
            [
                'company_id' => $companyId,
                'code' => 'NCC00005',
                'name' => 'SIM-SUPPLIER-005',
                'tax_code' => null,
                'address' => 'SIMULATED_DATA',
                'phone' => null,
                'contact_person_name' => null,
                'is_active' => true,
            ],
        ];

        foreach ($suppliers as $supData) {
            Supplier::updateOrCreate(
                ['company_id' => $companyId, 'code' => $supData['code']],
                $supData
            );
        }

        // 2. Inventory Items
        $items = [
            [
                'company_id' => $companyId,
                'code' => 'VT00001',
                'name' => 'SIM-ITEM-001 — Hàng hóa mẫu',
                'category_code' => 'HH',
                'category_name' => 'Hàng hóa',
                'unit' => 'Cây',
                'cost_price' => 1200000,
                'selling_price' => 1500000,
                'default_warehouse' => '1561',
                'inventory_account' => '1561',
                'revenue_account' => '5111',
                'cost_account' => '632',
                'vat_rate' => '10',
            ],
            [
                'company_id' => $companyId,
                'code' => 'VT00002',
                'name' => 'SIM-ITEM-002 — Hàng hóa mẫu',
                'category_code' => 'HH',
                'category_name' => 'Hàng hóa',
                'unit' => 'Cuộn',
                'cost_price' => 2450000,
                'selling_price' => 2900000,
                'default_warehouse' => '1561',
                'inventory_account' => '1561',
                'revenue_account' => '5111',
                'cost_account' => '632',
                'vat_rate' => '10',
            ],
            [
                'company_id' => $companyId,
                'code' => 'DV00001',
                'name' => 'SIM-SERVICE-001 — Dịch vụ mẫu',
                'category_code' => 'DV',
                'category_name' => 'Dịch vụ',
                'unit' => 'Gói',
                'cost_price' => 8500000,
                'selling_price' => 12000000,
                'default_warehouse' => '1561',
                'inventory_account' => '1561',
                'revenue_account' => '5113',
                'cost_account' => '6427',
                'vat_rate' => '10',
            ],
            [
                'company_id' => $companyId,
                'code' => 'NVL00001',
                'name' => 'SIM-MATERIAL-001 — Nguyên vật liệu mẫu',
                'category_code' => 'NVL',
                'category_name' => 'Nguyên vật liệu',
                'unit' => 'Bao',
                'cost_price' => 680000,
                'selling_price' => 820000,
                'default_warehouse' => '152',
                'inventory_account' => '152',
                'revenue_account' => '5111',
                'cost_account' => '154',
                'vat_rate' => '10',
            ],
            [
                'company_id' => $companyId,
                'code' => 'CCDC00001',
                'name' => 'SIM-TOOL-001 — Công cụ mẫu',
                'category_code' => 'CCDC',
                'category_name' => 'Công cụ dụng cụ',
                'unit' => 'Bộ',
                'cost_price' => 85000000,
                'selling_price' => 110000000,
                'default_warehouse' => '153',
                'inventory_account' => '153',
                'revenue_account' => '5111',
                'cost_account' => '632',
                'vat_rate' => '10',
            ],
        ];

        foreach ($items as $itData) {
            Item::updateOrCreate(
                ['company_id' => $companyId, 'code' => $itData['code']],
                $itData
            );
        }

        // 3. Purchase Invoices
        $invoicesData = [
            [
                'invoice_number' => 'NK00001',
                'supplier_code' => 'NCC00001',
                'accounting_date' => '2026-08-05',
                'invoice_date' => '2026-08-05',
                'due_date' => '2026-09-04',
                'sub_total' => 120000000,
                'tax_amount' => 12000000,
                'total_amount' => 132000000,
                'description' => 'SIMULATED_DATA — Mua hàng mẫu 01',
                'is_posted' => true,
                'lines' => [
                    [
                        'item_code' => 'VT00001',
                        'quantity' => 100,
                        'unit_price' => 1200000,
                        'amount' => 120000000,
                        'tax_rate' => 10,
                        'tax_amount' => 12000000,
                        'debit_account' => '1561',
                        'credit_account' => '331',
                        'tax_account' => '1331',
                    ]
                ]
            ],
            [
                'invoice_number' => 'NK00002',
                'supplier_code' => 'NCC00002',
                'accounting_date' => '2026-08-08',
                'invoice_date' => '2026-08-08',
                'due_date' => '2026-09-07',
                'sub_total' => 49000000,
                'tax_amount' => 4900000,
                'total_amount' => 53900000,
                'description' => 'SIMULATED_DATA — Mua hàng mẫu 02',
                'is_posted' => true,
                'lines' => [
                    [
                        'item_code' => 'VT00002',
                        'quantity' => 20,
                        'unit_price' => 2450000,
                        'amount' => 49000000,
                        'tax_rate' => 10,
                        'tax_amount' => 4900000,
                        'debit_account' => '1561',
                        'credit_account' => '331',
                        'tax_account' => '1331',
                    ]
                ]
            ],
            [
                'invoice_number' => 'MH00001',
                'supplier_code' => 'NCC00003',
                'accounting_date' => '2026-08-11',
                'invoice_date' => '2026-08-11',
                'due_date' => '2026-08-31',
                'sub_total' => 17000000,
                'tax_amount' => 1700000,
                'total_amount' => 18700000,
                'description' => 'SIMULATED_DATA — Mua dịch vụ mẫu',
                'is_posted' => false,
                'lines' => [
                    [
                        'item_code' => 'DV00001',
                        'quantity' => 2,
                        'unit_price' => 8500000,
                        'amount' => 17000000,
                        'tax_rate' => 10,
                        'tax_amount' => 1700000,
                        'debit_account' => '6427',
                        'credit_account' => '331',
                        'tax_account' => '1331',
                    ]
                ]
            ],
            [
                'invoice_number' => 'NK00003',
                'supplier_code' => 'NCC00004',
                'accounting_date' => '2026-08-14',
                'invoice_date' => '2026-08-14',
                'due_date' => '2026-09-13',
                'sub_total' => 34000000,
                'tax_amount' => 3400000,
                'total_amount' => 37400000,
                'description' => 'SIMULATED_DATA — Mua nguyên vật liệu mẫu',
                'is_posted' => false,
                'lines' => [
                    [
                        'item_code' => 'NVL00001',
                        'quantity' => 50,
                        'unit_price' => 680000,
                        'amount' => 34000000,
                        'tax_rate' => 10,
                        'tax_amount' => 3400000,
                        'debit_account' => '152',
                        'credit_account' => '331',
                        'tax_account' => '1331',
                    ]
                ]
            ],
            [
                'invoice_number' => 'NK00004',
                'supplier_code' => 'NCC00005',
                'accounting_date' => '2026-08-16',
                'invoice_date' => '2026-08-16',
                'due_date' => '2026-09-15',
                'sub_total' => 85000000,
                'tax_amount' => 8500000,
                'total_amount' => 93500000,
                'description' => 'SIMULATED_DATA — Mua công cụ mẫu',
                'is_posted' => true,
                'lines' => [
                    [
                        'item_code' => 'CCDC00001',
                        'quantity' => 1,
                        'unit_price' => 85000000,
                        'amount' => 85000000,
                        'tax_rate' => 10,
                        'tax_amount' => 8500000,
                        'debit_account' => '153',
                        'credit_account' => '331',
                        'tax_account' => '1331',
                    ]
                ]
            ],
        ];

        foreach ($invoicesData as $inv) {
            $supplier = Supplier::where('company_id', $companyId)
                ->where('code', $inv['supplier_code'])
                ->first();
            $invoice = PurchaseInvoice::updateOrCreate(
                ['company_id' => $companyId, 'invoice_number' => $inv['invoice_number']],
                [
                    'company_id' => $companyId,
                    'supplier_id' => $supplier?->id,
                    'supplier_name' => $supplier ? $supplier->name : 'SIM-SUPPLIER-UNKNOWN',
                    'accounting_date' => $inv['accounting_date'],
                    'invoice_date' => $inv['invoice_date'],
                    'due_date' => $inv['due_date'],
                    'sub_total' => $inv['sub_total'],
                    'tax_amount' => $inv['tax_amount'],
                    'total_amount' => $inv['total_amount'],
                    'description' => $inv['description'],
                    'is_posted' => $inv['is_posted'],
                ]
            );

            // Lines
            $invoice->lines()->delete();
            foreach ($inv['lines'] as $line) {
                $item = Item::where('company_id', $companyId)
                    ->where('code', $line['item_code'])
                    ->first();
                $invoice->lines()->create([
                    'item_id' => $item ? $item->id : null,
                    'description' => $item ? $item->name : $inv['description'],
                    'debit_account' => $line['debit_account'],
                    'credit_account' => $line['credit_account'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'amount' => $line['amount'],
                    'tax_rate' => $line['tax_rate'],
                    'tax_amount' => $line['tax_amount'],
                    'tax_account' => $line['tax_account'],
                ]);
            }
        }
    }
}
