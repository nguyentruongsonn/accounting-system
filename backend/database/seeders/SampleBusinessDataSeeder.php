<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\BankPayment;
use App\Models\BankReceipt;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\InventoryIssue;
use App\Models\InventoryReceipt;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SampleBusinessDataSeeder extends Seeder
{
    public function run(): void
    {
        $companies = Company::all();
        if ($companies->isEmpty()) {
            return;
        }

        foreach ($companies as $company) {
            $this->seedCompanyData($company);
        }
    }

    public function seedCompanyData(Company $company): void
    {
        // 1. Warehouses (Kho hàng)
        DB::table('warehouses')
            ->whereNotIn('company_id', Company::pluck('id'))
            ->update(['company_id' => $company->id]);

        $warehouses = [
            ['code' => 'KHO_TONG', 'name' => 'Kho tổng', 'default_account' => '156', 'address' => 'Hà Nội', 'manager_name' => 'Nguyễn Văn Kho'],
            ['code' => 'KHO_HH', 'name' => 'Kho hàng hóa', 'default_account' => '1561', 'address' => 'Hà Nội', 'manager_name' => 'Trần Thủ Kho'],
            ['code' => 'KHO_NVL', 'name' => 'Kho nguyên vật liệu', 'default_account' => '152', 'address' => 'Khu CN Phố Nối', 'manager_name' => 'Lê Vật Liệu'],
            ['code' => 'KHO_TP', 'name' => 'Kho thành phẩm', 'default_account' => '155', 'address' => 'Khu CN Phố Nối', 'manager_name' => 'Phạm Thành Phẩm'],
            ['code' => 'KHO_CCDC', 'name' => 'Kho công cụ dụng cụ', 'default_account' => '153', 'address' => 'Hà Nội', 'manager_name' => 'Hoàng Dụng Cụ'],
        ];

        foreach ($warehouses as $wh) {
            Warehouse::withoutGlobalScope('company')->updateOrCreate(
                ['code' => $wh['code']],
                array_merge($wh, ['company_id' => $company->id, 'is_active' => true])
            );
        }

        // 2. Bank Accounts (Tài khoản ngân hàng)
        $banks = [
            [
                'account_number' => '1012345678',
                'bank_name' => 'Ngân hàng TMCP Ngoại thương Việt Nam (Vietcombank)',
                'bank_code' => 'VCB',
                'branch' => 'Chi nhánh Thăng Long, Hà Nội',
                'account_holder' => $company->name,
                'currency' => 'VND',
                'is_active' => true,
            ],
            [
                'account_number' => '1903456789',
                'bank_name' => 'Ngân hàng TMCP Kỹ thương Việt Nam (Techcombank)',
                'bank_code' => 'TCB',
                'branch' => 'Chi nhánh Hoàn Kiếm, Hà Nội',
                'account_holder' => $company->name,
                'currency' => 'VND',
                'is_active' => true,
            ],
            [
                'account_number' => '2151000123',
                'bank_name' => 'Ngân hàng TMCP Đầu tư và Phát triển Việt Nam (BIDV)',
                'bank_code' => 'BIDV',
                'branch' => 'Chi nhánh Cầu Giấy, Hà Nội',
                'account_holder' => $company->name,
                'currency' => 'VND',
                'is_active' => true,
            ],
        ];

        foreach ($banks as $b) {
            BankAccount::withoutGlobalScope('company')->updateOrCreate(
                ['account_number' => $b['account_number']],
                array_merge($b, ['company_id' => $company->id])
            );
        }

        // 3. Customers (Khách hàng)
        $customers = [
            [
                'code' => 'KH00001',
                'name' => 'Công ty Cổ phần Thương mại & Dịch vụ Hoàng Phát',
                'tax_code' => '0108923456',
                'address' => '125 Hoàng Hoa Thám, Ba Đình, Hà Nội',
                'phone' => '02438889999',
                'customer_type' => 'org',
                'is_customer' => true,
                'is_active' => true,
            ],
            [
                'code' => 'KH00002',
                'name' => 'Công ty TNHH Đầu tư & Phát triển Công nghệ Minh An',
                'tax_code' => '0107654321',
                'address' => '88 Duy Tân, Cầu Giấy, Hà Nội',
                'phone' => '02437654321',
                'customer_type' => 'org',
                'is_customer' => true,
                'is_active' => true,
            ],
            [
                'code' => 'KH00003',
                'name' => 'Tập đoàn Viễn thông & Công nghệ Sao Mai',
                'tax_code' => '0309876543',
                'address' => 'Tòa nhà Landmark 81, Bình Thạnh, TP. Hồ Chí Minh',
                'phone' => '02839998888',
                'customer_type' => 'org',
                'is_customer' => true,
                'is_active' => true,
            ],
            [
                'code' => 'KH00004',
                'name' => 'Công ty Cổ phần Xây dựng & Thương mại Thăng Long',
                'tax_code' => '0105432198',
                'address' => '45 Lê Văn Lương, Thanh Xuân, Hà Nội',
                'phone' => '02435556666',
                'customer_type' => 'org',
                'is_customer' => true,
                'is_active' => true,
            ],
            [
                'code' => 'KH00005',
                'name' => 'Nguyễn Văn Tuấn',
                'tax_code' => null,
                'address' => 'Số 12 Chùa Bộc, Đống Đa, Hà Nội',
                'phone' => '0988123456',
                'customer_type' => 'individual',
                'is_customer' => true,
                'is_active' => true,
            ],
        ];

        foreach ($customers as $c) {
            Customer::withoutGlobalScope('company')->withTrashed()->updateOrCreate(
                ['company_id' => $company->id, 'code' => $c['code']],
                array_merge($c, ['company_id' => $company->id, 'deleted_at' => null])
            );
        }

        // 4. Suppliers (Nhà cung cấp)
        $suppliers = [
            [
                'code' => 'NCC00001',
                'name' => 'Công ty TNHH Thiết bị & Công nghệ FPT',
                'tax_code' => '0101234888',
                'address' => 'Tòa nhà FPT, Phố Duy Tân, Cầu Giấy, Hà Nội',
                'phone' => '02473007300',
                'supplier_type' => 'org',
                'is_supplier' => true,
                'is_active' => true,
            ],
            [
                'code' => 'NCC00002',
                'name' => 'Công ty Cổ phần Máy tính Vĩnh Xuân',
                'tax_code' => '0102345999',
                'address' => '39 Thái Hà, Đống Đa, Hà Nội',
                'phone' => '02438573210',
                'supplier_type' => 'org',
                'is_supplier' => true,
                'is_active' => true,
            ],
            [
                'code' => 'NCC00003',
                'name' => 'Công ty TNHH Giấy & Văn phòng phẩm Hồng Hà',
                'tax_code' => '0103456777',
                'address' => '25 Lý Thường Kiệt, Hoàn Kiếm, Hà Nội',
                'phone' => '02438252250',
                'supplier_type' => 'org',
                'is_supplier' => true,
                'is_active' => true,
            ],
            [
                'code' => 'NCC00004',
                'name' => 'Công ty Cổ phần Thiết bị Văn phòng Phúc Anh',
                'tax_code' => '0104567111',
                'address' => '15 Xã Đàn, Đống Đa, Hà Nội',
                'phone' => '02435737383',
                'supplier_type' => 'org',
                'is_supplier' => true,
                'is_active' => true,
            ],
            [
                'code' => 'NCC00005',
                'name' => 'Tổng Công ty Viễn thông Viettel',
                'tax_code' => '0100109106',
                'address' => 'Số 1 Giang Văn Minh, Ba Đình, Hà Nội',
                'phone' => '18008098',
                'supplier_type' => 'org',
                'is_supplier' => true,
                'is_active' => true,
            ],
        ];

        foreach ($suppliers as $s) {
            Supplier::withoutGlobalScope('company')->withTrashed()->updateOrCreate(
                ['company_id' => $company->id, 'code' => $s['code']],
                array_merge($s, ['company_id' => $company->id, 'deleted_at' => null])
            );
        }

        // 5. Employees (Nhân viên)
        $employees = [
            [
                'code' => 'NV00001',
                'name' => 'Nguyễn Văn An',
                'department' => 'Ban Giám đốc',
                'position' => 'Giám đốc',
                'gender' => 'male',
                'phone' => '0912345678',
                'email' => 'an.nguyen@accounting.local',
                'base_salary' => 35000000,
                'status' => 'active',
            ],
            [
                'code' => 'NV00002',
                'name' => 'Trần Thị Bình',
                'department' => 'Phòng Kế toán',
                'position' => 'Kế toán trưởng',
                'gender' => 'female',
                'phone' => '0923456789',
                'email' => 'binh.tran@accounting.local',
                'base_salary' => 22000000,
                'status' => 'active',
            ],
            [
                'code' => 'NV00003',
                'name' => 'Lê Hoàng Long',
                'department' => 'Phòng Kinh doanh',
                'position' => 'Nhân viên kinh doanh',
                'gender' => 'male',
                'phone' => '0934567890',
                'email' => 'long.le@accounting.local',
                'base_salary' => 15000000,
                'status' => 'active',
            ],
            [
                'code' => 'NV00004',
                'name' => 'Phạm Thu Hà',
                'department' => 'Phòng Mua hàng',
                'position' => 'Nhân viên mua hàng',
                'gender' => 'female',
                'phone' => '0945678901',
                'email' => 'ha.pham@accounting.local',
                'base_salary' => 14000000,
                'status' => 'active',
            ],
            [
                'code' => 'NV00005',
                'name' => 'Đỗ Văn Hùng',
                'department' => 'Phòng Kho vận',
                'position' => 'Thủ kho',
                'gender' => 'male',
                'phone' => '0956789012',
                'email' => 'hung.do@accounting.local',
                'base_salary' => 12000000,
                'status' => 'active',
            ],
        ];

        foreach ($employees as $e) {
            Employee::withoutGlobalScope('company')->withTrashed()->updateOrCreate(
                ['company_id' => $company->id, 'code' => $e['code']],
                array_merge($e, ['company_id' => $company->id, 'deleted_at' => null])
            );
        }

        // 6. Items & Services (Hàng hóa, dịch vụ)
        $items = [
            [
                'type' => 'Goods',
                'item_type' => 'goods',
                'code' => 'HH001',
                'name' => 'Laptop Dell Latitude 5540 i7 16GB',
                'unit' => 'Chiếc',
                'cost_price' => 20000000,
                'selling_price' => 25000000,
                'sale_price' => 25000000,
                'inventory_account' => '1561',
                'revenue_account' => '5111',
                'cost_account' => '632',
                'is_active' => true,
            ],
            [
                'type' => 'Goods',
                'item_type' => 'goods',
                'code' => 'HH002',
                'name' => 'Màn hình Dell UltraSharp U2422H 24 inch',
                'unit' => 'Chiếc',
                'cost_price' => 5500000,
                'selling_price' => 7000000,
                'sale_price' => 7000000,
                'inventory_account' => '1561',
                'revenue_account' => '5111',
                'cost_account' => '632',
                'is_active' => true,
            ],
            [
                'type' => 'Goods',
                'item_type' => 'goods',
                'code' => 'HH003',
                'name' => 'Máy in HP LaserJet Pro M404dn',
                'unit' => 'Chiếc',
                'cost_price' => 6000000,
                'selling_price' => 8000000,
                'sale_price' => 8000000,
                'inventory_account' => '1561',
                'revenue_account' => '5111',
                'cost_account' => '632',
                'is_active' => true,
            ],
            [
                'type' => 'Goods',
                'item_type' => 'goods',
                'code' => 'HH004',
                'name' => 'Giấy in Double A A4 70gsm',
                'unit' => 'Ram',
                'cost_price' => 65000,
                'selling_price' => 85000,
                'sale_price' => 85000,
                'inventory_account' => '1561',
                'revenue_account' => '5111',
                'cost_account' => '632',
                'is_active' => true,
            ],
            [
                'type' => 'Goods',
                'item_type' => 'goods',
                'code' => 'HH005',
                'name' => 'Ổ cứng SSD Samsung 980 Pro 1TB',
                'unit' => 'Chiếc',
                'cost_price' => 2200000,
                'selling_price' => 2900000,
                'sale_price' => 2900000,
                'inventory_account' => '1561',
                'revenue_account' => '5111',
                'cost_account' => '632',
                'is_active' => true,
            ],
            [
                'type' => 'Service',
                'item_type' => 'service',
                'code' => 'DV001',
                'name' => 'Gói dịch vụ bảo trì phần mềm & hệ thống IT',
                'unit' => 'Gói',
                'cost_price' => 0,
                'selling_price' => 15000000,
                'sale_price' => 15000000,
                'inventory_account' => '1561',
                'revenue_account' => '5113',
                'cost_account' => '632',
                'is_active' => true,
            ],
        ];

        foreach ($items as $it) {
            Item::withoutGlobalScope('company')->updateOrCreate(
                ['company_id' => $company->id, 'code' => $it['code']],
                array_merge($it, ['company_id' => $company->id])
            );
        }

        // 7. Transactions & Journal Entries
        $fiscalYear = FiscalYear::withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->where('year', 2026)
            ->first();

        if (!$fiscalYear) {
            return;
        }

        $cHoangPhat = Customer::withoutGlobalScope('company')->where('company_id', $company->id)->where('code', 'KH00001')->first();
        $cMinhAn = Customer::withoutGlobalScope('company')->where('company_id', $company->id)->where('code', 'KH00002')->first();
        $sFpt = Supplier::withoutGlobalScope('company')->where('company_id', $company->id)->where('code', 'NCC00001')->first();
        $sPhucAnh = Supplier::withoutGlobalScope('company')->where('company_id', $company->id)->where('code', 'NCC00004')->first();
        $bVcb = BankAccount::withoutGlobalScope('company')->where('company_id', $company->id)->where('bank_code', 'VCB')->first();
        $whHh = Warehouse::withoutGlobalScope('company')->where('company_id', $company->id)->where('code', 'KHO_HH')->first();
        $iLaptop = Item::withoutGlobalScope('company')->where('company_id', $company->id)->where('code', 'HH001')->first();
        $iManHinh = Item::withoutGlobalScope('company')->where('company_id', $company->id)->where('code', 'HH002')->first();

        // 7.1 Opening Balance Entry
        $this->recordJournalEntry(
            $company, $fiscalYear, 'general', 'SDDK-2026', '2026-01-01',
            'Số dư đầu kỳ năm 2026',
            [
                ['account' => '1111', 'debit' => 200000000, 'credit' => 0, 'desc' => 'Tiền mặt tại quỹ đầu kỳ'],
                ['account' => '1121', 'debit' => 620000000, 'credit' => 0, 'desc' => 'Tiền gửi Vietcombank đầu kỳ'],
                ['account' => '1561', 'debit' => 50000000, 'credit' => 0, 'desc' => 'Hàng tồn kho đầu kỳ'],
                ['account' => '2111', 'debit' => 1200000000, 'credit' => 0, 'desc' => 'Phương tiện vận tải (Xe Camry)'],
                ['account' => '2112', 'debit' => 180000000, 'credit' => 0, 'desc' => 'Thiết bị quản lý (Server Dell)'],
                ['account' => '2141', 'debit' => 0, 'credit' => 250000000, 'desc' => 'Hao mòn lũy kế TSCĐ'],
                ['account' => '4111', 'debit' => 0, 'credit' => 2000000000, 'desc' => 'Vốn đầu tư của chủ sở hữu'],
            ]
        );

        // 7.2 Fixed Assets (Tài sản cố định)
        FixedAsset::withoutGlobalScope('company')->updateOrCreate(
            ['company_id' => $company->id, 'asset_code' => 'TS00001'],
            [
                'company_id' => $company->id,
                'voucher_number' => 'GT00001',
                'voucher_date' => '2024-01-15',
                'asset_code' => 'TS00001',
                'asset_name' => 'Xe ô tô Toyota Camry 2.5Q',
                'category_code' => 'PT_VAN_TAI',
                'department_code' => 'BGĐ',
                'quantity' => 1,
                'purchase_date' => '2024-01-15',
                'start_depreciation_date' => '2024-02-01',
                'original_cost' => 1200000000,
                'depreciable_cost' => 1200000000,
                'useful_life_months' => 120,
                'monthly_depreciation' => 10000000,
                'accumulated_depreciation' => 200000000,
                'net_value' => 1000000000,
                'asset_account' => '2111',
                'depreciation_account' => '2141',
                'expense_account' => '6424',
                'credit_account' => '1121',
                'is_active' => true,
                'status' => 'in_use',
                'is_posted' => true,
            ]
        );

        FixedAsset::withoutGlobalScope('company')->updateOrCreate(
            ['company_id' => $company->id, 'asset_code' => 'TS00002'],
            [
                'company_id' => $company->id,
                'voucher_number' => 'GT00002',
                'voucher_date' => '2024-06-20',
                'asset_code' => 'TS00002',
                'asset_name' => 'Hệ thống máy chủ Server Dell PowerEdge R750',
                'category_code' => 'TB_QUAN_LY',
                'department_code' => 'CNTT',
                'quantity' => 1,
                'purchase_date' => '2024-06-20',
                'start_depreciation_date' => '2024-07-01',
                'original_cost' => 180000000,
                'depreciable_cost' => 180000000,
                'useful_life_months' => 60,
                'monthly_depreciation' => 3000000,
                'accumulated_depreciation' => 50000000,
                'net_value' => 130000000,
                'asset_account' => '2112',
                'depreciation_account' => '2141',
                'expense_account' => '6424',
                'credit_account' => '1121',
                'is_active' => true,
                'status' => 'in_use',
                'is_posted' => true,
            ]
        );

        // 7.3 Purchase Invoice (Mua hàng)
        if ($sFpt && $iLaptop && $iManHinh) {
            $pi1 = PurchaseInvoice::withoutGlobalScope('company')->updateOrCreate(
                ['company_id' => $company->id, 'invoice_number' => 'HDMH-00001'],
                [
                    'company_id' => $company->id,
                    'supplier_id' => $sFpt->id,
                    'supplier_name' => $sFpt->name,
                    'supplier_address' => $sFpt->address,
                    'invoice_number' => 'HDMH-00001',
                    'invoice_date' => '2026-08-08',
                    'accounting_date' => '2026-08-08',
                    'due_date' => '2026-09-08',
                    'sub_total' => 100000000,
                    'tax_amount' => 10000000,
                    'total_amount' => 110000000,
                    'total_stock_value' => 100000000,
                    'voucher_type' => 'purchase_invoice',
                    'description' => 'Mua máy tính Laptop Dell và màn hình từ NCC FPT',
                    'currency' => 'VND',
                    'exchange_rate' => 1,
                    'status' => 'posted',
                    'is_posted' => true,
                    'payment_status' => 'partial',
                ]
            );
            $pi1->lines()->delete();
            $pi1->lines()->create([
                'item_id' => $iLaptop->id,
                'description' => 'Laptop Dell Latitude 5540 i7',
                'debit_account' => '1561',
                'credit_account' => '331',
                'quantity' => 4,
                'unit_price' => 20000000,
                'amount' => 80000000,
                'tax_rate' => 10,
                'tax_amount' => 8000000,
                'tax_account' => '1331',
                'stock_value' => 80000000,
                'unit' => 'Chiếc',
                'warehouse_id' => $whHh?->id,
                'warehouse_code' => 'KHO_HH',
            ]);
            $pi1->lines()->create([
                'item_id' => $iManHinh->id,
                'description' => 'Màn hình Dell UltraSharp U2422H',
                'debit_account' => '1561',
                'credit_account' => '331',
                'quantity' => 3,
                'unit_price' => 5500000,
                'amount' => 16500000,
                'tax_rate' => 10,
                'tax_amount' => 1650000,
                'tax_account' => '1331',
                'stock_value' => 16500000,
                'unit' => 'Chiếc',
                'warehouse_id' => $whHh?->id,
                'warehouse_code' => 'KHO_HH',
            ]);
            $pi1Je = $this->recordJournalEntry(
                $company, $fiscalYear, 'purchase_invoice', 'HDMH-00001', '2026-08-08',
                'Mua hàng hóa từ NCC FPT',
                [
                    ['account' => '1561', 'debit' => 100000000, 'credit' => 0, 'contact_type' => 'supplier', 'contact_id' => $sFpt->id, 'contact_name' => $sFpt->name],
                    ['account' => '1331', 'debit' => 10000000, 'credit' => 0],
                    ['account' => '331', 'debit' => 0, 'credit' => 110000000, 'contact_type' => 'supplier', 'contact_id' => $sFpt->id, 'contact_name' => $sFpt->name],
                ],
                'purchase_invoice', $pi1->id
            );
            $pi1->update(['journal_entry_id' => $pi1Je->id]);
        }

        // 7.4 Sales Invoice (Bán hàng)
        if ($cHoangPhat && $iLaptop) {
            $si1 = SalesInvoice::withoutGlobalScope('company')->updateOrCreate(
                ['company_id' => $company->id, 'invoice_number' => 'HDBH-00001'],
                [
                    'company_id' => $company->id,
                    'customer_id' => $cHoangPhat->id,
                    'customer_name' => $cHoangPhat->name,
                    'customer_address' => $cHoangPhat->address,
                    'invoice_number' => 'HDBH-00001',
                    'invoice_date' => '2026-08-11',
                    'accounting_date' => '2026-08-11',
                    'due_date' => '2026-09-11',
                    'sub_total' => 60000000,
                    'tax_amount' => 6000000,
                    'total_amount' => 66000000,
                    'voucher_type' => 'sales_invoice',
                    'description' => 'Xuất bán Laptop Dell cho Công ty Hoàng Phát',
                    'currency' => 'VND',
                    'exchange_rate' => 1,
                    'status' => 'posted',
                    'is_posted' => true,
                    'payment_status' => 'partial',
                ]
            );
            $si1->lines()->delete();
            $si1->lines()->create([
                'item_id' => $iLaptop->id,
                'description' => 'Laptop Dell Latitude 5540 i7',
                'debit_account' => '131',
                'credit_account' => '5111',
                'quantity' => 2,
                'unit_price' => 25000000,
                'amount' => 50000000,
                'tax_rate' => 10,
                'tax_amount' => 5000000,
                'tax_account' => '3331',
                'cogs_debit_account' => '632',
                'cogs_credit_account' => '1561',
                'cogs_unit_price' => 20000000,
                'cogs_amount' => 40000000,
                'unit' => 'Chiếc',
                'warehouse_id' => $whHh?->id,
                'warehouse_code' => 'KHO_HH',
            ]);
            $si1Je = $this->recordJournalEntry(
                $company, $fiscalYear, 'sales_invoice', 'HDBH-00001', '2026-08-11',
                'Xuất bán Laptop Dell cho Công ty Hoàng Phát',
                [
                    ['account' => '131', 'debit' => 66000000, 'credit' => 0, 'contact_type' => 'customer', 'contact_id' => $cHoangPhat->id, 'contact_name' => $cHoangPhat->name],
                    ['account' => '5111', 'debit' => 0, 'credit' => 60000000],
                    ['account' => '3331', 'debit' => 0, 'credit' => 6000000],
                    ['account' => '632', 'debit' => 40000000, 'credit' => 0],
                    ['account' => '1561', 'debit' => 0, 'credit' => 40000000],
                ],
                'sales_invoice', $si1->id
            );
            $si1->update(['journal_entry_id' => $si1Je->id]);
        }

        // 7.5 Cash Receipts (Phiếu thu)
        if ($cHoangPhat) {
            $cr1 = CashReceipt::withoutGlobalScope('company')->withTrashed()->updateOrCreate(
                ['company_id' => $company->id, 'voucher_number' => 'PT00001'],
                [
                    'company_id' => $company->id,
                    'voucher_type' => 'thu_tien_mat',
                    'contact_type' => 'customer',
                    'contact_id' => $cHoangPhat->id,
                    'contact_name' => $cHoangPhat->name,
                    'voucher_number' => 'PT00001',
                    'voucher_date' => '2026-08-12',
                    'posting_date' => '2026-08-12',
                    'payer_name' => 'Nguyễn Thị Thu',
                    'payer_address' => $cHoangPhat->address,
                    'reason' => 'Thu tiền bán hàng trực tiếp của Công ty Hoàng Phát',
                    'currency' => 'VND',
                    'exchange_rate' => 1,
                    'total_amount' => 25000000,
                    'status' => 'posted',
                    'is_posted' => true,
                    'deleted_at' => null,
                ]
            );
            $cr1->lines()->delete();
            $cr1->lines()->create([
                'debit_account' => '1111',
                'credit_account' => '131',
                'description' => 'Thu tiền bán hàng trực tiếp',
                'amount' => 25000000,
                'line_contact_id' => $cHoangPhat->id,
                'line_contact_name' => $cHoangPhat->name,
            ]);
            $cr1Je = $this->recordJournalEntry(
                $company, $fiscalYear, 'cash_receipt', 'PT00001', '2026-08-12',
                'Thu tiền bán hàng trực tiếp của Công ty Hoàng Phát',
                [
                    ['account' => '1111', 'debit' => 25000000, 'credit' => 0],
                    ['account' => '131', 'debit' => 0, 'credit' => 25000000, 'contact_type' => 'customer', 'contact_id' => $cHoangPhat->id, 'contact_name' => $cHoangPhat->name],
                ],
                'cash_receipt', $cr1->id
            );
            $cr1->update(['journal_entry_id' => $cr1Je->id]);

            // PT00002
            $cr2 = CashReceipt::withoutGlobalScope('company')->withTrashed()->updateOrCreate(
                ['company_id' => $company->id, 'voucher_number' => 'PT00002'],
                [
                    'company_id' => $company->id,
                    'voucher_type' => 'thu_tien_mat',
                    'contact_type' => 'other',
                    'voucher_number' => 'PT00002',
                    'voucher_date' => '2026-08-15',
                    'posting_date' => '2026-08-15',
                    'payer_name' => 'Nguyễn Văn An',
                    'payer_address' => 'Hà Nội',
                    'reason' => 'Rút tiền gửi Vietcombank về nhập quỹ tiền mặt',
                    'currency' => 'VND',
                    'exchange_rate' => 1,
                    'total_amount' => 50000000,
                    'status' => 'posted',
                    'is_posted' => true,
                    'deleted_at' => null,
                ]
            );
            $cr2->lines()->delete();
            $cr2->lines()->create([
                'debit_account' => '1111',
                'credit_account' => '1121',
                'description' => 'Rút tiền gửi ngân hàng về nhập quỹ',
                'amount' => 50000000,
            ]);
            $cr2Je = $this->recordJournalEntry(
                $company, $fiscalYear, 'cash_receipt', 'PT00002', '2026-08-15',
                'Rút tiền gửi Vietcombank về nhập quỹ tiền mặt',
                [
                    ['account' => '1111', 'debit' => 50000000, 'credit' => 0],
                    ['account' => '1121', 'debit' => 0, 'credit' => 50000000],
                ],
                'cash_receipt', $cr2->id
            );
            $cr2->update(['journal_entry_id' => $cr2Je->id]);
        }

        // 7.6 Cash Payments (Phiếu chi)
        if ($sPhucAnh) {
            $cp1 = CashPayment::withoutGlobalScope('company')->withTrashed()->updateOrCreate(
                ['company_id' => $company->id, 'voucher_number' => 'PC00001'],
                [
                    'company_id' => $company->id,
                    'voucher_type' => 'chi_tien_mat',
                    'contact_type' => 'supplier',
                    'contact_id' => $sPhucAnh->id,
                    'contact_name' => $sPhucAnh->name,
                    'voucher_number' => 'PC00001',
                    'voucher_date' => '2026-08-13',
                    'posting_date' => '2026-08-13',
                    'receiver_name' => 'Trần Văn Phúc',
                    'receiver_address' => $sPhucAnh->address,
                    'reason' => 'Chi tiền mua văn phòng phẩm từ NCC Phúc Anh',
                    'currency' => 'VND',
                    'exchange_rate' => 1,
                    'total_amount' => 2200000,
                    'status' => 'posted',
                    'is_posted' => true,
                    'deleted_at' => null,
                ]
            );
            $cp1->lines()->delete();
            $cp1->lines()->create([
                'debit_account' => '6422',
                'credit_account' => '1111',
                'description' => 'Chi phí văn phòng phẩm',
                'amount' => 2000000,
            ]);
            $cp1->lines()->create([
                'debit_account' => '1331',
                'credit_account' => '1111',
                'description' => 'Thuế GTGT mua văn phòng phẩm',
                'amount' => 200000,
            ]);
            $cp1Je = $this->recordJournalEntry(
                $company, $fiscalYear, 'cash_payment', 'PC00001', '2026-08-13',
                'Chi tiền mua văn phòng phẩm từ NCC Phúc Anh',
                [
                    ['account' => '6422', 'debit' => 2000000, 'credit' => 0],
                    ['account' => '1331', 'debit' => 200000, 'credit' => 0],
                    ['account' => '1111', 'debit' => 0, 'credit' => 2200000],
                ],
                'cash_payment', $cp1->id
            );
            $cp1->update(['journal_entry_id' => $cp1Je->id]);

            // PC00002
            $cp2 = CashPayment::withoutGlobalScope('company')->withTrashed()->updateOrCreate(
                ['company_id' => $company->id, 'voucher_number' => 'PC00002'],
                [
                    'company_id' => $company->id,
                    'voucher_type' => 'chi_tien_mat',
                    'contact_type' => 'other',
                    'voucher_number' => 'PC00002',
                    'voucher_date' => '2026-08-20',
                    'posting_date' => '2026-08-20',
                    'receiver_name' => 'Điện lực Hà Nội',
                    'receiver_address' => 'Hà Nội',
                    'reason' => 'Chi tiền điện chiếu sáng và sinh hoạt văn phòng',
                    'currency' => 'VND',
                    'exchange_rate' => 1,
                    'total_amount' => 3300000,
                    'status' => 'posted',
                    'is_posted' => true,
                    'deleted_at' => null,
                ]
            );
            $cp2->lines()->delete();
            $cp2->lines()->create([
                'debit_account' => '6427',
                'credit_account' => '1111',
                'description' => 'Chi phí điện chiếu sáng',
                'amount' => 3000000,
            ]);
            $cp2->lines()->create([
                'debit_account' => '1331',
                'credit_account' => '1111',
                'description' => 'Thuế GTGT tiền điện',
                'amount' => 300000,
            ]);
            $cp2Je = $this->recordJournalEntry(
                $company, $fiscalYear, 'cash_payment', 'PC00002', '2026-08-20',
                'Chi tiền điện chiếu sáng và sinh hoạt văn phòng',
                [
                    ['account' => '6427', 'debit' => 3000000, 'credit' => 0],
                    ['account' => '1331', 'debit' => 300000, 'credit' => 0],
                    ['account' => '1111', 'debit' => 0, 'credit' => 3300000],
                ],
                'cash_payment', $cp2->id
            );
            $cp2->update(['journal_entry_id' => $cp2Je->id]);
        }

        // 7.7 Bank Receipts (Báo Có tiền gửi)
        if ($cMinhAn && $bVcb) {
            $br1 = BankReceipt::withoutGlobalScope('company')->updateOrCreate(
                ['company_id' => $company->id, 'voucher_number' => 'BC00001'],
                [
                    'company_id' => $company->id,
                    'voucher_type' => 'thu_tien_gui',
                    'contact_type' => 'customer',
                    'contact_id' => $cMinhAn->id,
                    'contact_name' => $cMinhAn->name,
                    'bank_account_id' => $bVcb->id,
                    'voucher_number' => 'BC00001',
                    'voucher_date' => '2026-08-16',
                    'posting_date' => '2026-08-16',
                    'payer_name' => $cMinhAn->name,
                    'payer_address' => $cMinhAn->address,
                    'description' => 'Khách hàng Minh An thanh toán tiền hàng qua Vietcombank',
                    'currency' => 'VND',
                    'exchange_rate' => 1,
                    'amount' => 88000000,
                    'status' => 'posted',
                    'is_posted' => true,
                ]
            );
            $br1->lines()->delete();
            $br1->lines()->create([
                'debit_account' => '1121',
                'credit_account' => '131',
                'description' => 'Thu tiền khách hàng qua ngân hàng',
                'amount' => 88000000,
                'bank_account_id' => $bVcb->id,
                'line_contact_id' => $cMinhAn->id,
                'line_contact_name' => $cMinhAn->name,
            ]);
            $br1Je = $this->recordJournalEntry(
                $company, $fiscalYear, 'bank_receipt', 'BC00001', '2026-08-16',
                'Khách hàng Minh An thanh toán tiền hàng qua Vietcombank',
                [
                    ['account' => '1121', 'debit' => 88000000, 'credit' => 0, 'bank_account_id' => $bVcb->id],
                    ['account' => '131', 'debit' => 0, 'credit' => 88000000, 'contact_type' => 'customer', 'contact_id' => $cMinhAn->id, 'contact_name' => $cMinhAn->name],
                ],
                'bank_receipt', $br1->id
            );
            $br1->update(['journal_entry_id' => $br1Je->id]);
        }

        // 7.8 Bank Payments (Ủy nhiệm chi / Báo Nợ)
        if ($sFpt && $bVcb) {
            $bp1 = BankPayment::withoutGlobalScope('company')->updateOrCreate(
                ['company_id' => $company->id, 'voucher_number' => 'BN00001'],
                [
                    'company_id' => $company->id,
                    'voucher_type' => 'chi_tien_gui',
                    'contact_type' => 'supplier',
                    'contact_id' => $sFpt->id,
                    'contact_name' => $sFpt->name,
                    'bank_account_id' => $bVcb->id,
                    'voucher_number' => 'BN00001',
                    'voucher_date' => '2026-08-19',
                    'posting_date' => '2026-08-19',
                    'payee_name' => $sFpt->name,
                    'payee_address' => $sFpt->address,
                    'description' => 'Chuyển khoản Vietcombank thanh toán tiền mua hàng cho NCC FPT',
                    'currency' => 'VND',
                    'exchange_rate' => 1,
                    'amount' => 65000000,
                    'status' => 'posted',
                    'is_posted' => true,
                ]
            );
            $bp1->lines()->delete();
            $bp1->lines()->create([
                'debit_account' => '331',
                'credit_account' => '1121',
                'description' => 'Thanh toán tiền hàng cho nhà cung cấp',
                'amount' => 65000000,
                'bank_account_id' => $bVcb->id,
                'line_contact_id' => $sFpt->id,
                'line_contact_name' => $sFpt->name,
            ]);
            $bp1Je = $this->recordJournalEntry(
                $company, $fiscalYear, 'bank_payment', 'BN00001', '2026-08-19',
                'Chuyển khoản Vietcombank thanh toán tiền mua hàng cho NCC FPT',
                [
                    ['account' => '331', 'debit' => 65000000, 'credit' => 0, 'contact_type' => 'supplier', 'contact_id' => $sFpt->id, 'contact_name' => $sFpt->name],
                    ['account' => '1121', 'debit' => 0, 'credit' => 65000000, 'bank_account_id' => $bVcb->id],
                ],
                'bank_payment', $bp1->id
            );
            $bp1->update(['journal_entry_id' => $bp1Je->id]);
        }

        // 7.9 Inventory Receipts (Phiếu nhập kho)
        if ($sFpt && $iLaptop && $iManHinh) {
            $ir1 = InventoryReceipt::withoutGlobalScope('company')->updateOrCreate(
                ['company_id' => $company->id, 'voucher_number' => 'PNK00001'],
                [
                    'company_id' => $company->id,
                    'voucher_type' => 'nhap_kho_mua_hang',
                    'contact_type' => 'supplier',
                    'contact_id' => $sFpt->id,
                    'contact_name' => $sFpt->name,
                    'warehouse_id' => $whHh?->id,
                    'voucher_number' => 'PNK00001',
                    'voucher_date' => '2026-08-08',
                    'posting_date' => '2026-08-08',
                    'description' => 'Nhập kho mua hàng từ NCC FPT',
                    'currency' => 'VND',
                    'exchange_rate' => 1,
                    'total_amount' => 96500000,
                    'status' => 'posted',
                    'is_posted' => true,
                ]
            );
            $ir1->lines()->delete();
            $ir1->lines()->create([
                'item_id' => $iLaptop->id,
                'warehouse_id' => $whHh?->id,
                'warehouse_code' => 'KHO_HH',
                'unit' => 'Chiếc',
                'description' => 'Laptop Dell Latitude 5540 i7',
                'quantity' => 4,
                'unit_price' => 20000000,
                'amount' => 80000000,
                'debit_account' => '1561',
                'credit_account' => '331',
            ]);
            $ir1->lines()->create([
                'item_id' => $iManHinh->id,
                'warehouse_id' => $whHh?->id,
                'warehouse_code' => 'KHO_HH',
                'unit' => 'Chiếc',
                'description' => 'Màn hình Dell UltraSharp U2422H',
                'quantity' => 3,
                'unit_price' => 5500000,
                'amount' => 16500000,
                'debit_account' => '1561',
                'credit_account' => '331',
            ]);
        }

        // 7.10 Inventory Issues (Phiếu xuất kho)
        if ($cHoangPhat && $iLaptop) {
            $ii1 = InventoryIssue::withoutGlobalScope('company')->updateOrCreate(
                ['company_id' => $company->id, 'voucher_number' => 'PXK00001'],
                [
                    'company_id' => $company->id,
                    'voucher_type' => 'xuat_kho_ban_hang',
                    'contact_type' => 'customer',
                    'contact_id' => $cHoangPhat->id,
                    'contact_name' => $cHoangPhat->name,
                    'warehouse_id' => $whHh?->id,
                    'voucher_number' => 'PXK00001',
                    'voucher_date' => '2026-08-11',
                    'posting_date' => '2026-08-11',
                    'description' => 'Xuất kho bán hàng cho Công ty Hoàng Phát',
                    'currency' => 'VND',
                    'exchange_rate' => 1,
                    'total_amount' => 40000000,
                    'status' => 'posted',
                    'is_posted' => true,
                ]
            );
            $ii1->lines()->delete();
            $ii1->lines()->create([
                'item_id' => $iLaptop->id,
                'warehouse_id' => $whHh?->id,
                'warehouse_code' => 'KHO_HH',
                'unit' => 'Chiếc',
                'description' => 'Laptop Dell Latitude 5540 i7',
                'quantity' => 2,
                'unit_price' => 20000000,
                'amount' => 40000000,
                'debit_account' => '632',
                'credit_account' => '1561',
            ]);
        }
    }

    private function recordJournalEntry($company, $fiscalYear, $type, $voucherNumber, $date, $desc, $lines, $sourceType = null, $sourceId = null): JournalEntry
    {
        $existing = JournalEntry::withoutGlobalScope('company')
            ->withTrashed()
            ->where('company_id', $company->id)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('voucher_number', $voucherNumber)
            ->first();
        if ($existing) {
            $existing->lines()->delete();
            $existing->forceDelete();
        }

        $total = 0;
        foreach ($lines as $l) {
            $total += $l['debit'] ?? 0;
        }

        $je = JournalEntry::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'fiscal_year_id' => $fiscalYear->id,
            'voucher_type' => $type,
            'voucher_number' => $voucherNumber,
            'voucher_date' => $date,
            'posting_date' => $date,
            'description' => $desc,
            'total_amount' => $total,
            'status' => 'posted',
            'currency' => 'VND',
            'exchange_rate' => 1,
            'source_document_type' => $sourceType,
            'source_document_id' => $sourceId,
            'created_at' => $date,
            'updated_at' => $date,
        ]);

        foreach ($lines as $l) {
            $je->lines()->create([
                'account_code' => $l['account'],
                'description' => $l['desc'] ?? $desc,
                'debit_amount' => $l['debit'] ?? 0,
                'credit_amount' => $l['credit'] ?? 0,
                'contact_type' => $l['contact_type'] ?? null,
                'contact_id' => $l['contact_id'] ?? null,
                'contact_name' => $l['contact_name'] ?? null,
                'bank_account_id' => $l['bank_account_id'] ?? null,
            ]);
        }

        return $je;
    }
}
