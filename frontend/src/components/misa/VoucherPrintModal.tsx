import React from 'react';
import { Button } from 'antd';
import Modal from '../layout/AppModal';
import { PrinterOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { readMoneyToVietnameseWords } from '../../utils/numberToWords';
import { printElementSafely } from '../../utils/safePrint';

const VOUCHER_PRINT_STYLES = `
    body { font-family: "Times New Roman", Times, serif; font-size: 13px; line-height: 1.35; padding: 10px; }
    .header-table { width: 100%; margin-bottom: 12px; border-collapse: collapse; }
    .header-table td { vertical-align: top; }
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .bold { font-weight: bold; }
    .italic { font-style: italic; }
    .title { font-size: 18px; font-weight: bold; margin-top: 8px; margin-bottom: 4px; }
    .voucher-info-row { margin-bottom: 6px; }
    .dots { border-bottom: 1px dotted #555; flex-grow: 1; margin-left: 6px; }
    .signatures-table { width: 100%; margin-top: 24px; border-collapse: collapse; text-align: center; }
    .signatures-table td { vertical-align: top; padding: 4px; }
    .sign-space { height: 65px; }
    .accounting-table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    .accounting-table th, .accounting-table td { border: 1px solid #333; padding: 5px 6px; font-size: 13px; }
    .accounting-table th { background-color: #f2f2f2; font-weight: bold; text-align: center; }
`;

export interface VoucherPrintLine {
    description?: string;
    item_code?: string;
    item_name?: string;
    unit?: string;
    warehouse_code?: string;
    warehouse_name?: string;
    quantity?: number;
    unit_price?: number;
    amount?: number;
    discount_rate?: number;
    discount_amount?: number;
    tax_rate?: number;
    tax_amount?: number;
    total_amount?: number;
    debit_account?: string;
    credit_account?: string;
    account_code?: string;
    debit_amount?: number;
    credit_amount?: number;
    contact_name?: string;
    cost_item_code?: string;
    cost_object_code?: string;
    cogs_amount?: number;
    cogs_price?: number;
}

export interface VoucherPrintData {
    company_name?: string;
    company_address?: string;
    company_tax_code?: string;
    company_phone?: string;
    voucher_number?: string;
    voucher_date?: string | Date;
    posting_date?: string | Date;
    order_number?: string;
    quote_number?: string;
    order_date?: string | Date;
    quote_date?: string | Date;
    delivery_date?: string | Date;
    expiry_date?: string | Date;
    valid_until?: string | Date;
    invoice_number?: string;
    invoice_code?: string;
    invoice_symbol?: string;
    invoice_date?: string | Date;
    contact_name?: string;
    contact_person?: string;
    payer_name?: string;
    receiver_name?: string;
    supplier_name?: string;
    customer_name?: string;
    address?: string;
    payer_address?: string;
    receiver_address?: string;
    supplier_address?: string;
    customer_address?: string;
    delivery_address?: string;
    tax_code?: string;
    supplier_tax_code?: string;
    customer_tax_code?: string;
    reason?: string;
    description?: string;
    sub_total?: number;
    discount_amount?: number;
    vat_amount?: number;
    tax_amount?: number;
    total_amount?: number;
    grand_total?: number;
    amount_in_words?: string;
    currency?: string;
    attached_docs?: string | number;
    warehouse_name?: string;
    warehouse_code?: string;
    payment_method?: string;
    payment_term?: string;
    lines?: VoucherPrintLine[];
    items?: VoucherPrintLine[];
}

export type VoucherPrintType =
    | 'receipt'
    | 'payment'
    | 'general'
    | 'journal'
    | 'general_journal'
    | 'closing'
    | 'inventory_receipt'
    | 'purchase_inward'
    | '01-VT'
    | 'purchase_order'
    | 'PO'
    | 'sales_invoice'
    | 'sales_invoice_delivery'
    | '01-BH'
    | 'inventory_issue'
    | 'sales_export'
    | '02-VT'
    | 'sales_quote'
    | 'sales_order';

export interface VoucherPrintModalProps {
    open: boolean;
    onClose?: () => void;
    onCancel?: () => void;
    type?: VoucherPrintType | string;
    data?: VoucherPrintData;
    voucher?: VoucherPrintData;
}

export const VoucherPrintModal: React.FC<VoucherPrintModalProps> = ({
    open,
    onClose,
    onCancel,
    type = 'receipt',
    data,
    voucher
}) => {
    const handleClose = onCancel || onClose || (() => {});
    const printData = voucher || data || {};

    // Normalize lines
    const rawLines: VoucherPrintLine[] = (printData.lines && printData.lines.length > 0)
        ? printData.lines
        : (printData.items && printData.items.length > 0 ? printData.items : []);

    // Categorize Template Type
    const isJournal = ['general', 'journal', 'general_journal', 'closing'].includes(type);
    const isReceipt = type === 'receipt';
    const isPayment = type === 'payment';
    const isInventoryReceipt = ['inventory_receipt', 'purchase_inward', '01-VT'].includes(type);
    const isInventoryIssue = ['inventory_issue', 'sales_export', '02-VT'].includes(type);
    const isPurchaseOrder = ['purchase_order', 'PO'].includes(type);
    const isSalesInvoice = ['sales_invoice', 'sales_invoice_delivery', '01-BH'].includes(type);
    const isSalesQuote = type === 'sales_quote';
    const isSalesOrder = type === 'sales_order';

    let title = 'CHỨNG TỪ KẾ TOÁN';
    let formCode = '01 - TT';
    let circular = 'Ban hành theo TT số 200/2014/TT-BTC ngày 22/12/2014 của BTC';

    if (isReceipt) {
        title = 'PHIẾU THU';
        formCode = '01 - TT';
    } else if (isPayment) {
        title = 'PHIẾU CHI';
        formCode = '02 - TT';
    } else if (isJournal) {
        title = 'PHIẾU KẾ TOÁN';
        formCode = '01 - PKT';
    } else if (isInventoryReceipt) {
        title = 'PHIẾU NHẬP KHO';
        formCode = '01 - VT';
    } else if (isInventoryIssue) {
        title = 'PHIẾU XUẤT KHO';
        formCode = '02 - VT';
    } else if (isPurchaseOrder) {
        title = 'ĐƠN MUA HÀNG (PURCHASE ORDER)';
        formCode = 'MẪU PO-01';
        circular = 'Ban hành theo chuẩn quản trị MISA AMIS';
    } else if (isSalesInvoice) {
        title = 'HÓA ĐƠN BÁN HÀNG KIÊM PHIẾU XUẤT KHO';
        formCode = '01 - BH';
        circular = 'Mẫu số 01GKTĐ/001 theo Nghị định 123/2020/NĐ-CP & TT 200';
    } else if (isSalesQuote) {
        title = 'BẢNG BÁO GIÁ (SALES QUOTATION)';
        formCode = 'MẪU BG-01';
        circular = 'Ban hành theo chuẩn quản trị MISA AMIS';
    } else if (isSalesOrder) {
        title = 'ĐƠN ĐẶT HÀNG (SALES ORDER)';
        formCode = 'MẪU DH-01';
        circular = 'Ban hành theo chuẩn quản trị MISA AMIS';
    }

    const voucherDateValue = printData.voucher_date || printData.invoice_date || printData.order_date || printData.quote_date;
    const voucherDate = voucherDateValue ? dayjs(voucherDateValue) : null;
    const day = voucherDate?.format('DD') ?? '—';
    const month = voucherDate?.format('MM') ?? '—';
    const year = voucherDate?.format('YYYY') ?? '—';

    const derivedTotal = rawLines.some((l) => l.amount != null || l.total_amount != null || (l.quantity != null && l.unit_price != null))
        ? rawLines.reduce((s, l) => s + Number(l.amount ?? l.total_amount ?? (l.quantity != null && l.unit_price != null ? Number(l.quantity) * Number(l.unit_price) : 0)) + Number(l.tax_amount ?? 0), 0)
        : null;
    const totalAmountValue = printData.total_amount ?? printData.grand_total ?? derivedTotal;
    const totalAmount = totalAmountValue == null ? 0 : Number(totalAmountValue);
    const hasAmountEvidence = totalAmountValue != null;
    const amountDisplay = hasAmountEvidence ? Number(totalAmount).toLocaleString('vi-VN') : '—';

    const derivedSubTotal = rawLines.some((l) => l.amount != null || (l.quantity != null && l.unit_price != null))
        ? rawLines.reduce((s, l) => s + Number(l.amount ?? (l.quantity != null && l.unit_price != null ? Number(l.quantity) * Number(l.unit_price) : 0)), 0)
        : null;
    const subTotalValue = printData.sub_total ?? derivedSubTotal;
    const subTotal = subTotalValue == null ? 0 : Number(subTotalValue);
    const hasSubTotalEvidence = subTotalValue != null;
    const subTotalDisplay = hasSubTotalEvidence ? Number(subTotal).toLocaleString('vi-VN') : '—';

    const vatAmount = Number(
        printData.vat_amount ??
        printData.tax_amount ??
        rawLines.reduce((s, l) => s + Number(l.tax_amount || 0), 0)
    ) || 0;
    const hasVatEvidence = printData.vat_amount != null || printData.tax_amount != null || rawLines.some((line) => line.tax_amount != null);
    const vatAmountDisplay = hasVatEvidence ? Number(vatAmount).toLocaleString('vi-VN') : '—';

    const amountInWords = printData.amount_in_words ?? (hasAmountEvidence ? readMoneyToVietnameseWords(totalAmount) : '—');

    const debitAccounts = Array.from(new Set(rawLines.map(l => l.debit_account).filter(Boolean))).join(', ') || '—';
    const creditAccounts = Array.from(new Set(rawLines.map(l => l.credit_account).filter(Boolean))).join(', ') || '—';

    const contactName = printData.contact_name || printData.customer_name || printData.supplier_name || printData.payer_name || printData.receiver_name || '—';
    const address = printData.address || printData.customer_address || printData.supplier_address || printData.payer_address || printData.receiver_address || printData.delivery_address || '—';
    const taxCode = printData.tax_code || printData.customer_tax_code || printData.supplier_tax_code || null;
    const voucherNo = printData.voucher_number || printData.invoice_number || printData.order_number || printData.quote_number || '—';

    const handlePrint = () => {
        const printContent = document.getElementById('misa-printable-voucher');
        if (!printContent) return;

        printElementSafely(printContent, `${title} - ${voucherNo}`, VOUCHER_PRINT_STYLES);
    };

    return (
        <Modal
            open={open}
            onCancel={handleClose}
            width={860}
            centered={true}
            className="misa-clean-modal"
            zIndex={2500}
            title={
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <PrinterOutlined style={{ color: '#0070c0', fontSize: 18 }} />
                    <span>Xem trước bản in: {title} ({voucherNo})</span>
                </div>
            }
            footer={[
                <Button key="close" onClick={handleClose}>Đóng</Button>,
                <Button key="print" type="primary" icon={<PrinterOutlined />} onClick={handlePrint} style={{ background: '#0070c0' }}>
                    In chứng từ
                </Button>
            ]}
        >
            <div
                id="misa-printable-voucher"
                style={{
                    fontFamily: '"Times New Roman", Times, serif',
                    fontSize: '13px',
                    color: '#000',
                    padding: '20px',
                    background: '#fff',
                    border: '1px solid #e0e0e0',
                    borderRadius: '4px'
                }}
            >
                {/* Header */}
                <table style={{ width: '100%', marginBottom: 10, borderCollapse: 'collapse' }}>
                    <tbody>
                        <tr>
                            <td style={{ width: '60%', verticalAlign: 'top' }}>
                                <div style={{ fontWeight: 'bold', fontSize: 14 }}>{printData.company_name ?? '—'}</div>
                                <div style={{ fontSize: 12, color: '#333' }}>{printData.company_address ?? '—'}</div>
                                <div style={{ fontSize: 12, color: '#333' }}>MST: {printData.company_tax_code ?? '—'} | ĐT: {printData.company_phone ?? '—'}</div>
                            </td>
                            <td style={{ width: '40%', verticalAlign: 'top', textAlign: 'center' }}>
                                <div style={{ fontWeight: 'bold', fontSize: 13 }}>Mẫu số: {formCode}</div>
                                <div style={{ fontSize: 11, fontStyle: 'italic', color: '#555' }}>({circular})</div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                {/* Title & Voucher Number */}
                <div style={{ textAlign: 'center', marginBottom: 12 }}>
                    <div style={{ fontSize: '19px', fontWeight: 'bold', textTransform: 'uppercase', letterSpacing: '1px' }}>
                        {title}
                    </div>
                    <div style={{ fontStyle: 'italic', fontSize: '13px', margin: '2px 0' }}>
                        Ngày {day} tháng {month} năm {year}
                    </div>
                    <table style={{ width: '100%', marginTop: 4 }}>
                        <tbody>
                            <tr>
                                <td style={{ width: '33%', textAlign: 'left', fontStyle: 'italic', fontSize: 12 }}>
                                    {isJournal ? `Ngày HT: ${printData.posting_date ? dayjs(printData.posting_date).format('DD/MM/YYYY') : `${day}/${month}/${year}`}` : (printData.invoice_symbol ? `Ký hiệu: ${printData.invoice_symbol}` : 'Quyển số: ..............')}
                                </td>
                                <td style={{ width: '34%', textAlign: 'center', fontWeight: 'bold', fontSize: 14 }}>
                                    Số: {voucherNo}
                                </td>
                                <td style={{ width: '33%', textAlign: 'right', fontSize: 12 }}>
                                    {!isJournal && !isPurchaseOrder && !isSalesQuote && !isSalesOrder ? (
                                        <>
                                            <div>Nợ: <strong>{debitAccounts}</strong></div>
                                            <div>Có: <strong>{creditAccounts}</strong></div>
                                        </>
                                    ) : null}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                {/* Body for Item-based Vouchers (01-VT, 02-VT, 01-BH, PO, Sales Quote, Sales Order) */}
                {(isInventoryReceipt || isInventoryIssue || isSalesInvoice || isPurchaseOrder || isSalesQuote || isSalesOrder) ? (
                    <div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginBottom: 12, fontSize: 13 }}>
                            <div style={{ display: 'flex' }}>
                                <span style={{ width: 170, flexShrink: 0 }}>
                                    {isInventoryReceipt ? 'Họ tên người giao hàng:' : (isInventoryIssue ? 'Họ tên người nhận hàng:' : (isPurchaseOrder ? 'Nhà cung cấp:' : 'Khách hàng / Đơn vị:'))}
                                </span>
                                <strong style={{ flexGrow: 1, borderBottom: '1px dotted #999', paddingBottom: 1 }}>
                                    {contactName}
                                </strong>
                            </div>

                            <div style={{ display: 'flex' }}>
                                <span style={{ width: 170, flexShrink: 0 }}>Địa chỉ:</span>
                                <span style={{ flexGrow: 1, borderBottom: '1px dotted #999', paddingBottom: 1 }}>
                                    {address}
                                </span>
                            </div>

                            <div style={{ display: 'flex' }}>
                                <span style={{ width: 170, flexShrink: 0 }}>
                                    {isInventoryReceipt ? 'Nhập tại kho:' : (isInventoryIssue ? 'Xuất tại kho:' : (isPurchaseOrder ? 'Địa điểm giao hàng:' : 'Lý do / Diễn giải:'))}
                                </span>
                                <span style={{ flexGrow: 1, borderBottom: '1px dotted #999', paddingBottom: 1 }}>
                                    {isInventoryReceipt || isInventoryIssue ? (printData.warehouse_name ?? '—') : (printData.description ?? printData.reason ?? '—')}
                                </span>
                            </div>

                            {taxCode && (
                                <div style={{ display: 'flex' }}>
                                    <span style={{ width: 170, flexShrink: 0 }}>Mã số thuế:</span>
                                    <span style={{ flexGrow: 1, borderBottom: '1px dotted #999', paddingBottom: 1, fontWeight: 600 }}>
                                        {taxCode}
                                    </span>
                                </div>
                            )}
                        </div>

                        {/* Items Table */}
                        <table style={{ width: '100%', borderCollapse: 'collapse', marginTop: 8, marginBottom: 8 }}>
                            <thead>
                                <tr style={{ background: '#f5f5f5', textAlign: 'center' }}>
                                    <th style={{ border: '1px solid #333', padding: '5px 4px', width: 32 }}>STT</th>
                                    <th style={{ border: '1px solid #333', padding: '5px 6px', width: 85 }}>Mã hàng</th>
                                    <th style={{ border: '1px solid #333', padding: '5px 8px' }}>Tên nhãn hiệu, quy cách hàng hóa</th>
                                    <th style={{ border: '1px solid #333', padding: '5px 4px', width: 50 }}>ĐVT</th>
                                    <th style={{ border: '1px solid #333', padding: '5px 4px', width: 60 }}>Số lượng</th>
                                    <th style={{ border: '1px solid #333', padding: '5px 6px', width: 90, textAlign: 'right' }}>Đơn giá</th>
                                    <th style={{ border: '1px solid #333', padding: '5px 8px', width: 105, textAlign: 'right' }}>Thành tiền</th>
                                    {isSalesInvoice && (
                                        <th style={{ border: '1px solid #333', padding: '5px 4px', width: 75, textAlign: 'right' }}>Thuế GTGT</th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {rawLines.length > 0 ? (
                                    rawLines.map((l, idx) => {
                                        const qty = l.quantity == null ? null : Number(l.quantity);
                                        const price = l.unit_price == null ? null : Number(l.unit_price);
                                        const hasLineAmount = l.amount != null || (qty != null && price != null);
                                        const amt = l.amount != null ? Number(l.amount) : (qty != null && price != null ? qty * price : null);
                                        const tax = l.tax_amount == null ? null : Number(l.tax_amount);
                                        return (
                                            <tr key={idx}>
                                                <td style={{ border: '1px solid #333', padding: '4px 3px', textAlign: 'center' }}>{idx + 1}</td>
                                                <td style={{ border: '1px solid #333', padding: '4px 4px', textAlign: 'center', fontSize: 12 }}>{l.item_code || '—'}</td>
                                                <td style={{ border: '1px solid #333', padding: '4px 6px' }}>{l.item_name || l.description || '—'}</td>
                                                <td style={{ border: '1px solid #333', padding: '4px 3px', textAlign: 'center' }}>{l.unit || '—'}</td>
                                                <td style={{ border: '1px solid #333', padding: '4px 4px', textAlign: 'center', fontWeight: 600 }}>{qty == null ? '—' : qty.toLocaleString('vi-VN')}</td>
                                                <td style={{ border: '1px solid #333', padding: '4px 6px', textAlign: 'right' }}>{price == null ? '—' : price.toLocaleString('vi-VN')}</td>
                                                <td style={{ border: '1px solid #333', padding: '4px 6px', textAlign: 'right', fontWeight: 600 }}>{hasLineAmount && amt != null ? amt.toLocaleString('vi-VN') : '—'}</td>
                                                {isSalesInvoice && (
                                                    <td style={{ border: '1px solid #333', padding: '4px 6px', textAlign: 'right' }}>{tax == null ? '—' : tax.toLocaleString('vi-VN')}</td>
                                                )}
                                            </tr>
                                        );
                                    })
                                ) : (
                                    <tr>
                                        <td colSpan={isSalesInvoice ? 8 : 7} style={{ border: '1px solid #333', padding: '10px', textAlign: 'center', fontStyle: 'italic' }}>
                                            Chưa có chi tiết mặt hàng
                                        </td>
                                    </tr>
                                )}
                                <tr style={{ fontWeight: 'bold', background: '#fafafa' }}>
                                    <td colSpan={6} style={{ border: '1px solid #333', padding: '5px 8px', textAlign: 'center' }}>
                                        Tổng cộng tiền hàng
                                    </td>
                                    <td style={{ border: '1px solid #333', padding: '5px 6px', textAlign: 'right', color: '#1677ff', fontSize: 13 }}>
                                        {subTotalDisplay}
                                    </td>
                                    {isSalesInvoice && (
                                        <td style={{ border: '1px solid #333', padding: '5px 6px', textAlign: 'right', color: '#1677ff', fontSize: 13 }}>
                                            {vatAmountDisplay}
                                        </td>
                                    )}
                                </tr>
                                {(isSalesInvoice || isPurchaseOrder || vatAmount > 0) && (
                                    <tr style={{ fontWeight: 'bold', background: '#f0f5ff' }}>
                                        <td colSpan={isSalesInvoice ? 6 : 6} style={{ border: '1px solid #333', padding: '5px 8px', textAlign: 'right' }}>
                                            Tổng thanh toán (đã gồm VAT):
                                        </td>
                                        <td colSpan={isSalesInvoice ? 2 : 1} style={{ border: '1px solid #333', padding: '5px 6px', textAlign: 'right', color: '#d4380d', fontSize: 14 }}>
                                            {amountDisplay} {printData.currency ?? '—'}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>

                        <div style={{ display: 'flex', flexDirection: 'column', gap: 4, marginTop: 6, fontSize: 13 }}>
                            <div style={{ display: 'flex' }}>
                                <span style={{ width: 150, flexShrink: 0 }}>Tổng số tiền (bằng chữ):</span>
                                <span style={{ flexGrow: 1, borderBottom: '1px dotted #999', paddingBottom: 1, fontStyle: 'italic', fontWeight: 600 }}>
                                    {amountInWords}
                                </span>
                            </div>
                        </div>

                        {/* Signatures for Inventory & Sales */}
                        <table style={{ width: '100%', marginTop: 18, borderCollapse: 'collapse', textAlign: 'center' }}>
                            <tbody>
                                <tr style={{ fontWeight: 'bold' }}>
                                    {isInventoryReceipt ? (
                                        <>
                                            <td style={{ width: '25%' }}>Người lập phiếu</td>
                                            <td style={{ width: '25%' }}>Người giao hàng</td>
                                            <td style={{ width: '25%' }}>Thủ kho</td>
                                            <td style={{ width: '25%' }}>Kế toán trưởng</td>
                                        </>
                                    ) : isInventoryIssue ? (
                                        <>
                                            <td style={{ width: '25%' }}>Người lập phiếu</td>
                                            <td style={{ width: '25%' }}>Người nhận hàng</td>
                                            <td style={{ width: '25%' }}>Thủ kho</td>
                                            <td style={{ width: '25%' }}>Kế toán trưởng</td>
                                        </>
                                    ) : isSalesInvoice ? (
                                        <>
                                            <td style={{ width: '25%' }}>Người mua hàng</td>
                                            <td style={{ width: '25%' }}>Người bán hàng</td>
                                            <td style={{ width: '25%' }}>Thủ kho</td>
                                            <td style={{ width: '25%' }}>Thủ trưởng đơn vị</td>
                                        </>
                                    ) : (
                                        <>
                                            <td style={{ width: '25%' }}>Người lập biểu</td>
                                            <td style={{ width: '25%' }}>Phụ trách bộ phận</td>
                                            <td style={{ width: '25%' }}>Kế toán trưởng</td>
                                            <td style={{ width: '25%' }}>Giám đốc duyệt</td>
                                        </>
                                    )}
                                </tr>
                                <tr style={{ fontSize: 11, fontStyle: 'italic', color: '#666' }}>
                                    <td>(Ký, họ tên)</td>
                                    <td>(Ký, họ tên)</td>
                                    <td>(Ký, họ tên)</td>
                                    <td>(Ký, họ tên, đóng dấu)</td>
                                </tr>
                                <tr>
                                    <td style={{ height: 60 }}></td>
                                    <td style={{ height: 60 }}></td>
                                    <td style={{ height: 60 }}></td>
                                    <td style={{ height: 60 }}></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                ) : isJournal ? (
                    /* Journal Entry (Phiếu kế toán) specific body */
                    <div>
                        <div style={{ marginBottom: 8, display: 'flex' }}>
                            <span style={{ width: 100, flexShrink: 0, fontWeight: 'bold' }}>Diễn giải:</span>
                            <span style={{ flexGrow: 1, borderBottom: '1px dotted #999', paddingBottom: 2 }}>
                                {printData.description || printData.reason || '—'}
                            </span>
                        </div>

                        {/* Accounting Grid */}
                        <table style={{ width: '100%', borderCollapse: 'collapse', marginTop: 8, marginBottom: 8 }}>
                            <thead>
                                <tr style={{ background: '#f5f5f5', textAlign: 'center' }}>
                                    <th style={{ border: '1px solid #333', padding: '5px 4px', width: 35 }}>STT</th>
                                    <th style={{ border: '1px solid #333', padding: '5px 8px' }}>Diễn giải</th>
                                    <th style={{ border: '1px solid #333', padding: '5px 4px', width: 70 }}>TK Nợ</th>
                                    <th style={{ border: '1px solid #333', padding: '5px 4px', width: 70 }}>TK Có</th>
                                    <th style={{ border: '1px solid #333', padding: '5px 8px', width: 130, textAlign: 'right' }}>Số tiền</th>
                                    <th style={{ border: '1px solid #333', padding: '5px 8px', width: 140 }}>Đối tượng</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rawLines.length > 0 ? (
                                    rawLines.map((l, idx) => (
                                        <tr key={idx}>
                                            <td style={{ border: '1px solid #333', padding: '4px 4px', textAlign: 'center' }}>{idx + 1}</td>
                                            <td style={{ border: '1px solid #333', padding: '4px 8px' }}>{l.description || printData.description || '-'}</td>
                                            <td style={{ border: '1px solid #333', padding: '4px 4px', textAlign: 'center', fontWeight: 'bold' }}>
                                                {l.debit_account || (l.debit_amount ? l.account_code : '')}
                                            </td>
                                            <td style={{ border: '1px solid #333', padding: '4px 4px', textAlign: 'center', fontWeight: 'bold' }}>
                                                {l.credit_account || (l.credit_amount ? l.account_code : '')}
                                            </td>
                                            <td style={{ border: '1px solid #333', padding: '4px 8px', textAlign: 'right', fontWeight: 600 }}>
                                                {l.amount != null || l.debit_amount != null || l.credit_amount != null
                                                    ? Number(l.amount ?? l.debit_amount ?? l.credit_amount).toLocaleString('vi-VN')
                                                    : '—'}
                                            </td>
                                            <td style={{ border: '1px solid #333', padding: '4px 8px', fontSize: 12 }}>{l.contact_name || '-'}</td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan={6} style={{ border: '1px solid #333', padding: '10px', textAlign: 'center', fontStyle: 'italic' }}>
                                            Chưa có dòng hạch toán
                                        </td>
                                    </tr>
                                )}
                                <tr style={{ fontWeight: 'bold', background: '#fafafa' }}>
                                    <td colSpan={4} style={{ border: '1px solid #333', padding: '5px 8px', textAlign: 'center' }}>
                                        Tổng cộng
                                    </td>
                                    <td style={{ border: '1px solid #333', padding: '5px 8px', textAlign: 'right', color: '#1677ff', fontSize: 14 }}>
                                        {amountDisplay}
                                    </td>
                                    <td style={{ border: '1px solid #333', padding: '5px 8px' }}></td>
                                </tr>
                            </tbody>
                        </table>

                        <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                            <div style={{ display: 'flex' }}>
                                <span style={{ width: 140, flexShrink: 0 }}>Số tiền viết bằng chữ:</span>
                                <span style={{ flexGrow: 1, borderBottom: '1px dotted #999', paddingBottom: 2, fontStyle: 'italic', fontWeight: 600 }}>
                                    {amountInWords}
                                </span>
                            </div>
                        </div>

                        {/* Signatures for PKT */}
                        <table style={{ width: '100%', marginTop: 16, borderCollapse: 'collapse', textAlign: 'center' }}>
                            <tbody>
                                <tr style={{ fontWeight: 'bold' }}>
                                    <td style={{ width: '33%' }}>Người lập biểu</td>
                                    <td style={{ width: '33%' }}>Kế toán trưởng</td>
                                    <td style={{ width: '34%' }}>Giám đốc / Người duyệt</td>
                                </tr>
                                <tr style={{ fontSize: 11, fontStyle: 'italic', color: '#666' }}>
                                    <td>(Ký, họ tên)</td>
                                    <td>(Ký, họ tên)</td>
                                    <td>(Ký, họ tên, đóng dấu)</td>
                                </tr>
                                <tr>
                                    <td style={{ height: 60 }}></td>
                                    <td style={{ height: 60 }}></td>
                                    <td style={{ height: 60 }}></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                ) : (
                    /* Receipt / Payment body */
                    <div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 8 }}>
                            <div style={{ display: 'flex' }}>
                                <span style={{ width: 170, flexShrink: 0 }}>
                                    {isReceipt ? 'Họ và tên người nộp tiền:' : 'Họ và tên người nhận tiền:'}
                                </span>
                                <strong style={{ flexGrow: 1, borderBottom: '1px dotted #999', paddingBottom: 2 }}>
                                    {printData.payer_name || printData.receiver_name || printData.contact_name || '—'}
                                </strong>
                            </div>

                            <div style={{ display: 'flex' }}>
                                <span style={{ width: 170, flexShrink: 0 }}>Địa chỉ:</span>
                                <span style={{ flexGrow: 1, borderBottom: '1px dotted #999', paddingBottom: 2 }}>
                                    {printData.payer_address || printData.receiver_address || printData.address || '—'}
                                </span>
                            </div>

                            <div style={{ display: 'flex' }}>
                                <span style={{ width: 170, flexShrink: 0 }}>
                                    {isReceipt ? 'Lý do nộp:' : 'Lý do chi:'}
                                </span>
                                <span style={{ flexGrow: 1, borderBottom: '1px dotted #999', paddingBottom: 2 }}>
                                    {printData.reason || printData.description || '—'}
                                </span>
                            </div>

                            <div style={{ display: 'flex' }}>
                                <span style={{ width: 170, flexShrink: 0 }}>Số tiền:</span>
                                <span style={{ flexGrow: 1, borderBottom: '1px dotted #999', paddingBottom: 2, fontWeight: 'bold', fontSize: 14 }}>
                                    {amountDisplay} {printData.currency ?? '—'}
                                </span>
                            </div>

                            <div style={{ display: 'flex' }}>
                                <span style={{ width: 170, flexShrink: 0 }}>Viết bằng chữ:</span>
                                <span style={{ flexGrow: 1, borderBottom: '1px dotted #999', paddingBottom: 2, fontStyle: 'italic', fontWeight: 600 }}>
                                    {amountInWords}
                                </span>
                            </div>

                            <div style={{ display: 'flex' }}>
                                <span style={{ width: 170, flexShrink: 0 }}>Kèm theo:</span>
                                <span style={{ flexGrow: 1, borderBottom: '1px dotted #999', paddingBottom: 2 }}>
                                    {printData.attached_docs ? `${printData.attached_docs} chứng từ gốc` : '................................................ chứng từ gốc'}
                                </span>
                            </div>
                        </div>

                        {/* Signatures */}
                        <table style={{ width: '100%', marginTop: 16, borderCollapse: 'collapse', textAlign: 'center' }}>
                            <tbody>
                                <tr style={{ fontWeight: 'bold' }}>
                                    <td style={{ width: '20%' }}>Giám đốc</td>
                                    <td style={{ width: '20%' }}>Kế toán trưởng</td>
                                    <td style={{ width: '20%' }}>{isReceipt ? 'Người nộp tiền' : 'Người nhận tiền'}</td>
                                    <td style={{ width: '20%' }}>Người lập phiếu</td>
                                    <td style={{ width: '20%' }}>Thủ quỹ</td>
                                </tr>
                                <tr style={{ fontSize: 11, fontStyle: 'italic', color: '#666' }}>
                                    <td>(Ký, họ tên, đóng dấu)</td>
                                    <td>(Ký, họ tên)</td>
                                    <td>(Ký, họ tên)</td>
                                    <td>(Ký, họ tên)</td>
                                    <td>(Ký, họ tên)</td>
                                </tr>
                                <tr>
                                    <td style={{ height: 55 }}></td>
                                    <td style={{ height: 55 }}></td>
                                    <td style={{ height: 55 }}></td>
                                    <td style={{ height: 55 }}></td>
                                    <td style={{ height: 55 }}></td>
                                </tr>
                            </tbody>
                        </table>

                        {/* Received confirmation note */}
                        <div style={{ marginTop: 12, fontSize: 12, borderTop: '1px dashed #ccc', paddingTop: 6 }}>
                            <div style={{ fontStyle: 'italic' }}>
                                - Đã nhận đủ số tiền (viết bằng chữ): <strong>{amountInWords}</strong>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </Modal>
    );
};

export default VoucherPrintModal;
