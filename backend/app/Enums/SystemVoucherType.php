<?php

namespace App\Enums;

use App\Models\BankPayment;
use App\Models\BankReceipt;
use App\Models\BorrowingContract;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\CostAllocation;
use App\Models\FixedAsset;
use App\Models\InventoryIssue;
use App\Models\InventoryReceipt;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\ProductionOrder;
use App\Models\PurchaseContract;
use App\Models\PurchaseDiscount;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\SalesDiscount;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesQuote;
use App\Models\SalesReturn;
use App\Models\ToolEquipment;
use Illuminate\Validation\ValidationException;

enum SystemVoucherType: string
{
    // 1. Tài sản cố định (Fixed Assets)
    case FIXED_ASSET_INCREMENT = 'fixed_asset_increment';
    case FIXED_ASSET_DECREMENT = 'fixed_asset_decrement';
    case FIXED_ASSET_DEPRECIATION = 'fixed_asset_depreciation';

    // 2. Công cụ dụng cụ (Tools & Equipments)
    case TOOL_INCREMENT = 'tool_increment';
    case TOOL_ALLOCATION = 'tool_allocation';
    case TOOL_DECREMENT = 'tool_decrement';

    // 3. Bán hàng (Sales)
    case SALES_QUOTE = 'sales_quote';
    case SALES_ORDER = 'sales_order';
    case SALES_INVOICE = 'sales_invoice';
    case SALES_RETURN = 'sales_return';
    case SALES_DISCOUNT = 'sales_discount';

    // 4. Mua hàng (Purchase)
    case PURCHASE_ORDER = 'purchase_order';
    case PURCHASE_CONTRACT = 'purchase_contract';
    case PURCHASE_INVOICE = 'purchase_invoice';
    case PURCHASE_RETURN = 'purchase_return';
    case PURCHASE_DISCOUNT = 'purchase_discount';

    // 5. Quỹ (Cash)
    case CASH_RECEIPT = 'cash_receipt';
    case CASH_PAYMENT = 'cash_payment';

    // 6. Ngân hàng (Bank)
    case BANK_RECEIPT = 'bank_receipt';
    case BANK_PAYMENT = 'bank_payment';

    // 7. Kho (Inventory)
    case INVENTORY_RECEIPT = 'inventory_receipt';
    case INVENTORY_ISSUE = 'inventory_issue';
    case INVENTORY_TRANSFER = 'inventory_transfer';
    case PRODUCTION_ORDER = 'production_order';

    // 8. Tiền lương (Payroll)
    case PAYROLL = 'payroll';

    // 9. Tổng hợp (General Ledger)
    case JOURNAL_ENTRY = 'journal_entry';
    case PERIOD_CLOSING = 'period_closing';

    // 10. Thuế & Khác / Giá thành / Vay
    case BORROWING_CONTRACT = 'borrowing_contract';
    case COST_ALLOCATION = 'cost_allocation';
    case VAT_DECLARATION = 'vat_declaration';

    /**
     * Tên hiển thị chuẩn tiếng Việt theo MISA AMIS
     */
    public function label(): string
    {
        return match ($this) {
            self::FIXED_ASSET_INCREMENT => 'Ghi tăng TSCĐ',
            self::FIXED_ASSET_DECREMENT => 'Ghi giảm TSCĐ',
            self::FIXED_ASSET_DEPRECIATION => 'Khấu hao TSCĐ',

            self::TOOL_INCREMENT => 'Ghi tăng CCDC',
            self::TOOL_ALLOCATION => 'Phân bổ CCDC',
            self::TOOL_DECREMENT => 'Ghi giảm CCDC',

            self::SALES_QUOTE => 'Báo giá',
            self::SALES_ORDER => 'Đơn đặt hàng',
            self::SALES_INVOICE => 'Hóa đơn bán hàng',
            self::SALES_RETURN => 'Trả lại hàng bán',
            self::SALES_DISCOUNT => 'Giảm giá hàng bán',

            self::PURCHASE_ORDER => 'Đơn mua hàng',
            self::PURCHASE_CONTRACT => 'Hợp đồng mua',
            self::PURCHASE_INVOICE => 'Hóa đơn mua hàng',
            self::PURCHASE_RETURN => 'Trả lại hàng mua',
            self::PURCHASE_DISCOUNT => 'Giảm giá hàng mua',

            self::CASH_RECEIPT => 'Phiếu thu',
            self::CASH_PAYMENT => 'Phiếu chi',

            self::BANK_RECEIPT => 'Thu tiền gửi',
            self::BANK_PAYMENT => 'Ủy nhiệm chi',

            self::INVENTORY_RECEIPT => 'Phiếu nhập kho',
            self::INVENTORY_ISSUE => 'Phiếu xuất kho',
            self::INVENTORY_TRANSFER => 'Phiếu chuyển kho',
            self::PRODUCTION_ORDER => 'Lệnh sản xuất',

            self::PAYROLL => 'Bảng tính lương',

            self::JOURNAL_ENTRY => 'Chứng từ nghiệp vụ khác',
            self::PERIOD_CLOSING => 'Kết chuyển lãi lỗ',

            self::BORROWING_CONTRACT => 'Khế ước vay',
            self::COST_ALLOCATION => 'Bút toán phân bổ giá thành',
            self::VAT_DECLARATION => 'Tờ khai thuế GTGT',
        };
    }

    /**
     * Mã phân hệ kế toán (Module key)
     */
    public function module(): string
    {
        return match ($this) {
            self::FIXED_ASSET_INCREMENT, self::FIXED_ASSET_DECREMENT, self::FIXED_ASSET_DEPRECIATION => 'fixed_asset',
            self::TOOL_INCREMENT, self::TOOL_ALLOCATION, self::TOOL_DECREMENT => 'tool',
            self::SALES_QUOTE, self::SALES_ORDER, self::SALES_INVOICE, self::SALES_RETURN, self::SALES_DISCOUNT => 'sales',
            self::PURCHASE_ORDER, self::PURCHASE_CONTRACT, self::PURCHASE_INVOICE, self::PURCHASE_RETURN, self::PURCHASE_DISCOUNT => 'purchase',
            self::CASH_RECEIPT, self::CASH_PAYMENT => 'cash',
            self::BANK_RECEIPT, self::BANK_PAYMENT => 'bank',
            self::INVENTORY_RECEIPT, self::INVENTORY_ISSUE, self::INVENTORY_TRANSFER, self::PRODUCTION_ORDER => 'inventory',
            self::PAYROLL => 'payroll',
            self::JOURNAL_ENTRY, self::PERIOD_CLOSING => 'gl',
            self::BORROWING_CONTRACT, self::COST_ALLOCATION, self::VAT_DECLARATION => 'other',
        };
    }

    /**
     * Tên nhóm phân hệ tiếng Việt (MISA Module Label)
     */
    public function moduleName(): string
    {
        return match ($this->module()) {
            'fixed_asset' => 'Tài sản cố định',
            'tool' => 'Công cụ dụng cụ',
            'sales' => 'Bán hàng',
            'purchase' => 'Mua hàng',
            'cash' => 'Quỹ (Tiền mặt)',
            'bank' => 'Tiền gửi ngân hàng',
            'inventory' => 'Kho',
            'payroll' => 'Tiền lương',
            'gl' => 'Tổng hợp',
            'other' => 'Thuế & Khác / Giá thành / Vay',
            default => 'Khác',
        };
    }

    /**
     * Lớp Eloquent Model tương ứng
     */
    public function modelClass(): ?string
    {
        return match ($this) {
            self::FIXED_ASSET_INCREMENT, self::FIXED_ASSET_DECREMENT => FixedAsset::class,
            self::TOOL_INCREMENT, self::TOOL_DECREMENT => ToolEquipment::class,
            self::SALES_QUOTE => SalesQuote::class,
            self::SALES_ORDER => SalesOrder::class,
            self::SALES_INVOICE => SalesInvoice::class,
            self::SALES_RETURN => SalesReturn::class,
            self::SALES_DISCOUNT => SalesDiscount::class,
            self::PURCHASE_ORDER => PurchaseOrder::class,
            self::PURCHASE_CONTRACT => PurchaseContract::class,
            self::PURCHASE_INVOICE => PurchaseInvoice::class,
            self::PURCHASE_RETURN => PurchaseReturn::class,
            self::PURCHASE_DISCOUNT => PurchaseDiscount::class,
            self::CASH_RECEIPT => CashReceipt::class,
            self::CASH_PAYMENT => CashPayment::class,
            self::BANK_RECEIPT => BankReceipt::class,
            self::BANK_PAYMENT => BankPayment::class,
            self::INVENTORY_RECEIPT => InventoryReceipt::class,
            self::INVENTORY_ISSUE => InventoryIssue::class,
            self::PRODUCTION_ORDER => ProductionOrder::class,
            self::PAYROLL => Payroll::class,
            self::JOURNAL_ENTRY, self::PERIOD_CLOSING => JournalEntry::class,
            self::BORROWING_CONTRACT => BorrowingContract::class,
            self::COST_ALLOCATION => CostAllocation::class,
            default => null,
        };
    }

    /**
     * Tiền tố số chứng từ tự động
     */
    public function prefix(): string
    {
        return match ($this) {
            self::FIXED_ASSET_INCREMENT => 'TSCD',
            self::FIXED_ASSET_DECREMENT => 'GGTS',
            self::FIXED_ASSET_DEPRECIATION => 'KHTS',
            self::TOOL_INCREMENT => 'CCDC',
            self::TOOL_ALLOCATION => 'PBCC',
            self::TOOL_DECREMENT => 'GGCC',
            self::SALES_QUOTE => 'BG',
            self::SALES_ORDER => 'DDH',
            self::SALES_INVOICE => 'HDBH',
            self::SALES_RETURN => 'TLBH',
            self::SALES_DISCOUNT => 'GGBH',
            self::PURCHASE_ORDER => 'PO',
            self::PURCHASE_CONTRACT => 'HDM',
            self::PURCHASE_INVOICE => 'HDMH',
            self::PURCHASE_RETURN => 'TLMH',
            self::PURCHASE_DISCOUNT => 'GGMH',
            self::CASH_RECEIPT => 'PT',
            self::CASH_PAYMENT => 'PC',
            self::BANK_RECEIPT => 'BC',
            self::BANK_PAYMENT => 'UNC',
            self::INVENTORY_RECEIPT => 'PNK',
            self::INVENTORY_ISSUE => 'PXK',
            self::INVENTORY_TRANSFER => 'PCK',
            self::PRODUCTION_ORDER => 'LSX',
            self::PAYROLL => 'BL',
            self::JOURNAL_ENTRY => 'PKT',
            self::PERIOD_CLOSING => 'PKC',
            self::BORROWING_CONTRACT => 'KUV',
            self::COST_ALLOCATION => 'PBGT',
            self::VAT_DECLARATION => 'TKTT',
        };
    }

    /**
     * Tài khoản Nợ mặc định theo regime đã được caller resolve.
     */
    public function defaultDebitAccount(string $standard = 'TT99'): string
    {
        $is133 = strtoupper($standard) === 'TT133';

        return match ($this) {
            self::FIXED_ASSET_INCREMENT => '2111',
            self::FIXED_ASSET_DECREMENT => $is133 ? '811' : '811',
            self::FIXED_ASSET_DEPRECIATION => $is133 ? '6422' : '6424',
            self::TOOL_INCREMENT => '242',
            self::TOOL_ALLOCATION => $is133 ? '6422' : '6423',
            self::TOOL_DECREMENT => '811',
            self::SALES_INVOICE => '131',
            self::SALES_RETURN => $is133 ? '5111' : '5212',
            self::SALES_DISCOUNT => $is133 ? '5111' : '5213',
            self::PURCHASE_INVOICE => '1561',
            self::PURCHASE_RETURN => '331',
            self::PURCHASE_DISCOUNT => '331',
            self::CASH_RECEIPT => '1111',
            self::CASH_PAYMENT => '331',
            self::BANK_RECEIPT => '1121',
            self::BANK_PAYMENT => '331',
            self::INVENTORY_RECEIPT => '1561',
            self::INVENTORY_ISSUE => '632',
            self::PRODUCTION_ORDER => '154',
            self::PAYROLL => $is133 ? '6422' : '6421',
            self::JOURNAL_ENTRY => '1111',
            self::PERIOD_CLOSING => '911',
            self::BORROWING_CONTRACT => '1121',
            self::COST_ALLOCATION => '154',
            default => '',
        };
    }

    /**
     * Tài khoản Có mặc định theo regime đã được caller resolve.
     */
    public function defaultCreditAccount(string $standard = 'TT99'): string
    {
        $is133 = strtoupper($standard) === 'TT133';

        return match ($this) {
            self::FIXED_ASSET_INCREMENT => '331',
            self::FIXED_ASSET_DECREMENT => '2111',
            self::FIXED_ASSET_DEPRECIATION => '2141',
            self::TOOL_INCREMENT => '331',
            self::TOOL_ALLOCATION => '242',
            self::TOOL_DECREMENT => '242',
            self::SALES_INVOICE => '5111',
            self::SALES_RETURN => '131',
            self::SALES_DISCOUNT => '131',
            self::PURCHASE_INVOICE => '331',
            self::PURCHASE_RETURN => '1561',
            self::PURCHASE_DISCOUNT => '1561',
            self::CASH_RECEIPT => '131',
            self::CASH_PAYMENT => '1111',
            self::BANK_RECEIPT => '131',
            self::BANK_PAYMENT => '1121',
            self::INVENTORY_RECEIPT => '331',
            self::INVENTORY_ISSUE => '1561',
            self::PRODUCTION_ORDER => '152',
            self::PAYROLL => '3341',
            self::JOURNAL_ENTRY => '1111',
            self::PERIOD_CLOSING => '911',
            self::BORROWING_CONTRACT => '3411',
            self::COST_ALLOCATION => $is133 ? '154' : '621',
            default => '',
        };
    }

    /**
     * Tìm Enum từ tên tiếng Việt, code hoặc enum key
     */
    public static function resolveType(string|self|null $type): ?self
    {
        if ($type instanceof self) {
            return $type;
        }
        if (empty($type)) {
            return null;
        }

        $typeStr = trim($type);

        // Try direct enum value
        $fromVal = self::tryFrom($typeStr);
        if ($fromVal) {
            return $fromVal;
        }

        $lower = mb_strtolower($typeStr);

        // Mapping synonyms and labels
        $map = [
            'sales_invoice' => self::SALES_INVOICE,
            'hóa đơn bán hàng' => self::SALES_INVOICE,
            'chứng từ bán hàng' => self::SALES_INVOICE,
            'salesinvoice' => self::SALES_INVOICE,

            'sales_quote' => self::SALES_QUOTE,
            'báo giá' => self::SALES_QUOTE,
            'salesquote' => self::SALES_QUOTE,

            'sales_order' => self::SALES_ORDER,
            'đơn đặt hàng' => self::SALES_ORDER,
            'đơn bán hàng' => self::SALES_ORDER,
            'salesorder' => self::SALES_ORDER,

            'sales_return' => self::SALES_RETURN,
            'trả lại hàng bán' => self::SALES_RETURN,
            'hàng bán trả lại' => self::SALES_RETURN,
            'salesreturn' => self::SALES_RETURN,
            'tlhb' => self::SALES_RETURN,

            'sales_discount' => self::SALES_DISCOUNT,
            'giảm giá hàng bán' => self::SALES_DISCOUNT,
            'hàng bán giảm giá' => self::SALES_DISCOUNT,
            'salesdiscount' => self::SALES_DISCOUNT,
            'gghb' => self::SALES_DISCOUNT,

            'purchase_invoice' => self::PURCHASE_INVOICE,
            'hóa đơn mua hàng' => self::PURCHASE_INVOICE,
            'chứng từ mua hàng' => self::PURCHASE_INVOICE,
            'chứng từ mua dịch vụ' => self::PURCHASE_INVOICE,
            'purchaseinvoice' => self::PURCHASE_INVOICE,

            'purchase_return' => self::PURCHASE_RETURN,
            'trả lại hàng mua' => self::PURCHASE_RETURN,
            'hàng mua trả lại' => self::PURCHASE_RETURN,
            'purchasereturn' => self::PURCHASE_RETURN,
            'tlmh' => self::PURCHASE_RETURN,

            'purchase_discount' => self::PURCHASE_DISCOUNT,
            'giảm giá hàng mua' => self::PURCHASE_DISCOUNT,
            'hàng mua giảm giá' => self::PURCHASE_DISCOUNT,
            'purchasediscount' => self::PURCHASE_DISCOUNT,
            'ggmh' => self::PURCHASE_DISCOUNT,

            'purchase_order' => self::PURCHASE_ORDER,
            'đơn mua hàng' => self::PURCHASE_ORDER,
            'đơn đặt mua' => self::PURCHASE_ORDER,
            'purchaseorder' => self::PURCHASE_ORDER,

            'purchase_contract' => self::PURCHASE_CONTRACT,
            'hợp đồng mua' => self::PURCHASE_CONTRACT,
            'hợp đồng mua hàng' => self::PURCHASE_CONTRACT,
            'purchasecontract' => self::PURCHASE_CONTRACT,

            'cash_receipt' => self::CASH_RECEIPT,
            'phiếu thu' => self::CASH_RECEIPT,
            'thu tiền mặt' => self::CASH_RECEIPT,
            'cashreceipt' => self::CASH_RECEIPT,

            'cash_payment' => self::CASH_PAYMENT,
            'phiếu chi' => self::CASH_PAYMENT,
            'chi tiền mặt' => self::CASH_PAYMENT,
            'cashpayment' => self::CASH_PAYMENT,

            'bank_receipt' => self::BANK_RECEIPT,
            'thu tiền gửi' => self::BANK_RECEIPT,
            'báo có' => self::BANK_RECEIPT,
            'giấy báo có' => self::BANK_RECEIPT,
            'bankreceipt' => self::BANK_RECEIPT,

            'bank_payment' => self::BANK_PAYMENT,
            'ủy nhiệm chi' => self::BANK_PAYMENT,
            'báo nợ' => self::BANK_PAYMENT,
            'giấy báo nợ' => self::BANK_PAYMENT,
            'bankpayment' => self::BANK_PAYMENT,

            'inventory_receipt' => self::INVENTORY_RECEIPT,
            'phiếu nhập kho' => self::INVENTORY_RECEIPT,
            'nhập kho' => self::INVENTORY_RECEIPT,
            'inventoryreceipt' => self::INVENTORY_RECEIPT,

            'inventory_issue' => self::INVENTORY_ISSUE,
            'phiếu xuất kho' => self::INVENTORY_ISSUE,
            'xuất kho' => self::INVENTORY_ISSUE,
            'inventoryissue' => self::INVENTORY_ISSUE,

            'production_order' => self::PRODUCTION_ORDER,
            'lệnh sản xuất' => self::PRODUCTION_ORDER,
            'productionorder' => self::PRODUCTION_ORDER,

            'payroll' => self::PAYROLL,
            'bảng tính lương' => self::PAYROLL,
            'bảng lương' => self::PAYROLL,

            'fixed_asset' => self::FIXED_ASSET_INCREMENT,
            'fixedasset' => self::FIXED_ASSET_INCREMENT,
            'ghi tăng tscđ' => self::FIXED_ASSET_INCREMENT,
            'tài sản cố định' => self::FIXED_ASSET_INCREMENT,

            'tool_equipment' => self::TOOL_INCREMENT,
            'toolequipment' => self::TOOL_INCREMENT,
            'ghi tăng ccdc' => self::TOOL_INCREMENT,
            'công cụ dụng cụ' => self::TOOL_INCREMENT,

            'journal_entry' => self::JOURNAL_ENTRY,
            'journalentry' => self::JOURNAL_ENTRY,
            'chứng từ nghiệp vụ khác' => self::JOURNAL_ENTRY,

            'borrowing_contract' => self::BORROWING_CONTRACT,
            'borrowingcontract' => self::BORROWING_CONTRACT,
            'khế ước vay' => self::BORROWING_CONTRACT,
            'hợp đồng vay' => self::BORROWING_CONTRACT,

            'cost_allocation' => self::COST_ALLOCATION,
            'costallocation' => self::COST_ALLOCATION,
            'bút toán phân bổ giá thành' => self::COST_ALLOCATION,
            'phân bổ giá thành' => self::COST_ALLOCATION,
        ];

        return $map[$lower] ?? null;
    }

    /**
     * Lấy toàn bộ danh mục chứng từ hệ thống với metadata phục vụ Frontend Modal & Registry
     */
    public static function allTypes(): array
    {
        $list = [];
        foreach (self::cases() as $case) {
            $list[] = [
                'code' => $case->value,
                'name' => $case->label(),
                'module' => $case->module(),
                'module_name' => $case->moduleName(),
                'prefix' => $case->prefix(),
                'model_class' => $case->modelClass(),
                'default_debit_account_tt200' => $case->defaultDebitAccount('TT200'),
                'default_credit_account_tt200' => $case->defaultCreditAccount('TT200'),
                'default_debit_account_tt99' => $case->defaultDebitAccount('TT99'),
                'default_credit_account_tt99' => $case->defaultCreditAccount('TT99'),
                'default_debit_account_tt133' => $case->defaultDebitAccount('TT133'),
                'default_credit_account_tt133' => $case->defaultCreditAccount('TT133'),
            ];
        }

        return $list;
    }

    /**
     * Tự động suy diễn đối tượng, diễn giải, định khoản Nợ/Có khi kế toán chọn chứng từ tham chiếu
     */
    public static function resolveCrossVoucherDefaults(
        string|self|null $sourceType,
        string|self|null $targetType,
        mixed $targetModelOrId,
        string $standard = 'TT99'
    ): array {
        $sourceEnum = self::resolveType($sourceType);
        $targetEnum = self::resolveType($targetType);

        // Nạp Target Record nếu là ID
        $targetModel = $targetModelOrId;
        if (! is_object($targetModel) && $targetEnum && $targetEnum->modelClass()) {
            $modelClass = $targetEnum->modelClass();
            $targetModel = $modelClass::find($targetModelOrId);
        }

        $result = [
            'contact_id' => null,
            'contact_code' => null,
            'contact_name' => null,
            'payer_name' => null,
            'receiver_name' => null,
            'voucher_number' => null,
            'voucher_date' => null,
            'total_amount' => 0,
            'description' => '',
            'lines' => [],
        ];

        if (! $targetModel) {
            return $result;
        }

        // Trích xuất metadata cơ bản từ Target Model
        $vNum = $targetModel->voucher_number ?? $targetModel->invoice_number ?? $targetModel->quote_number ?? $targetModel->order_number ?? $targetModel->contract_number ?? $targetModel->tool_code ?? $targetModel->asset_code ?? '';
        $vDate = $targetModel->voucher_date ?? $targetModel->invoice_date ?? $targetModel->quote_date ?? $targetModel->order_date ?? $targetModel->signed_date ?? $targetModel->purchase_date ?? $targetModel->disbursement_date ?? now()->toDateString();
        $totalAmount = (float) ($targetModel->total_amount ?? $targetModel->amount ?? $targetModel->contract_value ?? $targetModel->original_cost ?? $targetModel->total_cost ?? 0);

        $result['voucher_number'] = $vNum;
        $result['voucher_date'] = substr((string) $vDate, 0, 10);
        $result['total_amount'] = $totalAmount;

        // Trích xuất thông tin Đối tượng (Customer / Supplier / Employee / Lender)
        if (isset($targetModel->customer_id) || isset($targetModel->customer)) {
            $cust = $targetModel->customer ?? null;
            $result['contact_id'] = $targetModel->customer_id ?? ($cust->id ?? null);
            $result['contact_code'] = $targetModel->customer_code ?? ($cust->code ?? ('KH'.$result['contact_id']));
            $result['contact_name'] = $targetModel->customer_name ?? ($cust->name ?? 'Khách hàng');
            $result['payer_name'] = $result['contact_name'];
        } elseif (isset($targetModel->supplier_id) || isset($targetModel->supplier)) {
            $supp = $targetModel->supplier ?? null;
            $result['contact_id'] = $targetModel->supplier_id ?? ($supp->id ?? null);
            $result['contact_code'] = $targetModel->supplier_code ?? ($supp->code ?? ('NCC'.$result['contact_id']));
            $result['contact_name'] = $targetModel->supplier_name ?? ($supp->name ?? 'Nhà cung cấp');
            $result['receiver_name'] = $result['contact_name'];
        } elseif (isset($targetModel->lender_name)) {
            $result['contact_name'] = $targetModel->lender_name;
            $result['contact_code'] = 'LENDER';
            $result['receiver_name'] = $targetModel->lender_name;
            $result['payer_name'] = $targetModel->lender_name;
        } elseif (isset($targetModel->contact_name)) {
            $result['contact_id'] = $targetModel->contact_id ?? null;
            $result['contact_name'] = $targetModel->contact_name;
            $result['contact_code'] = (string) ($targetModel->contact_id ?? '');
            $result['payer_name'] = $targetModel->payer_name ?? $targetModel->contact_name;
            $result['receiver_name'] = $targetModel->receiver_name ?? $targetModel->contact_name;
        }

        // Xử lý Ma trận Nghiệp vụ Auto-Fill
        $targetName = $targetEnum ? $targetEnum->label() : 'chứng từ gốc';

        // This endpoint historically returned account lines from local
        // defaults. Those values are suggestions only and are not an
        // owner-approved mapping. In a deployment, fail closed before any
        // branch (or the final fallback) can expose them. The legacy test
        // harness may explicitly disable this boundary while a real mapping
        // resolver is being integrated.
        if (config('accounting.enforce_voucher_reference_account_mappings', true)) {
            throw ValidationException::withMessages([
                'account_mappings' => 'Không thể tự động sinh tài khoản từ chứng từ tham chiếu khi chưa có mapping tài khoản được phê duyệt.',
            ]);
        }

        // 1. Target là Bán hàng (SalesInvoice)
        if ($targetModel instanceof SalesInvoice) {
            if ($sourceEnum === self::CASH_RECEIPT) {
                $result['description'] = "Thu tiền khách hàng theo HĐ {$vNum}";
                $result['lines'][] = [
                    'debit_account' => '1111',
                    'credit_account' => '131',
                    'amount' => $totalAmount,
                    'description' => $result['description'],
                    'invoice_id' => $targetModel->id,
                ];
            } elseif ($sourceEnum === self::BANK_RECEIPT) {
                $result['description'] = "Thu tiền gửi theo HĐ {$vNum}";
                $result['lines'][] = [
                    'debit_account' => '1121',
                    'credit_account' => '131',
                    'amount' => $totalAmount,
                    'description' => $result['description'],
                    'invoice_id' => $targetModel->id,
                ];
            } elseif ($sourceEnum === self::INVENTORY_ISSUE) {
                $result['description'] = "Xuất kho bán hàng theo HĐ {$vNum}";
                if (method_exists($targetModel, 'lines') && $targetModel->lines->isNotEmpty()) {
                    foreach ($targetModel->lines as $line) {
                        $result['lines'][] = [
                            'item_id' => $line->item_id ?? null,
                            'description' => $line->description ?? "Xuất bán theo HĐ {$vNum}",
                            'quantity' => $line->quantity ?? 1,
                            'unit_price' => $line->cogs_price ?? ($line->unit_price ?? 0),
                            'amount' => $line->cogs_amount ?? ($line->amount ?? 0),
                            'debit_account' => '632',
                            'credit_account' => $line->inventory_account ?? '1561',
                        ];
                    }
                }
            } elseif ($sourceEnum === self::SALES_RETURN) {
                $result['description'] = "Trả lại hàng bán theo HĐ {$vNum}";
                if (method_exists($targetModel, 'lines') && $targetModel->lines->isNotEmpty()) {
                    foreach ($targetModel->lines as $line) {
                        $result['lines'][] = [
                            'item_id' => $line->item_id ?? null,
                            'item_code' => $line->item?->code ?? $line->item_code ?? null,
                            'item_name' => $line->item?->name ?? $line->item_name ?? null,
                            'description' => $line->description ?? "Trả lại theo HĐ {$vNum}",
                            'unit' => $line->unit ?? 'Chiếc',
                            'quantity' => $line->quantity ?? 1,
                            'unit_price' => $line->unit_price ?? 0,
                            'amount' => $line->amount ?? 0,
                            'debit_account' => strtoupper($standard) === 'TT133' ? '5111' : '5212',
                            'credit_account' => '131',
                            'tax_rate' => $line->tax_rate ?? 10,
                            'tax_amount' => $line->tax_amount ?? 0,
                            'tax_account' => '33311',
                            'inventory_account' => '1561',
                            'cogs_account' => '632',
                            'cogs_price' => $line->cogs_price ?? ($line->cogs_unit_price ?? 0),
                            'cogs_amount' => $line->cogs_amount ?? 0,
                            'invoice_number' => $vNum,
                            'invoice_date' => $vDate,
                        ];
                    }
                }
            } elseif ($sourceEnum === self::SALES_DISCOUNT) {
                $result['description'] = "Giảm giá hàng bán theo HĐ {$vNum}";
                if (method_exists($targetModel, 'lines') && $targetModel->lines->isNotEmpty()) {
                    foreach ($targetModel->lines as $line) {
                        $result['lines'][] = [
                            'item_id' => $line->item_id ?? null,
                            'item_code' => $line->item?->code ?? $line->item_code ?? null,
                            'item_name' => $line->item?->name ?? $line->item_name ?? null,
                            'description' => $line->description ?? "Giảm giá theo HĐ {$vNum}",
                            'unit' => $line->unit ?? 'Chiếc',
                            'quantity' => $line->quantity ?? 1,
                            'unit_price' => $line->unit_price ?? 0,
                            'amount' => $line->amount ?? 0,
                            'discount_amount' => $line->discount_amount ?? 0,
                            'debit_account' => strtoupper($standard) === 'TT133' ? '5111' : '5213',
                            'credit_account' => '131',
                            'tax_rate' => $line->tax_rate ?? 10,
                            'tax_amount' => $line->tax_amount ?? 0,
                            'tax_account' => '33311',
                            'invoice_number' => $vNum,
                            'invoice_date' => $vDate,
                        ];
                    }
                }
            }
        }
        // 2. Target là Mua hàng (PurchaseInvoice)
        elseif ($targetModel instanceof PurchaseInvoice) {
            if ($sourceEnum === self::CASH_PAYMENT) {
                $result['description'] = "Chi tiền trả NCC theo HĐ {$vNum}";
                $result['lines'][] = [
                    'debit_account' => '331',
                    'credit_account' => '1111',
                    'amount' => $totalAmount,
                    'description' => $result['description'],
                    'invoice_id' => $targetModel->id,
                ];
            } elseif ($sourceEnum === self::BANK_PAYMENT) {
                $result['description'] = "Ủy nhiệm chi trả NCC theo HĐ {$vNum}";
                $result['lines'][] = [
                    'debit_account' => '331',
                    'credit_account' => '1121',
                    'amount' => $totalAmount,
                    'description' => $result['description'],
                    'invoice_id' => $targetModel->id,
                ];
            } elseif ($sourceEnum === self::INVENTORY_RECEIPT) {
                $result['description'] = "Nhập kho mua hàng theo HĐ {$vNum}";
                if (method_exists($targetModel, 'lines') && $targetModel->lines->isNotEmpty()) {
                    foreach ($targetModel->lines as $line) {
                        $result['lines'][] = [
                            'item_id' => $line->item_id ?? null,
                            'description' => $line->description ?? "Nhập kho theo HĐ {$vNum}",
                            'quantity' => $line->quantity ?? 1,
                            'unit_price' => $line->unit_price ?? 0,
                            'amount' => $line->amount ?? 0,
                            'debit_account' => $line->debit_account ?? '1561',
                            'credit_account' => '331',
                        ];
                    }
                }
            } elseif ($sourceEnum === self::PURCHASE_RETURN) {
                $result['description'] = "Trả lại hàng mua theo HĐ {$vNum}";
                if (method_exists($targetModel, 'lines') && $targetModel->lines->isNotEmpty()) {
                    foreach ($targetModel->lines as $line) {
                        $result['lines'][] = [
                            'item_id' => $line->item_id ?? null,
                            'item_code' => $line->item?->code ?? $line->item_code ?? null,
                            'item_name' => $line->item?->name ?? $line->item_name ?? $line->description ?? null,
                            'description' => $line->description ?? "Trả lại theo HĐ {$vNum}",
                            'unit' => $line->unit ?? 'Chiếc',
                            'quantity' => $line->quantity ?? 1,
                            'unit_price' => $line->unit_price ?? 0,
                            'amount' => $line->amount ?? 0,
                            'debit_account' => '331',
                            'credit_account' => $line->debit_account ?? '1561',
                            'tax_rate' => $line->tax_rate ?? 10,
                            'tax_amount' => $line->tax_amount ?? 0,
                            'tax_account' => $line->tax_account ?? '1331',
                            'invoice_number' => $vNum,
                            'invoice_date' => $vDate,
                        ];
                    }
                }
            } elseif ($sourceEnum === self::PURCHASE_DISCOUNT) {
                $result['description'] = "Giảm giá hàng mua theo HĐ {$vNum}";
                if (method_exists($targetModel, 'lines') && $targetModel->lines->isNotEmpty()) {
                    foreach ($targetModel->lines as $line) {
                        $result['lines'][] = [
                            'item_id' => $line->item_id ?? null,
                            'item_code' => $line->item?->code ?? $line->item_code ?? null,
                            'item_name' => $line->item?->name ?? $line->item_name ?? $line->description ?? null,
                            'description' => $line->description ?? "Giảm giá theo HĐ {$vNum}",
                            'unit' => $line->unit ?? 'Chiếc',
                            'quantity' => $line->quantity ?? 1,
                            'unit_price' => $line->unit_price ?? 0,
                            'amount' => $line->amount ?? 0,
                            'discount_amount' => $line->discount_amount ?? $line->amount ?? 0,
                            'debit_account' => '331',
                            'credit_account' => $line->debit_account ?? '1561',
                            'tax_rate' => $line->tax_rate ?? 10,
                            'tax_amount' => $line->tax_amount ?? 0,
                            'tax_account' => $line->tax_account ?? '1331',
                            'invoice_number' => $vNum,
                            'invoice_date' => $vDate,
                        ];
                    }
                }
            }
        }
        // 3. Target là Báo giá hoặc Đơn đặt hàng (SalesQuote / SalesOrder)
        elseif ($targetModel instanceof SalesQuote || $targetModel instanceof SalesOrder) {
            $prefixDesc = ($targetModel instanceof SalesQuote) ? 'báo giá' : 'đơn đặt hàng';
            $result['description'] = "Bán hàng theo {$prefixDesc} {$vNum}";

            if ($targetModel->relationLoaded('lines') || method_exists($targetModel, 'lines')) {
                foreach ($targetModel->lines as $line) {
                    $result['lines'][] = [
                        'item_id' => $line->item_id ?? null,
                        'item_code' => $line->item_code ?? null,
                        'item_name' => $line->item_name ?? null,
                        'unit' => $line->unit ?? 'Chiếc',
                        'quantity' => $line->quantity ?? 1,
                        'unit_price' => $line->unit_price ?? 0,
                        'amount' => $line->amount ?? 0,
                        'tax_rate' => $line->tax_rate ?? 10,
                        'tax_amount' => $line->tax_amount ?? 0,
                        'debit_account' => '131',
                        'credit_account' => '5111',
                        'description' => $line->description ?? $result['description'],
                    ];
                }
            }
        }
        // 3b. Target là Trả lại hàng bán (SalesReturn)
        elseif ($targetModel instanceof SalesReturn) {
            if ($sourceEnum === self::INVENTORY_RECEIPT) {
                $result['description'] = "Nhập kho hàng bán trả lại theo {$vNum}";
                if (method_exists($targetModel, 'lines') && $targetModel->lines && $targetModel->lines->isNotEmpty()) {
                    foreach ($targetModel->lines as $line) {
                        $result['lines'][] = [
                            'item_id' => $line->item_id ?? null,
                            'description' => $line->description ?? "Nhập kho trả lại theo {$vNum}",
                            'quantity' => $line->quantity ?? 1,
                            'unit_price' => $line->cogs_price ?? ($line->cogs_unit_price ?? 0),
                            'amount' => $line->cogs_amount ?? 0,
                            'debit_account' => $line->inventory_account ?? ($line->cogs_debit_account ?? '1561'),
                            'credit_account' => $line->cogs_account ?? ($line->cogs_credit_account ?? '632'),
                        ];
                    }
                }
            } elseif ($sourceEnum === self::CASH_PAYMENT || $sourceEnum === self::BANK_PAYMENT) {
                $isBank = ($sourceEnum === self::BANK_PAYMENT);
                $result['description'] = ($isBank ? 'Ủy nhiệm chi' : 'Chi tiền')." trả lại hàng bán theo {$vNum}";
                $result['lines'][] = [
                    'debit_account' => '131',
                    'credit_account' => $isBank ? '1121' : '1111',
                    'amount' => $totalAmount,
                    'description' => $result['description'],
                ];
            }
        }
        // 3c. Target là Giảm giá hàng bán (SalesDiscount)
        elseif ($targetModel instanceof SalesDiscount) {
            if ($sourceEnum === self::CASH_PAYMENT || $sourceEnum === self::BANK_PAYMENT) {
                $isBank = ($sourceEnum === self::BANK_PAYMENT);
                $result['description'] = ($isBank ? 'Ủy nhiệm chi' : 'Chi tiền')." giảm giá hàng bán theo {$vNum}";
                $result['lines'][] = [
                    'debit_account' => '131',
                    'credit_account' => $isBank ? '1121' : '1111',
                    'amount' => $totalAmount,
                    'description' => $result['description'],
                ];
            }
        }
        // 4. Target là Đơn mua hàng / Hợp đồng mua (PurchaseOrder / PurchaseContract)
        elseif ($targetModel instanceof PurchaseOrder || $targetModel instanceof PurchaseContract) {
            $prefixDesc = ($targetModel instanceof PurchaseOrder) ? 'đơn mua hàng' : 'hợp đồng mua';
            $result['description'] = "Mua hàng theo {$prefixDesc} {$vNum}";

            if (method_exists($targetModel, 'lines') && $targetModel->lines && $targetModel->lines->isNotEmpty()) {
                foreach ($targetModel->lines as $line) {
                    $result['lines'][] = [
                        'item_id' => $line->item_id ?? null,
                        'quantity' => $line->quantity ?? 1,
                        'unit_price' => $line->unit_price ?? 0,
                        'amount' => $line->amount ?? 0,
                        'debit_account' => '1561',
                        'credit_account' => '331',
                        'description' => $line->description ?? $result['description'],
                    ];
                }
            } else {
                $result['lines'][] = [
                    'debit_account' => '1561',
                    'credit_account' => '331',
                    'amount' => $totalAmount,
                    'description' => $result['description'],
                ];
            }
        }
        // 4b. Target là Trả lại hàng mua (PurchaseReturn)
        elseif ($targetModel instanceof PurchaseReturn) {
            if ($sourceEnum === self::INVENTORY_ISSUE) {
                $result['description'] = "Xuất kho trả lại hàng mua theo {$vNum}";
                if (method_exists($targetModel, 'lines') && $targetModel->lines && $targetModel->lines->isNotEmpty()) {
                    foreach ($targetModel->lines as $line) {
                        $result['lines'][] = [
                            'item_id' => $line->item_id ?? null,
                            'description' => $line->description ?? "Xuất kho trả lại theo {$vNum}",
                            'quantity' => $line->quantity ?? 1,
                            'unit_price' => $line->unit_price ?? 0,
                            'amount' => $line->amount ?? 0,
                            'debit_account' => '331',
                            'credit_account' => $line->credit_account ?? '1561',
                        ];
                    }
                }
            } elseif ($sourceEnum === self::CASH_RECEIPT || $sourceEnum === self::BANK_RECEIPT) {
                $isBank = ($sourceEnum === self::BANK_RECEIPT);
                $result['description'] = ($isBank ? 'Thu tiền gửi' : 'Thu tiền mặt')." do trả lại hàng mua theo {$vNum}";
                $result['lines'][] = [
                    'debit_account' => $isBank ? '1121' : '1111',
                    'credit_account' => '331',
                    'amount' => $totalAmount,
                    'description' => $result['description'],
                ];
            }
        }
        // 4c. Target là Giảm giá hàng mua (PurchaseDiscount)
        elseif ($targetModel instanceof PurchaseDiscount) {
            if ($sourceEnum === self::CASH_RECEIPT || $sourceEnum === self::BANK_RECEIPT) {
                $isBank = ($sourceEnum === self::BANK_RECEIPT);
                $result['description'] = ($isBank ? 'Thu tiền gửi' : 'Thu tiền mặt')." do giảm giá hàng mua theo {$vNum}";
                $result['lines'][] = [
                    'debit_account' => $isBank ? '1121' : '1111',
                    'credit_account' => '331',
                    'amount' => $totalAmount,
                    'description' => $result['description'],
                ];
            }
        }
        // 5. Target là Tiền lương (Payroll)
        elseif ($targetModel instanceof Payroll) {
            $month = $targetModel->month ?? '';
            $monthStr = $month ? "tháng {$month}" : $vNum;
            if ($sourceEnum === self::CASH_PAYMENT) {
                $result['description'] = "Chi trả tiền lương {$monthStr}";
                $result['lines'][] = [
                    'debit_account' => '3341',
                    'credit_account' => '1111',
                    'amount' => $totalAmount,
                    'description' => $result['description'],
                ];
            } elseif ($sourceEnum === self::BANK_PAYMENT) {
                $result['description'] = "Ủy nhiệm chi trả tiền lương {$monthStr}";
                $result['lines'][] = [
                    'debit_account' => '3341',
                    'credit_account' => '1121',
                    'amount' => $totalAmount,
                    'description' => $result['description'],
                ];
            }
        }
        // 6. Target là Khế ước vay (BorrowingContract)
        elseif ($targetModel instanceof BorrowingContract) {
            if ($sourceEnum === self::BANK_RECEIPT) {
                $result['description'] = "Thu tiền giải ngân theo khế ước vay {$vNum}";
                $result['lines'][] = [
                    'debit_account' => '1121',
                    'credit_account' => '3411',
                    'amount' => $totalAmount,
                    'description' => $result['description'],
                ];
            } elseif ($sourceEnum === self::BANK_PAYMENT || $sourceEnum === self::CASH_PAYMENT) {
                $isBank = $sourceEnum === self::BANK_PAYMENT;
                $result['description'] = "Chi trả nợ gốc theo khế ước vay {$vNum}";
                $result['lines'][] = [
                    'debit_account' => '3411',
                    'credit_account' => $isBank ? '1121' : '1111',
                    'amount' => (float) ($targetModel->remaining_principal ?: $totalAmount),
                    'description' => $result['description'],
                ];
            }
        }
        // 7. Target là Tài sản cố định (FixedAsset)
        elseif ($targetModel instanceof FixedAsset) {
            $name = $targetModel->asset_name ?? $vNum;
            $result['description'] = "Thanh toán mua TSCĐ {$name}";
            $isBank = ($sourceEnum === self::BANK_PAYMENT || $sourceEnum === self::BANK_RECEIPT);
            $creditAcc = $isBank ? '1121' : '1111';
            $result['lines'][] = [
                'debit_account' => $targetModel->asset_account ?? '2111',
                'credit_account' => $creditAcc,
                'amount' => $totalAmount,
                'description' => $result['description'],
            ];
        }
        // 8. Target là Công cụ dụng cụ (ToolEquipment)
        elseif ($targetModel instanceof ToolEquipment) {
            $name = $targetModel->tool_name ?? $vNum;
            $result['description'] = "Thanh toán mua CCDC {$name}";
            $isBank = ($sourceEnum === self::BANK_PAYMENT || $sourceEnum === self::BANK_RECEIPT);
            $creditAcc = $isBank ? '1121' : '1111';
            $result['lines'][] = [
                'debit_account' => $targetModel->tool_account ?? '242',
                'credit_account' => $creditAcc,
                'amount' => $totalAmount,
                'description' => $result['description'],
            ];
        }
        // 9. Target là Lệnh sản xuất (ProductionOrder)
        elseif ($targetModel instanceof ProductionOrder) {
            $result['description'] = "Xuất NVL phục vụ Lệnh sản xuất {$vNum}";
            $result['lines'][] = [
                'debit_account' => strtoupper($standard) === 'TT133' ? '154' : '621',
                'credit_account' => '152',
                'amount' => 0,
                'description' => $result['description'],
            ];
        }
        // 10. Target là Phân bổ giá thành (CostAllocation)
        elseif ($targetModel instanceof CostAllocation) {
            $result['description'] = 'Nhập kho thành phẩm theo phân bổ giá thành tháng '.($targetModel->month ?? '');
            $result['lines'][] = [
                'debit_account' => '155',
                'credit_account' => '154',
                'amount' => $totalAmount,
                'description' => $result['description'],
            ];
        }

        // Fallback default line if no lines generated
        if (empty($result['lines'])) {
            $debitAcc = $sourceEnum ? $sourceEnum->defaultDebitAccount($standard) : '1111';
            $creditAcc = $sourceEnum ? $sourceEnum->defaultCreditAccount($standard) : '131';
            $result['description'] = $result['description'] ?: "Hạch toán theo {$targetName} {$vNum}";
            $result['lines'][] = [
                'debit_account' => $debitAcc ?: '1111',
                'credit_account' => $creditAcc ?: '131',
                'amount' => $totalAmount,
                'description' => $result['description'],
            ];
        }

        return $result;
    }
}
