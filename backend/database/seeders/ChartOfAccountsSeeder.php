<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ChartOfAccount;
use App\Models\Company;

class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $companies = Company::all();
        
        if ($companies->isEmpty()) {
            $companies = collect([Company::create([
                'name' => 'Doanh nghiệp của tôi',
                'tax_code' => null,
                'address' => null,
                'email' => null,
                'phone' => null,
            ])]);
        }

        $accounts = [
            // LOẠI 1: TÀI SẢN NGẮN HẠN
            ['code' => '111', 'name' => 'Tiền mặt', 'name_en' => 'Cash on hand', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => true],
            ['code' => '1111', 'name' => 'Tiền Việt Nam', 'parent_code' => '111', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1112', 'name' => 'Ngoại tệ', 'parent_code' => '111', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1113', 'name' => 'Vàng tiền tệ', 'parent_code' => '111', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            
            ['code' => '112', 'name' => 'Tiền gửi ngân hàng', 'name_en' => 'Cash in bank', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => true],
            ['code' => '1121', 'name' => 'Tiền Việt Nam', 'parent_code' => '112', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1122', 'name' => 'Ngoại tệ', 'parent_code' => '112', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            
            ['code' => '113', 'name' => 'Tiền đang chuyển', 'name_en' => 'Cash in transit', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            
            ['code' => '121', 'name' => 'Chứng khoán kinh doanh', 'name_en' => 'Trading securities', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '128', 'name' => 'Đầu tư nắm giữ đến ngày đáo hạn', 'name_en' => 'Held-to-maturity investments', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => true],
            ['code' => '1281', 'name' => 'Tiền gửi có kỳ hạn', 'parent_code' => '128', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1282', 'name' => 'Trái phiếu', 'parent_code' => '128', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1288', 'name' => 'Các khoản đầu tư khác', 'parent_code' => '128', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],

            ['code' => '131', 'name' => 'Phải thu của khách hàng', 'name_en' => 'Trade receivables', 'type' => 'asset', 'nature' => 'amphibious', 'level' => 1, 'is_parent' => false],
            ['code' => '133', 'name' => 'Thuế GTGT được khấu trừ', 'name_en' => 'Deductible VAT', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => true],
            ['code' => '1331', 'name' => 'Thuế GTGT được khấu trừ của HHDV', 'parent_code' => '133', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1332', 'name' => 'Thuế GTGT được khấu trừ của TSCĐ', 'parent_code' => '133', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            
            ['code' => '136', 'name' => 'Phải thu nội bộ', 'name_en' => 'Internal receivables', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '138', 'name' => 'Phải thu khác', 'name_en' => 'Other receivables', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => true],
            ['code' => '1381', 'name' => 'Tài sản thiếu chờ xử lý', 'parent_code' => '138', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1388', 'name' => 'Phải thu khác', 'parent_code' => '138', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            
            ['code' => '141', 'name' => 'Tạm ứng', 'name_en' => 'Advances', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            
            ['code' => '151', 'name' => 'Hàng mua đang đi đường', 'name_en' => 'Goods in transit', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '152', 'name' => 'Nguyên liệu, vật liệu', 'name_en' => 'Raw materials', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '153', 'name' => 'Công cụ, dụng cụ', 'name_en' => 'Tools and supplies', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '154', 'name' => 'Chi phí SXKD dở dang', 'name_en' => 'Work in progress', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '155', 'name' => 'Thành phẩm', 'name_en' => 'Finished goods', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '156', 'name' => 'Hàng hóa', 'name_en' => 'Merchandise', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => true],
            ['code' => '1561', 'name' => 'Giá mua hàng hóa', 'parent_code' => '156', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1562', 'name' => 'Chi phí thu mua hàng hóa', 'parent_code' => '156', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '157', 'name' => 'Hàng gửi đi bán', 'name_en' => 'Goods sent on consignment', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],

            // LOẠI 2: TÀI SẢN DÀI HẠN
            ['code' => '211', 'name' => 'Tài sản cố định hữu hình', 'name_en' => 'Tangible fixed assets', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => true],
            ['code' => '2111', 'name' => 'Nhà cửa, vật kiến trúc', 'parent_code' => '211', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '2112', 'name' => 'Máy móc, thiết bị', 'parent_code' => '211', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '2113', 'name' => 'Phương tiện vận tải, truyền dẫn', 'parent_code' => '211', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '213', 'name' => 'Tài sản cố định vô hình', 'name_en' => 'Intangible fixed assets', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '214', 'name' => 'Hao mòn TSCĐ', 'name_en' => 'Depreciation', 'type' => 'asset', 'nature' => 'credit', 'level' => 1, 'is_parent' => true],
            ['code' => '2141', 'name' => 'Hao mòn TSCĐ hữu hình', 'parent_code' => '214', 'type' => 'asset', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '241', 'name' => 'Xây dựng cơ bản dở dang', 'name_en' => 'Construction in progress', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '242', 'name' => 'Chi phí trả trước', 'name_en' => 'Prepaid expenses', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],

            // LOẠI 3: NỢ PHẢI TRẢ
            ['code' => '331', 'name' => 'Phải trả cho người bán', 'name_en' => 'Trade payables', 'type' => 'liability', 'nature' => 'amphibious', 'level' => 1, 'is_parent' => false],
            ['code' => '333', 'name' => 'Thuế và các khoản phải nộp Nhà nước', 'name_en' => 'Taxes and payables', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => true],
            ['code' => '3331', 'name' => 'Thuế GTGT phải nộp', 'parent_code' => '333', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => true],
            ['code' => '33311', 'name' => 'Thuế GTGT đầu ra', 'parent_code' => '3331', 'type' => 'liability', 'nature' => 'credit', 'level' => 3, 'is_parent' => false],
            ['code' => '33312', 'name' => 'Thuế GTGT hàng nhập khẩu', 'parent_code' => '3331', 'type' => 'liability', 'nature' => 'credit', 'level' => 3, 'is_parent' => false],
            ['code' => '3334', 'name' => 'Thuế thu nhập doanh nghiệp', 'parent_code' => '333', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '3335', 'name' => 'Thuế thu nhập cá nhân', 'parent_code' => '333', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '334', 'name' => 'Phải trả người lao động', 'name_en' => 'Payables to employees', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => false],
            ['code' => '335', 'name' => 'Chi phí phải trả', 'name_en' => 'Accrued expenses', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => false],
            ['code' => '338', 'name' => 'Phải trả, phải nộp khác', 'name_en' => 'Other payables', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => true],
            ['code' => '3382', 'name' => 'Kinh phí công đoàn', 'parent_code' => '338', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '3383', 'name' => 'Bảo hiểm xã hội', 'parent_code' => '338', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '3384', 'name' => 'Bảo hiểm y tế', 'parent_code' => '338', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '3386', 'name' => 'Bảo hiểm thất nghiệp', 'parent_code' => '338', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '3388', 'name' => 'Phải trả, phải nộp khác', 'parent_code' => '338', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '341', 'name' => 'Vay và nợ thuê tài chính', 'name_en' => 'Borrowings and finance lease liabilities', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => false],

            // LOẠI 4: VỐN CHỦ SỞ HỮU
            ['code' => '411', 'name' => 'Vốn đầu tư của chủ sở hữu', 'name_en' => 'Owner\'s equity', 'type' => 'equity', 'nature' => 'credit', 'level' => 1, 'is_parent' => true],
            ['code' => '4111', 'name' => 'Vốn góp của chủ sở hữu', 'parent_code' => '411', 'type' => 'equity', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '421', 'name' => 'Lợi nhuận sau thuế chưa phân phối', 'name_en' => 'Retained earnings', 'type' => 'equity', 'nature' => 'amphibious', 'level' => 1, 'is_parent' => true],
            ['code' => '4211', 'name' => 'Lợi nhuận sau thuế chưa PP năm trước', 'parent_code' => '421', 'type' => 'equity', 'nature' => 'amphibious', 'level' => 2, 'is_parent' => false],
            ['code' => '4212', 'name' => 'Lợi nhuận sau thuế chưa PP năm nay', 'parent_code' => '421', 'type' => 'equity', 'nature' => 'amphibious', 'level' => 2, 'is_parent' => false],

            // LOẠI 5: DOANH THU
            ['code' => '511', 'name' => 'Doanh thu bán hàng và cung cấp dịch vụ', 'name_en' => 'Revenue', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => true],
            ['code' => '5111', 'name' => 'Doanh thu bán hàng hóa', 'parent_code' => '511', 'type' => 'revenue', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '5112', 'name' => 'Doanh thu bán các thành phẩm', 'parent_code' => '511', 'type' => 'revenue', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '5113', 'name' => 'Doanh thu cung cấp dịch vụ', 'parent_code' => '511', 'type' => 'revenue', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '515', 'name' => 'Doanh thu hoạt động tài chính', 'name_en' => 'Financial income', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => false],
            ['code' => '521', 'name' => 'Các khoản giảm trừ doanh thu', 'name_en' => 'Revenue deductions', 'type' => 'revenue', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],

            // LOẠI 6: CHI PHÍ SXKD
            ['code' => '632', 'name' => 'Giá vốn hàng bán', 'name_en' => 'Cost of goods sold', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '635', 'name' => 'Chi phí tài chính', 'name_en' => 'Financial expenses', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '641', 'name' => 'Chi phí bán hàng', 'name_en' => 'Selling expenses', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '642', 'name' => 'Chi phí quản lý doanh nghiệp', 'name_en' => 'General and admin expenses', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => true],
            ['code' => '6421', 'name' => 'Chi phí nhân viên quản lý', 'parent_code' => '642', 'type' => 'expense', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '6422', 'name' => 'Chi phí vật liệu quản lý', 'parent_code' => '642', 'type' => 'expense', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '6423', 'name' => 'Chi phí đồ dùng văn phòng', 'parent_code' => '642', 'type' => 'expense', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '6427', 'name' => 'Chi phí dịch vụ mua ngoài', 'parent_code' => '642', 'type' => 'expense', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '6428', 'name' => 'Chi phí bằng tiền khác', 'parent_code' => '642', 'type' => 'expense', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],

            // LOẠI 7 & 8: THU NHẬP & CHI PHÍ KHÁC
            ['code' => '711', 'name' => 'Thu nhập khác', 'name_en' => 'Other income', 'type' => 'revenue', 'nature' => 'credit', 'level' => 1, 'is_parent' => false],
            ['code' => '811', 'name' => 'Chi phí khác', 'name_en' => 'Other expenses', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],
            ['code' => '821', 'name' => 'Chi phí thuế thu nhập doanh nghiệp', 'name_en' => 'Corporate income tax expense', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => false],

            // LOẠI 9: XÁC ĐỊNH KẾT QUẢ KINH DOANH
            ['code' => '911', 'name' => 'Xác định kết quả kinh doanh', 'name_en' => 'Income summary', 'type' => 'equity', 'nature' => 'amphibious', 'level' => 1, 'is_parent' => false],
        ];

        // Bổ sung đủ danh mục 137 tài khoản chi tiết theo hệ thống tài khoản
        // doanh nghiệp Việt Nam; không dùng mã ACC_* hay dữ liệu mô phỏng.
        $accounts = array_merge($accounts, [
            ['code' => '1211', 'name' => 'Cổ phiếu', 'parent_code' => '121', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1212', 'name' => 'Trái phiếu', 'parent_code' => '121', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1283', 'name' => 'Cho vay', 'parent_code' => '128', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1311', 'name' => 'Phải thu khách hàng trong nước', 'parent_code' => '131', 'type' => 'asset', 'nature' => 'amphibious', 'level' => 2, 'is_parent' => false],
            ['code' => '1312', 'name' => 'Phải thu khách hàng nước ngoài', 'parent_code' => '131', 'type' => 'asset', 'nature' => 'amphibious', 'level' => 2, 'is_parent' => false],
            ['code' => '1313', 'name' => 'Phải thu theo tiến độ kế hoạch hợp đồng xây dựng', 'parent_code' => '131', 'type' => 'asset', 'nature' => 'amphibious', 'level' => 2, 'is_parent' => false],
            ['code' => '1361', 'name' => 'Vốn kinh doanh ở các đơn vị trực thuộc', 'parent_code' => '136', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1362', 'name' => 'Phải thu nội bộ về chênh lệch tỷ giá', 'parent_code' => '136', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1382', 'name' => 'Tài sản thiếu chờ xử lý', 'parent_code' => '138', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1383', 'name' => 'Phải thu cổ phần hóa', 'parent_code' => '138', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1385', 'name' => 'Phải thu về cổ tức và lợi nhuận được chia', 'parent_code' => '138', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1411', 'name' => 'Tạm ứng cho nhân viên', 'parent_code' => '141', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1541', 'name' => 'Chi phí nguyên liệu, vật liệu trực tiếp', 'parent_code' => '154', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1542', 'name' => 'Chi phí nhân công trực tiếp', 'parent_code' => '154', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1543', 'name' => 'Chi phí sản xuất chung', 'parent_code' => '154', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1568', 'name' => 'Hàng hóa bất động sản', 'parent_code' => '156', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '1571', 'name' => 'Hàng gửi đi bán', 'parent_code' => '157', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '2114', 'name' => 'Thiết bị, dụng cụ quản lý', 'parent_code' => '211', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '2115', 'name' => 'Cây lâu năm, súc vật làm việc và cho sản phẩm', 'parent_code' => '211', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '2118', 'name' => 'TSCĐ hữu hình khác', 'parent_code' => '211', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '2131', 'name' => 'Quyền sử dụng đất', 'parent_code' => '213', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '2132', 'name' => 'Quyền phát hành', 'parent_code' => '213', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '2133', 'name' => 'Bản quyền, bằng sáng chế', 'parent_code' => '213', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '2142', 'name' => 'Hao mòn TSCĐ thuê tài chính', 'parent_code' => '214', 'type' => 'asset', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '2143', 'name' => 'Hao mòn TSCĐ vô hình', 'parent_code' => '214', 'type' => 'asset', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '2147', 'name' => 'Hao mòn bất động sản đầu tư', 'parent_code' => '214', 'type' => 'asset', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '2411', 'name' => 'Mua sắm TSCĐ', 'parent_code' => '241', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '2412', 'name' => 'Xây dựng cơ bản', 'parent_code' => '241', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '2413', 'name' => 'Sửa chữa lớn TSCĐ', 'parent_code' => '241', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '2421', 'name' => 'Chi phí trả trước về thuê cơ sở hạ tầng', 'parent_code' => '242', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '2422', 'name' => 'Chi phí trả trước khác', 'parent_code' => '242', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '244', 'name' => 'Cầm cố, thế chấp, ký quỹ, ký cược', 'type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_parent' => true],
            ['code' => '2441', 'name' => 'Cầm cố, thế chấp, ký quỹ, ký cược', 'parent_code' => '244', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '2442', 'name' => 'Ký quỹ bảo lãnh', 'parent_code' => '244', 'type' => 'asset', 'nature' => 'debit', 'level' => 2, 'is_parent' => false],
            ['code' => '3311', 'name' => 'Phải trả người bán trong nước', 'parent_code' => '331', 'type' => 'liability', 'nature' => 'amphibious', 'level' => 2, 'is_parent' => false],
            ['code' => '3312', 'name' => 'Phải trả người bán nước ngoài', 'parent_code' => '331', 'type' => 'liability', 'nature' => 'amphibious', 'level' => 2, 'is_parent' => false],
            ['code' => '3313', 'name' => 'Phải trả nhà thầu xây dựng', 'parent_code' => '331', 'type' => 'liability', 'nature' => 'amphibious', 'level' => 2, 'is_parent' => false],
            ['code' => '3332', 'name' => 'Thuế tiêu thụ đặc biệt', 'parent_code' => '333', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '3333', 'name' => 'Thuế xuất, nhập khẩu', 'parent_code' => '333', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '3338', 'name' => 'Các loại thuế khác', 'parent_code' => '333', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '3339', 'name' => 'Phí, lệ phí và các khoản phải nộp khác', 'parent_code' => '333', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '336', 'name' => 'Phải trả nội bộ', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => true],
            ['code' => '3361', 'name' => 'Phải trả nội bộ về vốn kinh doanh', 'parent_code' => '336', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '3362', 'name' => 'Phải trả nội bộ về chênh lệch tỷ giá', 'parent_code' => '336', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '3363', 'name' => 'Phải trả nội bộ về chi phí đi vay đủ điều kiện vốn hóa', 'parent_code' => '336', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '3381', 'name' => 'Tài sản thừa chờ giải quyết', 'parent_code' => '338', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '3387', 'name' => 'Doanh thu chưa thực hiện', 'parent_code' => '338', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '3411', 'name' => 'Các khoản đi vay', 'parent_code' => '341', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '3412', 'name' => 'Nợ thuê tài chính', 'parent_code' => '341', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '3413', 'name' => 'Trái phiếu phát hành', 'parent_code' => '341', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '352', 'name' => 'Dự phòng phải trả', 'type' => 'liability', 'nature' => 'credit', 'level' => 1, 'is_parent' => true],
            ['code' => '3521', 'name' => 'Dự phòng bảo hành sản phẩm hàng hóa', 'parent_code' => '352', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '3522', 'name' => 'Dự phòng bảo hành công trình xây dựng', 'parent_code' => '352', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '3523', 'name' => 'Dự phòng tái cơ cấu doanh nghiệp', 'parent_code' => '352', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '3524', 'name' => 'Dự phòng phải trả khác', 'parent_code' => '352', 'type' => 'liability', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
            ['code' => '353', 'name' => 'Quỹ khen thưởng, phúc lợi', 'type' => 'equity', 'nature' => 'credit', 'level' => 1, 'is_parent' => true],
            ['code' => '3531', 'name' => 'Quỹ khen thưởng', 'parent_code' => '353', 'type' => 'equity', 'nature' => 'credit', 'level' => 2, 'is_parent' => false],
        ]);

        $parentCodes = collect($accounts)->pluck('parent_code')->filter()->unique();
        foreach ($companies as $company) {
            foreach ($accounts as $acc) {
                // Existing and soft-deleted accounts remain owner-controlled.
                // Re-running bootstrap must not rewrite historical classifications.
                $account = ChartOfAccount::withTrashed()->firstOrNew([
                    'company_id' => $company->id,
                    'code' => $acc['code'],
                ]);
                if ($account->exists) {
                    continue;
                }
                $account->fill(array_merge($acc, [
                    'company_id' => $company->id,
                    'is_parent' => $parentCodes->contains($acc['code']),
                    'is_active' => true,
                    'name_en' => null,
                    'description' => null,
                ]));
                $account->save();
            }
        }
    }
}
