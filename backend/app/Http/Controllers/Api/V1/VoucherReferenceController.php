<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountingRegime;
use App\Enums\SystemVoucherType;
use App\Http\Controllers\Controller;
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
use App\Services\AccountingRegimeService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VoucherReferenceController extends Controller
{
    private function textOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function amountOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Tìm kiếm danh sách chứng từ để người dùng chọn làm chứng từ tham chiếu
     */
    public function search(Request $request)
    {
        $searchBy = $request->query('search_by', 'voucher_type'); // voucher_type, contact, voucher_number
        $searchValue = $request->query('search_value', 'Tất cả');
        $moduleGroup = $request->query('module_group'); // all, cash, bank, purchase, sales, inventory, fixed_asset, tool, payroll, gl, other
        $fromDate = $request->query('from_date');
        $toDate = $request->query('to_date');
        $contactId = $request->query('contact_id');
        $keyword = trim($request->query('keyword', ''));
        $companyId = TenantContext::companyId($request);

        $results = collect();

        // 1. Hóa đơn bán hàng (Sales Module)
        if ($this->shouldInclude('Hóa đơn bán hàng', 'sales', $searchBy, $searchValue, $moduleGroup)) {
            $query = SalesInvoice::with(['customer', 'lines'])
                ->where('company_id', $companyId);

            if ($fromDate) {
                $query->whereDate('invoice_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('invoice_date', '<=', $toDate);
            }

            $invoices = $query->orderBy('invoice_date', 'desc')->get();
            foreach ($invoices as $inv) {
                $firstLine = $inv->lines->first();
                $results->push([
                    'id' => 'sales-inv-'.$inv->id,
                    'real_id' => $inv->id,
                    'model' => 'SalesInvoice',
                    'voucher_type' => 'Hóa đơn bán hàng',
                    'posting_date' => $this->dateOrNull($inv->accounting_date) ?? $this->dateOrNull($inv->invoice_date),
                    'voucher_date' => $this->dateOrNull($inv->invoice_date),
                    'voucher_number' => $this->textOrNull($inv->invoice_number),
                    'description' => $this->textOrNull($inv->description),
                    'contact_id' => $inv->customer_id,
                    'contact_code' => $this->textOrNull($inv->customer?->code),
                    'contact_name' => $this->textOrNull($inv->customer_name) ?? $this->textOrNull($inv->customer?->name),
                    'debit_account' => $firstLine?->debit_account,
                    'credit_account' => $firstLine?->credit_account,
                    'total_amount' => $this->amountOrNull($inv->total_amount),
                ]);
            }
        }

        // 2. Hóa đơn mua hàng (Purchase Module)
        if ($this->shouldInclude('Hóa đơn mua hàng', 'purchase', $searchBy, $searchValue, $moduleGroup)) {
            $query = PurchaseInvoice::with(['supplier', 'lines'])
                ->where('company_id', $companyId);

            if ($fromDate) {
                $query->whereDate('invoice_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('invoice_date', '<=', $toDate);
            }

            $invoices = $query->orderBy('invoice_date', 'desc')->get();
            foreach ($invoices as $inv) {
                $firstLine = $inv->lines->first();
                $results->push([
                    'id' => 'purchase-inv-'.$inv->id,
                    'real_id' => $inv->id,
                    'model' => 'PurchaseInvoice',
                    'voucher_type' => 'Hóa đơn mua hàng',
                    'posting_date' => $this->dateOrNull($inv->accounting_date) ?? $this->dateOrNull($inv->invoice_date),
                    'voucher_date' => $this->dateOrNull($inv->invoice_date),
                    'voucher_number' => $this->textOrNull($inv->invoice_number),
                    'description' => $this->textOrNull($inv->description),
                    'contact_id' => $inv->supplier_id,
                    'contact_code' => $this->textOrNull($inv->supplier?->code),
                    'contact_name' => $this->textOrNull($inv->supplier_name) ?? $this->textOrNull($inv->supplier?->name),
                    'debit_account' => $firstLine?->debit_account,
                    'credit_account' => $firstLine?->credit_account,
                    'total_amount' => $this->amountOrNull($inv->total_amount),
                ]);
            }
        }

        // 3. Đơn đặt hàng mua (Purchase Module)
        if ($this->shouldInclude('Đơn mua hàng', 'purchase', $searchBy, $searchValue, $moduleGroup)) {
            $query = PurchaseOrder::with(['supplier'])
                ->where('company_id', $companyId);

            if ($fromDate) {
                $query->whereDate('order_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('order_date', '<=', $toDate);
            }

            $orders = $query->orderBy('order_date', 'desc')->get();
            foreach ($orders as $po) {
                $results->push([
                    'id' => 'po-'.$po->id,
                    'real_id' => $po->id,
                    'model' => 'PurchaseOrder',
                    'voucher_type' => 'Đơn mua hàng',
                    'posting_date' => $this->dateOrNull($po->order_date),
                    'voucher_date' => $this->dateOrNull($po->order_date),
                    'voucher_number' => $this->textOrNull($po->order_number),
                    'description' => $this->textOrNull($po->description) ?? $this->textOrNull($po->note),
                    'contact_id' => $po->supplier_id,
                    'contact_code' => $this->textOrNull($po->supplier?->code),
                    'contact_name' => $this->textOrNull($po->supplier_name) ?? $this->textOrNull($po->supplier?->name),
                    'debit_account' => null,
                    'credit_account' => null,
                    'total_amount' => $this->amountOrNull($po->total_amount),
                ]);
            }
        }

        // 4. Hợp đồng mua (Purchase Module)
        if ($this->shouldInclude('Hợp đồng mua', 'purchase', $searchBy, $searchValue, $moduleGroup)) {
            $query = PurchaseContract::with(['supplier'])
                ->where('company_id', $companyId);

            if ($fromDate) {
                $query->whereDate('signed_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('signed_date', '<=', $toDate);
            }

            $contracts = $query->orderBy('signed_date', 'desc')->get();
            foreach ($contracts as $c) {
                $cDate = $c->signed_date ?? $c->created_at;
                $results->push([
                    'id' => 'contract-'.$c->id,
                    'real_id' => $c->id,
                    'model' => 'PurchaseContract',
                    'voucher_type' => 'Hợp đồng mua',
                    'posting_date' => $this->dateOrNull($cDate),
                    'voucher_date' => $this->dateOrNull($cDate),
                    'voucher_number' => $this->textOrNull($c->contract_number),
                    'description' => $this->textOrNull($c->contract_name),
                    'contact_id' => $c->supplier_id,
                    'contact_code' => $this->textOrNull($c->supplier?->code),
                    'contact_name' => $this->textOrNull($c->supplier_name) ?? $this->textOrNull($c->supplier?->name),
                    'debit_account' => null,
                    'credit_account' => null,
                    'total_amount' => $this->amountOrNull($c->contract_value ?? $c->total_amount),
                ]);
            }
        }

        // 4b. Trả lại hàng mua (Purchase Module)
        if ($this->shouldInclude('Trả lại hàng mua', 'purchase', $searchBy, $searchValue, $moduleGroup) || $this->shouldInclude('Chứng từ trả lại hàng mua', 'purchase', $searchBy, $searchValue, $moduleGroup)) {
            $query = PurchaseReturn::with(['supplier', 'lines'])
                ->where('company_id', $companyId);

            if ($fromDate) {
                $query->whereDate('voucher_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('voucher_date', '<=', $toDate);
            }

            $returns = $query->orderBy('voucher_date', 'desc')->get();
            foreach ($returns as $ret) {
                $firstLine = $ret->lines->first();
                $results->push([
                    'id' => 'purchase-return-'.$ret->id,
                    'real_id' => $ret->id,
                    'model' => 'PurchaseReturn',
                    'voucher_type' => 'Trả lại hàng mua',
                    'posting_date' => $this->dateOrNull($ret->accounting_date) ?? $this->dateOrNull($ret->voucher_date),
                    'voucher_date' => $this->dateOrNull($ret->voucher_date),
                    'voucher_number' => $this->textOrNull($ret->voucher_number),
                    'description' => $this->textOrNull($ret->description) ?? $this->textOrNull($ret->reason),
                    'contact_id' => $ret->supplier_id,
                    'contact_code' => $this->textOrNull($ret->supplier?->code),
                    'contact_name' => $this->textOrNull($ret->supplier_name) ?? $this->textOrNull($ret->supplier?->name),
                    'debit_account' => $firstLine?->debit_account,
                    'credit_account' => $firstLine?->credit_account,
                    'total_amount' => $this->amountOrNull($ret->total_amount),
                ]);
            }
        }

        // 4c. Giảm giá hàng mua (Purchase Module)
        if ($this->shouldInclude('Giảm giá hàng mua', 'purchase', $searchBy, $searchValue, $moduleGroup) || $this->shouldInclude('Chứng từ giảm giá hàng mua', 'purchase', $searchBy, $searchValue, $moduleGroup)) {
            $query = PurchaseDiscount::with(['supplier', 'lines'])
                ->where('company_id', $companyId);

            if ($fromDate) {
                $query->whereDate('voucher_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('voucher_date', '<=', $toDate);
            }

            $discounts = $query->orderBy('voucher_date', 'desc')->get();
            foreach ($discounts as $disc) {
                $firstLine = $disc->lines->first();
                $results->push([
                    'id' => 'purchase-discount-'.$disc->id,
                    'real_id' => $disc->id,
                    'model' => 'PurchaseDiscount',
                    'voucher_type' => 'Giảm giá hàng mua',
                    'posting_date' => $this->dateOrNull($disc->accounting_date) ?? $this->dateOrNull($disc->voucher_date),
                    'voucher_date' => $this->dateOrNull($disc->voucher_date),
                    'voucher_number' => $this->textOrNull($disc->voucher_number),
                    'description' => $this->textOrNull($disc->description) ?? $this->textOrNull($disc->reason),
                    'contact_id' => $disc->supplier_id,
                    'contact_code' => $this->textOrNull($disc->supplier?->code),
                    'contact_name' => $this->textOrNull($disc->supplier_name) ?? $this->textOrNull($disc->supplier?->name),
                    'debit_account' => $firstLine?->debit_account,
                    'credit_account' => $firstLine?->credit_account,
                    'total_amount' => $this->amountOrNull($disc->total_amount),
                ]);
            }
        }

        // 4d. Trả lại hàng bán (Sales Module)
        if ($this->shouldInclude('Trả lại hàng bán', 'sales', $searchBy, $searchValue, $moduleGroup) || $this->shouldInclude('Chứng từ trả lại hàng bán', 'sales', $searchBy, $searchValue, $moduleGroup)) {
            $query = SalesReturn::with(['customer', 'lines'])
                ->where('company_id', $companyId);

            if ($fromDate) {
                $query->whereDate('voucher_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('voucher_date', '<=', $toDate);
            }

            $sReturns = $query->orderBy('voucher_date', 'desc')->get();
            foreach ($sReturns as $sRet) {
                $firstLine = $sRet->lines->first();
                $results->push([
                    'id' => 'sales-return-'.$sRet->id,
                    'real_id' => $sRet->id,
                    'model' => 'SalesReturn',
                    'voucher_type' => 'Trả lại hàng bán',
                    'posting_date' => $this->dateOrNull($sRet->accounting_date) ?? $this->dateOrNull($sRet->voucher_date),
                    'voucher_date' => $this->dateOrNull($sRet->voucher_date),
                    'voucher_number' => $this->textOrNull($sRet->voucher_number),
                    'description' => $this->textOrNull($sRet->description) ?? $this->textOrNull($sRet->reason),
                    'contact_id' => $sRet->customer_id,
                    'contact_code' => $this->textOrNull($sRet->customer?->code),
                    'contact_name' => $this->textOrNull($sRet->customer_name) ?? $this->textOrNull($sRet->customer?->name),
                    'debit_account' => $firstLine?->debit_account,
                    'credit_account' => $firstLine?->credit_account,
                    'total_amount' => $this->amountOrNull($sRet->total_amount),
                ]);
            }
        }

        // 4e. Giảm giá hàng bán (Sales Module)
        if ($this->shouldInclude('Giảm giá hàng bán', 'sales', $searchBy, $searchValue, $moduleGroup) || $this->shouldInclude('Chứng từ giảm giá hàng bán', 'sales', $searchBy, $searchValue, $moduleGroup)) {
            $query = SalesDiscount::with(['customer', 'lines'])
                ->where('company_id', $companyId);

            if ($fromDate) {
                $query->whereDate('voucher_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('voucher_date', '<=', $toDate);
            }

            $sDiscounts = $query->orderBy('voucher_date', 'desc')->get();
            foreach ($sDiscounts as $sDisc) {
                $firstLine = $sDisc->lines->first();
                $results->push([
                    'id' => 'sales-discount-'.$sDisc->id,
                    'real_id' => $sDisc->id,
                    'model' => 'SalesDiscount',
                    'voucher_type' => 'Giảm giá hàng bán',
                    'posting_date' => $this->dateOrNull($sDisc->accounting_date) ?? $this->dateOrNull($sDisc->voucher_date),
                    'voucher_date' => $this->dateOrNull($sDisc->voucher_date),
                    'voucher_number' => $this->textOrNull($sDisc->voucher_number),
                    'description' => $this->textOrNull($sDisc->description) ?? $this->textOrNull($sDisc->reason),
                    'contact_id' => $sDisc->customer_id,
                    'contact_code' => $this->textOrNull($sDisc->customer?->code),
                    'contact_name' => $this->textOrNull($sDisc->customer_name) ?? $this->textOrNull($sDisc->customer?->name),
                    'debit_account' => $firstLine?->debit_account,
                    'credit_account' => $firstLine?->credit_account,
                    'total_amount' => $this->amountOrNull($sDisc->total_amount),
                ]);
            }
        }

        // 5. Phiếu thu tiền mặt (Cash Module)
        if ($this->shouldInclude('Phiếu thu', 'cash', $searchBy, $searchValue, $moduleGroup)) {
            $query = CashReceipt::with(['lines'])
                ->where('company_id', $companyId);

            if ($fromDate) {
                $query->whereDate('voucher_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('voucher_date', '<=', $toDate);
            }

            $receipts = $query->orderBy('voucher_date', 'desc')->get();
            foreach ($receipts as $r) {
                $firstLine = $r->lines->first();
                $results->push([
                    'id' => 'receipt-'.$r->id,
                    'real_id' => $r->id,
                    'model' => 'CashReceipt',
                    'voucher_type' => 'Phiếu thu',
                    'posting_date' => $this->dateOrNull($r->posting_date) ?? $this->dateOrNull($r->voucher_date),
                    'voucher_date' => $this->dateOrNull($r->voucher_date),
                    'voucher_number' => $this->textOrNull($r->voucher_number),
                    'description' => $this->textOrNull($r->reason),
                    'contact_id' => $r->contact_id,
                    'contact_code' => $this->textOrNull($r->contact_id),
                    'contact_name' => $this->textOrNull($r->contact_name) ?? $this->textOrNull($r->payer_name),
                    'debit_account' => $firstLine?->debit_account,
                    'credit_account' => $firstLine?->credit_account,
                    'total_amount' => $this->amountOrNull($r->total_amount),
                ]);
            }
        }

        // 6. Phiếu chi tiền mặt (Cash Module)
        if ($this->shouldInclude('Phiếu chi', 'cash', $searchBy, $searchValue, $moduleGroup)) {
            $query = CashPayment::with(['lines'])
                ->where('company_id', $companyId);

            if ($fromDate) {
                $query->whereDate('voucher_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('voucher_date', '<=', $toDate);
            }

            $payments = $query->orderBy('voucher_date', 'desc')->get();
            foreach ($payments as $p) {
                $firstLine = $p->lines->first();
                $results->push([
                    'id' => 'payment-'.$p->id,
                    'real_id' => $p->id,
                    'model' => 'CashPayment',
                    'voucher_type' => 'Phiếu chi',
                    'posting_date' => $this->dateOrNull($p->posting_date) ?? $this->dateOrNull($p->voucher_date),
                    'voucher_date' => $this->dateOrNull($p->voucher_date),
                    'voucher_number' => $this->textOrNull($p->voucher_number),
                    'description' => $this->textOrNull($p->reason),
                    'contact_id' => $p->contact_id,
                    'contact_code' => $this->textOrNull($p->contact_id),
                    'contact_name' => $this->textOrNull($p->contact_name) ?? $this->textOrNull($p->receiver_name),
                    'debit_account' => $firstLine?->debit_account,
                    'credit_account' => $firstLine?->credit_account,
                    'total_amount' => $this->amountOrNull($p->total_amount),
                ]);
            }
        }

        // 7. Thu tiền gửi / Báo Có (Bank Module)
        if ($this->shouldInclude('Thu tiền gửi', 'bank', $searchBy, $searchValue, $moduleGroup) || $this->shouldInclude('Báo Có', 'bank', $searchBy, $searchValue, $moduleGroup)) {
            $query = BankReceipt::with(['lines'])
                ->where('company_id', $companyId);

            if ($fromDate) {
                $query->whereDate('voucher_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('voucher_date', '<=', $toDate);
            }

            $bankReceipts = $query->orderBy('voucher_date', 'desc')->get();
            foreach ($bankReceipts as $br) {
                $firstLine = $br->lines->first();
                $results->push([
                    'id' => 'bank-receipt-'.$br->id,
                    'real_id' => $br->id,
                    'model' => 'BankReceipt',
                    'voucher_type' => 'Thu tiền gửi',
                    'posting_date' => $this->dateOrNull($br->posting_date) ?? $this->dateOrNull($br->voucher_date),
                    'voucher_date' => $this->dateOrNull($br->voucher_date),
                    'voucher_number' => $this->textOrNull($br->voucher_number),
                    'description' => $this->textOrNull($br->description),
                    'contact_id' => $br->contact_id,
                    'contact_code' => $this->textOrNull($br->contact_id),
                    'contact_name' => $this->textOrNull($br->contact_name) ?? $this->textOrNull($br->payer_name),
                    'debit_account' => $firstLine?->debit_account,
                    'credit_account' => $firstLine?->credit_account,
                    'total_amount' => $this->amountOrNull($br->amount),
                ]);
            }
        }

        // 8. Ủy nhiệm chi / Báo Nợ (Bank Module)
        if ($this->shouldInclude('Ủy nhiệm chi', 'bank', $searchBy, $searchValue, $moduleGroup) || $this->shouldInclude('Báo Nợ', 'bank', $searchBy, $searchValue, $moduleGroup)) {
            $query = BankPayment::with(['lines'])
                ->where('company_id', $companyId);

            if ($fromDate) {
                $query->whereDate('voucher_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('voucher_date', '<=', $toDate);
            }

            $bankPayments = $query->orderBy('voucher_date', 'desc')->get();
            foreach ($bankPayments as $bp) {
                $firstLine = $bp->lines->first();
                $results->push([
                    'id' => 'bank-payment-'.$bp->id,
                    'real_id' => $bp->id,
                    'model' => 'BankPayment',
                    'voucher_type' => 'Ủy nhiệm chi',
                    'posting_date' => $this->dateOrNull($bp->posting_date) ?? $this->dateOrNull($bp->voucher_date),
                    'voucher_date' => $this->dateOrNull($bp->voucher_date),
                    'voucher_number' => $this->textOrNull($bp->voucher_number),
                    'description' => $this->textOrNull($bp->description),
                    'contact_id' => $bp->contact_id,
                    'contact_code' => $this->textOrNull($bp->contact_id),
                    'contact_name' => $this->textOrNull($bp->contact_name) ?? $this->textOrNull($bp->payee_name),
                    'debit_account' => $firstLine?->debit_account,
                    'credit_account' => $firstLine?->credit_account,
                    'total_amount' => $this->amountOrNull($bp->amount),
                ]);
            }
        }

        // 9. Phiếu nhập kho (Inventory Module)
        if ($this->shouldInclude('Phiếu nhập kho', 'inventory', $searchBy, $searchValue, $moduleGroup)) {
            $query = InventoryReceipt::with(['lines'])
                ->where('company_id', $companyId);

            if ($fromDate) {
                $query->whereDate('voucher_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('voucher_date', '<=', $toDate);
            }

            $invReceipts = $query->orderBy('voucher_date', 'desc')->get();
            foreach ($invReceipts as $ir) {
                $firstLine = $ir->lines->first();
                $results->push([
                    'id' => 'inv-receipt-'.$ir->id,
                    'real_id' => $ir->id,
                    'model' => 'InventoryReceipt',
                    'voucher_type' => 'Phiếu nhập kho',
                    'posting_date' => $this->dateOrNull($ir->posting_date) ?? $this->dateOrNull($ir->voucher_date),
                    'voucher_date' => $this->dateOrNull($ir->voucher_date),
                    'voucher_number' => $this->textOrNull($ir->voucher_number),
                    'description' => $this->textOrNull($ir->description),
                    'contact_id' => $ir->contact_id,
                    'contact_code' => $this->textOrNull($ir->contact_id),
                    'contact_name' => $this->textOrNull($ir->contact_name) ?? $this->textOrNull($ir->deliverer_name),
                    'debit_account' => $firstLine?->debit_account,
                    'credit_account' => $firstLine?->credit_account,
                    'total_amount' => $this->amountOrNull($ir->total_amount),
                ]);
            }
        }

        // 10. Phiếu xuất kho (Inventory Module)
        if ($this->shouldInclude('Phiếu xuất kho', 'inventory', $searchBy, $searchValue, $moduleGroup)) {
            $query = InventoryIssue::with(['lines'])
                ->where('company_id', $companyId);

            if ($fromDate) {
                $query->whereDate('voucher_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('voucher_date', '<=', $toDate);
            }

            $invIssues = $query->orderBy('voucher_date', 'desc')->get();
            foreach ($invIssues as $ii) {
                $firstLine = $ii->lines->first();
                $results->push([
                    'id' => 'inv-issue-'.$ii->id,
                    'real_id' => $ii->id,
                    'model' => 'InventoryIssue',
                    'voucher_type' => 'Phiếu xuất kho',
                    'posting_date' => $this->dateOrNull($ii->posting_date) ?? $this->dateOrNull($ii->voucher_date),
                    'voucher_date' => $this->dateOrNull($ii->voucher_date),
                    'voucher_number' => $this->textOrNull($ii->voucher_number),
                    'description' => $this->textOrNull($ii->description),
                    'contact_id' => $ii->contact_id,
                    'contact_code' => $this->textOrNull($ii->contact_id),
                    'contact_name' => $this->textOrNull($ii->contact_name) ?? $this->textOrNull($ii->receiver_name),
                    'debit_account' => $firstLine?->debit_account,
                    'credit_account' => $firstLine?->credit_account,
                    'total_amount' => $this->amountOrNull($ii->total_amount),
                ]);
            }
        }

        // 11. Chứng từ nghiệp vụ khác (GL Module)
        if ($this->shouldInclude('Chứng từ nghiệp vụ khác', 'gl', $searchBy, $searchValue, $moduleGroup)) {
            $query = JournalEntry::with(['lines'])
                ->where('company_id', $companyId);

            if ($fromDate) {
                $query->whereDate('voucher_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('voucher_date', '<=', $toDate);
            }

            $entries = $query->orderBy('voucher_date', 'desc')->get();
            foreach ($entries as $je) {
                $firstDebit = $je->lines->firstWhere('debit_amount', '>', 0);
                $firstCredit = $je->lines->firstWhere('credit_amount', '>', 0);
                $results->push([
                    'id' => 'journal-entry-'.$je->id,
                    'real_id' => $je->id,
                    'model' => 'JournalEntry',
                    'voucher_type' => 'Chứng từ nghiệp vụ khác',
                    'posting_date' => $this->dateOrNull($je->posting_date) ?? $this->dateOrNull($je->voucher_date),
                    'voucher_date' => $this->dateOrNull($je->voucher_date),
                    'voucher_number' => $this->textOrNull($je->voucher_number),
                    'description' => $this->textOrNull($je->description),
                    'contact_id' => null,
                    'contact_code' => null,
                    'contact_name' => null,
                    'debit_account' => $firstDebit?->account_code,
                    'credit_account' => $firstCredit?->account_code,
                    'total_amount' => $this->amountOrNull($je->total_amount),
                ]);
            }
        }

        // 12. Báo giá bán hàng (Sales Module)
        if ($this->shouldInclude('Báo giá', 'sales', $searchBy, $searchValue, $moduleGroup)) {
            $query = SalesQuote::with(['customer', 'lines'])
                ->where('company_id', $companyId);

            if ($fromDate) {
                $query->whereDate('quote_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('quote_date', '<=', $toDate);
            }

            $quotes = $query->orderBy('quote_date', 'desc')->get();
            foreach ($quotes as $q) {
                $results->push([
                    'id' => 'sales-quote-'.$q->id,
                    'real_id' => $q->id,
                    'model' => 'SalesQuote',
                    'voucher_type' => 'Báo giá',
                    'posting_date' => $this->dateOrNull($q->quote_date),
                    'voucher_date' => $this->dateOrNull($q->quote_date),
                    'voucher_number' => $this->textOrNull($q->quote_number),
                    'description' => $this->textOrNull($q->description),
                    'contact_id' => $q->customer_id,
                    'contact_code' => $this->textOrNull($q->customer_code) ?? $this->textOrNull($q->customer?->code),
                    'contact_name' => $this->textOrNull($q->customer_name) ?? $this->textOrNull($q->customer?->name),
                    'debit_account' => null,
                    'credit_account' => null,
                    'total_amount' => $this->amountOrNull($q->total_amount),
                ]);
            }
        }

        // 13. Đơn đặt hàng bán (Sales Module)
        if ($this->shouldInclude('Đơn đặt hàng', 'sales', $searchBy, $searchValue, $moduleGroup)) {
            $query = SalesOrder::with(['customer', 'lines'])
                ->where('company_id', $companyId);

            if ($fromDate) {
                $query->whereDate('order_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('order_date', '<=', $toDate);
            }

            $orders = $query->orderBy('order_date', 'desc')->get();
            foreach ($orders as $so) {
                $results->push([
                    'id' => 'sales-order-'.$so->id,
                    'real_id' => $so->id,
                    'model' => 'SalesOrder',
                    'voucher_type' => 'Đơn đặt hàng',
                    'posting_date' => $this->dateOrNull($so->order_date),
                    'voucher_date' => $this->dateOrNull($so->order_date),
                    'voucher_number' => $this->textOrNull($so->order_number),
                    'description' => $this->textOrNull($so->description),
                    'contact_id' => $so->customer_id,
                    'contact_code' => $this->textOrNull($so->customer_code) ?? $this->textOrNull($so->customer?->code),
                    'contact_name' => $this->textOrNull($so->customer_name) ?? $this->textOrNull($so->customer?->name),
                    'debit_account' => null,
                    'credit_account' => null,
                    'total_amount' => $this->amountOrNull($so->total_amount),
                ]);
            }
        }

        // 14. Tài sản cố định / Ghi tăng TSCĐ (Fixed Assets Module)
        if ($this->shouldInclude('Ghi tăng TSCĐ', 'fixed_asset', $searchBy, $searchValue, $moduleGroup) || $this->shouldInclude('Tài sản cố định', 'fixed_asset', $searchBy, $searchValue, $moduleGroup)) {
            $query = FixedAsset::where('company_id', $companyId);
            if ($fromDate) {
                $query->whereDate('voucher_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('voucher_date', '<=', $toDate);
            }

            $assets = $query->orderBy('id', 'desc')->get();
            foreach ($assets as $fa) {
                $vDate = $fa->voucher_date ?: $fa->purchase_date;
                $results->push([
                    'id' => 'fixed-asset-'.$fa->id,
                    'real_id' => $fa->id,
                    'model' => 'FixedAsset',
                    'voucher_type' => 'Ghi tăng TSCĐ',
                    'posting_date' => $this->dateOrNull($vDate),
                    'voucher_date' => $this->dateOrNull($vDate),
                    'voucher_number' => $this->textOrNull($fa->voucher_number) ?? $this->textOrNull($fa->asset_code),
                    'description' => 'Ghi tăng TSCĐ: '.$fa->asset_name,
                    'contact_id' => '',
                    'contact_code' => '',
                    'contact_name' => '',
                    'debit_account' => $fa->asset_account,
                    'credit_account' => $fa->credit_account,
                    'total_amount' => $this->amountOrNull($fa->original_cost),
                ]);
            }
        }

        // 15. Công cụ dụng cụ / Ghi tăng CCDC (Tools Module)
        if ($this->shouldInclude('Ghi tăng CCDC', 'tool', $searchBy, $searchValue, $moduleGroup) || $this->shouldInclude('Công cụ dụng cụ', 'tool', $searchBy, $searchValue, $moduleGroup)) {
            $query = ToolEquipment::where('company_id', $companyId);
            if ($fromDate) {
                $query->whereDate('purchase_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('purchase_date', '<=', $toDate);
            }

            $tools = $query->orderBy('id', 'desc')->get();
            foreach ($tools as $tool) {
                $vDate = $tool->purchase_date;
                $results->push([
                    'id' => 'tool-equipment-'.$tool->id,
                    'real_id' => $tool->id,
                    'model' => 'ToolEquipment',
                    'voucher_type' => 'Ghi tăng CCDC',
                    'posting_date' => $this->dateOrNull($vDate),
                    'voucher_date' => $this->dateOrNull($vDate),
                    'voucher_number' => $this->textOrNull($tool->tool_code),
                    'description' => 'Ghi tăng CCDC: '.$tool->tool_name,
                    'contact_id' => '',
                    'contact_code' => '',
                    'contact_name' => '',
                    'debit_account' => $tool->tool_account,
                    'credit_account' => null,
                    'total_amount' => $this->amountOrNull($tool->original_cost),
                ]);
            }
        }

        // 16. Tiền lương / Bảng tính lương (Payroll Module)
        if ($this->shouldInclude('Bảng tính lương', 'payroll', $searchBy, $searchValue, $moduleGroup) || $this->shouldInclude('Tiền lương', 'payroll', $searchBy, $searchValue, $moduleGroup)) {
            $query = Payroll::where('company_id', $companyId);
            if ($fromDate) {
                $query->whereDate('voucher_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('voucher_date', '<=', $toDate);
            }

            $payrolls = $query->orderBy('id', 'desc')->get();
            foreach ($payrolls as $pr) {
                $results->push([
                    'id' => 'payroll-'.$pr->id,
                    'real_id' => $pr->id,
                    'model' => 'Payroll',
                    'voucher_type' => 'Bảng tính lương',
                    'posting_date' => $this->dateOrNull($pr->posting_date) ?? $this->dateOrNull($pr->voucher_date),
                    'voucher_date' => $this->dateOrNull($pr->voucher_date),
                    'voucher_number' => $this->textOrNull($pr->voucher_number),
                    'description' => $this->textOrNull($pr->description),
                    'contact_id' => null,
                    'contact_code' => null,
                    'contact_name' => null,
                    'debit_account' => null,
                    'credit_account' => null,
                    'total_amount' => $this->amountOrNull($pr->total_amount),
                ]);
            }
        }

        // 17. Khế ước vay (Other / Borrowing Module)
        if ($this->shouldInclude('Khế ước vay', 'other', $searchBy, $searchValue, $moduleGroup) || $this->shouldInclude('Hợp đồng vay', 'other', $searchBy, $searchValue, $moduleGroup)) {
            $query = BorrowingContract::where('company_id', $companyId);
            if ($fromDate) {
                $query->whereDate('disbursement_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('disbursement_date', '<=', $toDate);
            }

            $contracts = $query->orderBy('id', 'desc')->get();
            foreach ($contracts as $bc) {
                $vDate = $bc->disbursement_date;
                $results->push([
                    'id' => 'borrowing-contract-'.$bc->id,
                    'real_id' => $bc->id,
                    'model' => 'BorrowingContract',
                    'voucher_type' => 'Khế ước vay',
                    'posting_date' => $this->dateOrNull($vDate),
                    'voucher_date' => $this->dateOrNull($vDate),
                    'voucher_number' => $this->textOrNull($bc->contract_number),
                    'description' => $this->textOrNull($bc->purpose),
                    'contact_id' => null,
                    'contact_code' => null,
                    'contact_name' => $this->textOrNull($bc->lender_name),
                    'debit_account' => $bc->debit_account,
                    'credit_account' => null,
                    'total_amount' => $this->amountOrNull($bc->amount),
                ]);
            }
        }

        // 18. Lệnh sản xuất (Inventory / Production Module)
        if ($this->shouldInclude('Lệnh sản xuất', 'inventory', $searchBy, $searchValue, $moduleGroup)) {
            $query = ProductionOrder::with('item')->where('company_id', $companyId);
            if ($fromDate) {
                $query->whereDate('start_date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->whereDate('start_date', '<=', $toDate);
            }

            $orders = $query->orderBy('id', 'desc')->get();
            foreach ($orders as $po) {
                $results->push([
                    'id' => 'prod-order-'.$po->id,
                    'real_id' => $po->id,
                    'model' => 'ProductionOrder',
                    'voucher_type' => 'Lệnh sản xuất',
                    'posting_date' => $this->dateOrNull($po->start_date),
                    'voucher_date' => $this->dateOrNull($po->start_date),
                    'voucher_number' => $this->textOrNull($po->order_number),
                    'description' => null,
                    'contact_id' => null,
                    'contact_code' => null,
                    'contact_name' => null,
                    'debit_account' => null,
                    'credit_account' => null,
                    'total_amount' => null,
                ]);
            }
        }

        // 19. Bút toán phân bổ giá thành (Other / Costing Module)
        if ($this->shouldInclude('Bút toán phân bổ giá thành', 'other', $searchBy, $searchValue, $moduleGroup)) {
            $query = CostAllocation::where('company_id', $companyId);
            $allocations = $query->orderBy('id', 'desc')->get();
            foreach ($allocations as $ca) {
                $dateStr = null;
                if ($ca->month) {
                    try {
                        $dateStr = Carbon::parse($ca->month.'-01')->endOfMonth()->format('Y-m-d');
                    } catch (\Throwable) {
                        $dateStr = null;
                    }
                }
                if ($dateStr !== null && $fromDate && $dateStr < $fromDate) {
                    continue;
                }
                if ($dateStr !== null && $toDate && $dateStr > $toDate) {
                    continue;
                }
                $results->push([
                    'id' => 'cost-alloc-'.$ca->id,
                    'real_id' => $ca->id,
                    'model' => 'CostAllocation',
                    'voucher_type' => 'Bút toán phân bổ giá thành',
                    'posting_date' => $dateStr,
                    'voucher_date' => $dateStr,
                    'voucher_number' => null,
                    'description' => null,
                    'contact_id' => null,
                    'contact_code' => null,
                    'contact_name' => null,
                    'debit_account' => null,
                    'credit_account' => null,
                    'total_amount' => $this->amountOrNull($ca->total_cost),
                ]);
            }
        }

        // Lọc theo contact_id nếu có chỉ định cụ thể
        if (! empty($contactId)) {
            $cIdStr = (string) $contactId;
            $results = $results->filter(function ($item) use ($cIdStr) {
                return (string) ($item['contact_id'] ?? '') === $cIdStr ||
                       (string) ($item['contact_code'] ?? '') === $cIdStr;
            });
        }

        // Lọc theo search_by và search_value khi tìm theo Đối tượng hoặc Số chứng từ
        if ($searchValue !== 'Tất cả' && $searchValue !== '' && $searchValue !== null) {
            $sv = mb_strtolower(trim($searchValue));
            if ($searchBy === 'contact') {
                $results = $results->filter(function ($item) use ($sv) {
                    $code = mb_strtolower($item['contact_code'] ?? '');
                    $name = mb_strtolower($item['contact_name'] ?? '');
                    $cId = mb_strtolower((string) ($item['contact_id'] ?? ''));

                    return str_contains($code, $sv) || str_contains($name, $sv) || str_contains($cId, $sv);
                });
            } elseif ($searchBy === 'voucher_number') {
                $results = $results->filter(function ($item) use ($sv) {
                    $vn = mb_strtolower($item['voucher_number'] ?? '');

                    return str_contains($vn, $sv);
                });
            }
        }

        // Lọc theo từ khóa tìm kiếm (Số chứng từ, Đối tượng, Diễn giải)
        if ($keyword !== '') {
            $kw = mb_strtolower($keyword);
            $results = $results->filter(function ($item) use ($kw) {
                return str_contains(mb_strtolower($item['voucher_number'] ?? ''), $kw) ||
                       str_contains(mb_strtolower($item['contact_name'] ?? ''), $kw) ||
                       str_contains(mb_strtolower($item['contact_code'] ?? ''), $kw) ||
                       str_contains(mb_strtolower($item['description'] ?? ''), $kw);
            });
        }

        return response()->json([
            'success' => true,
            'data' => $results->values(),
            'total' => $results->count(),
        ]);
    }

    /**
     * Tự động điền đối tượng, diễn giải, định khoản Nợ/Có khi kế toán chọn chứng từ tham chiếu
     */
    public function resolveDefaults(Request $request)
    {
        $sourceType = $request->input('source_voucher_type') ?? $request->input('source_type');
        $targetType = $request->input('target_voucher_type') ?? $request->input('target_type') ?? $request->input('model');
        $targetId = $request->input('target_voucher_id') ?? $request->input('target_id') ?? $request->input('real_id') ?? $request->input('id');
        $companyId = TenantContext::companyId($request);
        $standard = $request->input('standard');
        $hasRegimeContext = $request->filled('fiscal_year_id') || $request->filled('posting_date');
        $isProduction = in_array(strtolower((string) config('app.env', 'production')), ['production', 'prod'], true);
        if (! $hasRegimeContext && $isProduction) {
            throw ValidationException::withMessages([
                'fiscal_year_id' => 'Phải cung cấp năm tài chính hoặc ngày hạch toán để xác định chế độ kế toán; hệ thống không tự chọn TT99.',
            ]);
        }

        if ($hasRegimeContext) {
            $metadata = app(AccountingRegimeService::class)->reportMetadata(
                $companyId,
                $request->input('posting_date'),
                $request->input('posting_date'),
                $request->integer('fiscal_year_id') ?: null
            );
            $resolvedStandard = $metadata['accounting_regime'];
            if ($standard !== null && strtoupper((string) $standard) !== $resolvedStandard) {
                throw ValidationException::withMessages([
                    'standard' => "Chế độ kế toán phải là {$resolvedStandard} theo năm tài chính đã chọn.",
                ]);
            }
            $standard = $resolvedStandard;
        }
        $standard ??= AccountingRegime::TT99->value;

        $resolved = SystemVoucherType::resolveCrossVoucherDefaults(
            $sourceType,
            $targetType,
            $targetId,
            $standard
        );

        return response()->json([
            'success' => true,
            'data' => $resolved,
            'meta' => ['accounting_regime' => $standard],
        ]);
    }

    /**
     * Kiểm tra điều kiện chứng từ có phù hợp với bộ lọc không
     */
    private function shouldInclude(string $type, string $module, string $searchBy, ?string $searchValue, ?string $moduleGroup = null): bool
    {
        if (! empty($moduleGroup) && $moduleGroup !== 'all') {
            if ($module !== $moduleGroup) {
                return false;
            }
        }

        if ($searchBy !== 'voucher_type') {
            return true;
        }
        if ($searchValue === 'Tất cả' || empty($searchValue)) {
            return true;
        }

        $searchValue = trim($searchValue);

        // Hỗ trợ chọn theo tên nhóm chứng từ (Optgroup header)
        if ($searchValue === 'Bán hàng' || $searchValue === 'sales') {
            return in_array($type, ['Hóa đơn bán hàng', 'Chứng từ bán hàng', 'Báo giá', 'Đơn đặt hàng', 'Hợp đồng bán', 'Chứng từ giảm giá hàng bán', 'Chứng từ trả lại hàng bán']);
        }
        if ($searchValue === 'Mua hàng' || $searchValue === 'purchase') {
            return in_array($type, ['Hóa đơn mua hàng', 'Chứng từ mua dịch vụ', 'Đơn mua hàng', 'Hợp đồng mua', 'Chứng từ giảm giá hàng mua', 'Chứng từ trả lại hàng mua']);
        }
        if ($searchValue === 'Quỹ' || $searchValue === 'Quỹ (Tiền mặt)' || $searchValue === 'cash') {
            return in_array($type, ['Phiếu thu', 'Phiếu chi']);
        }
        if ($searchValue === 'Ngân hàng' || $searchValue === 'bank') {
            return in_array($type, ['Thu tiền gửi', 'Báo Có', 'Ủy nhiệm chi', 'Báo Nợ', 'Séc chuyển khoản', 'Séc tiền mặt', 'Bảng kê nộp séc']);
        }
        if ($searchValue === 'Kho' || $searchValue === 'inventory') {
            return in_array($type, ['Phiếu nhập kho', 'Phiếu xuất kho', 'Phiếu chuyển kho', 'Lệnh sản xuất']);
        }
        if ($searchValue === 'Tài sản cố định' || $searchValue === 'fixed_asset') {
            return in_array($type, ['Ghi tăng TSCĐ', 'Ghi giảm TSCĐ', 'Khấu hao TSCĐ', 'Tài sản cố định']);
        }
        if ($searchValue === 'Công cụ dụng cụ' || $searchValue === 'tool') {
            return in_array($type, ['Ghi tăng CCDC', 'Ghi giảm CCDC', 'Phân bổ CCDC', 'Công cụ dụng cụ']);
        }
        if ($searchValue === 'Tiền lương' || $searchValue === 'payroll') {
            return in_array($type, ['Bảng tính lương', 'Tiền lương']);
        }
        if ($searchValue === 'Tổng hợp' || $searchValue === 'gl') {
            return in_array($type, ['Chứng từ nghiệp vụ khác', 'Kết chuyển lãi lỗ']);
        }
        if ($searchValue === 'Thuế & Khác' || $searchValue === 'other' || $searchValue === 'Vay & Khác' || $searchValue === 'Giá thành') {
            return in_array($type, ['Khế ước vay', 'Bút toán phân bổ giá thành', 'Lệnh sản xuất', 'Tờ khai thuế GTGT']);
        }

        $svLower = mb_strtolower($searchValue);
        $typeLower = mb_strtolower($type);

        $aliases = [
            'tài sản cố định' => 'tscđ',
            'công cụ dụng cụ' => 'ccdc',
            'bảng tính khấu hao' => 'khấu hao',
        ];
        foreach ($aliases as $k => $v) {
            $svLower = str_replace($k, $v, $svLower);
            $typeLower = str_replace($k, $v, $typeLower);
        }

        return str_contains($svLower, $typeLower) || str_contains($typeLower, $svLower);
    }
}
