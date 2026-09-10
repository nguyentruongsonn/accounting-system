import React, { useState } from 'react';
import { Button, Select } from 'antd';
import Modal from '../layout/AppModal';
import { PrinterOutlined, BankOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { readMoneyToVietnameseWords } from '../../utils/numberToWords';
import { printElementSafely } from '../../utils/safePrint';
import ModalFrame from '../layout/ModalFrame';

const BANK_PRINT_STYLES = `
    body { font-family: "Times New Roman", Times, serif; font-size: 13.5px; line-height: 1.4; padding: 10px; }
    table { width: 100%; border-collapse: collapse; }
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .bold { font-weight: bold; }
    .italic { font-style: italic; }
    .box-border { border: 1px solid #333; padding: 8px; }
    .unc-header { border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 12px; }
    .grid-border th, .grid-border td { border: 1px solid #444; padding: 6px; }
`;

export type BankPrintTemplateType = 'bao_co' | 'bao_no' | 'unc_tt200' | 'unc_vcb' | 'unc_tcb' | 'unc_bidv' | 'unc_ctg';

export interface BankVoucherPrintData {
    company_name?: string;
    company_address?: string;
    company_tax_code?: string;
    
    // Voucher common
    voucher_number?: string;
    voucher_date?: string | Date;
    posting_date?: string | Date;
    voucher_type?: string;
    description?: string;
    currency?: string;
    exchange_rate?: number;
    amount?: number;
    total_amount?: number;
    amount_in_words?: string;
    fee_bearer?: string;
    
    // Source Bank (Tài khoản chi/nhận của đơn vị)
    bank_account_number?: string;
    bank_name?: string;
    bank_branch?: string;
    bank_code?: string;
    
    // Payer (Người nộp / chuyển tiền)
    payer_name?: string;
    payer_address?: string;
    payer_bank_account?: string;
    payer_bank_name?: string;
    payer_branch?: string;
    
    // Payee (Người nhận / thụ hưởng)
    payee_name?: string;
    payee_address?: string;
    payee_bank_account?: string;
    payee_bank_name?: string;
    payee_branch?: string;

    // Contact
    contact_name?: string;
    employee_name?: string;

    // Accounting lines
    lines?: Array<{
        description?: string;
        debit_account?: string;
        credit_account?: string;
        amount?: number;
    }>;
}

export interface BankVoucherPrintModalProps {
    open: boolean;
    onClose?: () => void;
    onCancel?: () => void;
    voucherType?: 'receipt' | 'payment';
    data?: BankVoucherPrintData;
    voucher?: BankVoucherPrintData;
    initialTemplate?: BankPrintTemplateType;
}

export const BankVoucherPrintModal: React.FC<BankVoucherPrintModalProps> = ({
    open,
    onClose,
    onCancel,
    voucherType = 'receipt',
    data,
    voucher,
    initialTemplate,
}) => {
    const handleClose = onCancel || onClose || (() => {});
    const printData = voucher || data || {};
    const isReceipt = voucherType === 'receipt';

    // Default template selection
    const defaultTemplate: BankPrintTemplateType = initialTemplate || (isReceipt ? 'bao_co' : 'unc_tt200');
    const [selectedTemplate, setSelectedTemplate] = useState<BankPrintTemplateType>(defaultTemplate);

    const voucherDate = printData.voucher_date ? dayjs(printData.voucher_date) : null;
    const day = voucherDate?.format('DD') ?? '—';
    const month = voucherDate?.format('MM') ?? '—';
    const year = voucherDate?.format('YYYY') ?? '—';

    const totalAmount = printData.total_amount ?? printData.amount;
    const amountDisplay = totalAmount == null ? '—' : Number(totalAmount).toLocaleString('vi-VN');
    const amountInWords = printData.amount_in_words ?? (totalAmount == null ? '—' : readMoneyToVietnameseWords(totalAmount));

    const debitAccounts = Array.from(new Set((printData.lines || []).map(l => l.debit_account).filter(Boolean))).join(', ') || '—';
    const creditAccounts = Array.from(new Set((printData.lines || []).map(l => l.credit_account).filter(Boolean))).join(', ') || '—';

    const sourceAccount = printData.bank_account_number ?? '—';
    const sourceBank = printData.bank_name ?? '—';
    const destAccount = printData.payee_bank_account ?? printData.payer_bank_account ?? '—';
    const destBank = printData.payee_bank_name ?? printData.payer_bank_name ?? '—';
    const feePayerText = printData.fee_bearer === 'seller' || printData.fee_bearer === 'contact' ? 'Người thụ hưởng chịu phí' : 'Đơn vị chịu phí (Trong số tiền)';

    const handlePrint = () => {
        const printContent = document.getElementById('bank-voucher-printable-area');
        if (!printContent) return;

        const documentTitle = `Bản in Ngân hàng - ${printData.voucher_number || ''}`;
        printElementSafely(printContent, documentTitle, BANK_PRINT_STYLES);
    };

    // Render Template Content
    const renderTemplate = () => {
        switch (selectedTemplate) {
            case 'bao_co':
                return renderBaoCo();
            case 'bao_no':
                return renderBaoNo();
            case 'unc_vcb':
                return renderUNC('Vietcombank', 'VCB - NGÂN HÀNG TMCP NGOẠI THƯƠNG VIỆT NAM', '#005930');
            case 'unc_tcb':
                return renderUNC('Techcombank', 'TECHCOMBANK - NGÂN HÀNG TMCP KỸ THƯƠNG VIỆT NAM', '#e01a22');
            case 'unc_bidv':
                return renderUNC('BIDV', 'BIDV - NGÂN HÀNG TMCP ĐẦU TƯ VÀ PHÁT TRIỂN VIỆT NAM', '#005f6e');
            case 'unc_ctg':
                return renderUNC('VietinBank', 'VIETINBANK - NGÂN HÀNG TMCP CÔNG THƯƠNG VIỆT NAM', '#004c8f');
            case 'unc_tt200':
            default:
                return renderUNCTT200();
        }
    };

    // 1. Giấy Báo Có (Bank Credit Advice)
    const renderBaoCo = () => (
        <div>
            <table style={{ width: '100%', marginBottom: 12 }}>
                <tbody>
                    <tr>
                        <td style={{ width: '60%', verticalAlign: 'top' }}>
                    <div style={{ fontWeight: 'bold' }}>{printData.company_name ?? '—'}</div>
                    <div style={{ fontSize: 13, color: '#333' }}>{printData.company_address ?? '—'}</div>
                        </td>
                        <td style={{ width: '40%', verticalAlign: 'top', textAlign: 'center' }}>
                            <div style={{ fontWeight: 'bold' }}>Mẫu số: 01 - TT</div>
                            <div style={{ fontSize: 12, fontStyle: 'italic' }}>(Ban hành theo TT số 200/2014/TT-BTC)</div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div style={{ textAlign: 'center', margin: '12px 0 16px' }}>
                <div style={{ fontSize: '20px', fontWeight: 'bold', textTransform: 'uppercase', letterSpacing: '1px' }}>
                    GIẤY BÁO CÓ (NGÂN HÀNG)
                </div>
                <div style={{ fontStyle: 'italic', fontSize: '13px', margin: '2px 0' }}>
                    Ngày {day} tháng {month} năm {year}
                </div>
                <div style={{ fontSize: 13 }}>
                    Số: <strong>{printData.voucher_number ?? '—'}</strong> | Nợ: <strong>{debitAccounts}</strong> | Có: <strong>{creditAccounts}</strong>
                </div>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 8, fontSize: 14 }}>
                <div style={{ display: 'flex' }}>
                    <span style={{ width: 180, flexShrink: 0 }}>Ngân hàng báo Có:</span>
                    <strong style={{ flexGrow: 1, borderBottom: '1px dotted #888' }}>{sourceBank}</strong>
                </div>
                <div style={{ display: 'flex' }}>
                    <span style={{ width: 180, flexShrink: 0 }}>Số tài khoản nhận:</span>
                    <strong style={{ flexGrow: 1, borderBottom: '1px dotted #888' }}>{sourceAccount}</strong>
                </div>
                <div style={{ display: 'flex' }}>
                    <span style={{ width: 180, flexShrink: 0 }}>Đơn vị nộp/chuyển tiền:</span>
                    <span style={{ flexGrow: 1, borderBottom: '1px dotted #888' }}>
                        {printData.payer_name ?? printData.contact_name ?? '—'}
                    </span>
                </div>
                <div style={{ display: 'flex' }}>
                    <span style={{ width: 180, flexShrink: 0 }}>Tài khoản chuyển:</span>
                    <span style={{ flexGrow: 1, borderBottom: '1px dotted #888' }}>
                        {printData.payer_bank_account || 'Tại ngân hàng đối tác'}
                    </span>
                </div>
                <div style={{ display: 'flex' }}>
                    <span style={{ width: 180, flexShrink: 0 }}>Nội dung nộp:</span>
                    <span style={{ flexGrow: 1, borderBottom: '1px dotted #888' }}>{printData.description || 'Thu tiền gửi vào tài khoản'}</span>
                </div>
                <div style={{ display: 'flex' }}>
                    <span style={{ width: 180, flexShrink: 0 }}>Số tiền báo Có:</span>
                    <strong style={{ flexGrow: 1, borderBottom: '1px dotted #888', fontSize: 15 }}>
                        {amountDisplay} {printData.currency ?? '—'}
                    </strong>
                </div>
                <div style={{ display: 'flex' }}>
                    <span style={{ width: 180, flexShrink: 0 }}>Số tiền viết bằng chữ:</span>
                    <span style={{ flexGrow: 1, borderBottom: '1px dotted #888', fontStyle: 'italic', fontWeight: 'bold' }}>
                        {amountInWords}
                    </span>
                </div>
            </div>

            <table style={{ width: '100%', marginTop: 24, borderCollapse: 'collapse', textAlign: 'center' }}>
                <tbody>
                    <tr style={{ fontWeight: 'bold' }}>
                        <td style={{ width: '25%' }}>Giám đốc</td>
                        <td style={{ width: '25%' }}>Kế toán trưởng</td>
                        <td style={{ width: '25%' }}>Người lập phiếu</td>
                        <td style={{ width: '25%' }}>Ngân hàng xác nhận</td>
                    </tr>
                    <tr style={{ fontSize: 12, fontStyle: 'italic', color: '#666' }}>
                        <td>(Ký, họ tên, đóng dấu)</td>
                        <td>(Ký, họ tên)</td>
                        <td>(Ký, họ tên)</td>
                        <td>(Ký, đóng dấu)</td>
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
    );

    // 2. Giấy Báo Nợ (Bank Debit Advice)
    const renderBaoNo = () => (
        <div>
            <table style={{ width: '100%', marginBottom: 12 }}>
                <tbody>
                    <tr>
                        <td style={{ width: '60%', verticalAlign: 'top' }}>
                    <div style={{ fontWeight: 'bold' }}>{printData.company_name ?? '—'}</div>
                    <div style={{ fontSize: 13, color: '#333' }}>{printData.company_address ?? '—'}</div>
                        </td>
                        <td style={{ width: '40%', verticalAlign: 'top', textAlign: 'center' }}>
                            <div style={{ fontWeight: 'bold' }}>Mẫu số: 02 - TT</div>
                            <div style={{ fontSize: 12, fontStyle: 'italic' }}>(Ban hành theo TT số 200/2014/TT-BTC)</div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div style={{ textAlign: 'center', margin: '12px 0 16px' }}>
                <div style={{ fontSize: '20px', fontWeight: 'bold', textTransform: 'uppercase', letterSpacing: '1px' }}>
                    GIẤY BÁO NỢ (NGÂN HÀNG)
                </div>
                <div style={{ fontStyle: 'italic', fontSize: '13px', margin: '2px 0' }}>
                    Ngày {day} tháng {month} năm {year}
                </div>
                <div style={{ fontSize: 13 }}>
                    Số: <strong>{printData.voucher_number ?? '—'}</strong> | Nợ: <strong>{debitAccounts}</strong> | Có: <strong>{creditAccounts}</strong>
                </div>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 8, fontSize: 14 }}>
                <div style={{ display: 'flex' }}>
                    <span style={{ width: 180, flexShrink: 0 }}>Ngân hàng trích nợ:</span>
                    <strong style={{ flexGrow: 1, borderBottom: '1px dotted #888' }}>{sourceBank}</strong>
                </div>
                <div style={{ display: 'flex' }}>
                    <span style={{ width: 180, flexShrink: 0 }}>Số tài khoản trích nợ:</span>
                    <strong style={{ flexGrow: 1, borderBottom: '1px dotted #888' }}>{sourceAccount}</strong>
                </div>
                <div style={{ display: 'flex' }}>
                    <span style={{ width: 180, flexShrink: 0 }}>Đơn vị thụ hưởng:</span>
                    <span style={{ flexGrow: 1, borderBottom: '1px dotted #888' }}>
                        {printData.payee_name ?? printData.contact_name ?? '—'}
                    </span>
                </div>
                <div style={{ display: 'flex' }}>
                    <span style={{ width: 180, flexShrink: 0 }}>Số tài khoản thụ hưởng:</span>
                    <span style={{ flexGrow: 1, borderBottom: '1px dotted #888' }}>
                        {destAccount} tại {destBank}
                    </span>
                </div>
                <div style={{ display: 'flex' }}>
                    <span style={{ width: 180, flexShrink: 0 }}>Nội dung thanh toán:</span>
                    <span style={{ flexGrow: 1, borderBottom: '1px dotted #888' }}>{printData.description || 'Thanh toán tiền qua ngân hàng'}</span>
                </div>
                <div style={{ display: 'flex' }}>
                    <span style={{ width: 180, flexShrink: 0 }}>Số tiền trích nợ:</span>
                    <strong style={{ flexGrow: 1, borderBottom: '1px dotted #888', fontSize: 15 }}>
                        {amountDisplay} {printData.currency ?? '—'}
                    </strong>
                </div>
                <div style={{ display: 'flex' }}>
                    <span style={{ width: 180, flexShrink: 0 }}>Số tiền viết bằng chữ:</span>
                    <span style={{ flexGrow: 1, borderBottom: '1px dotted #888', fontStyle: 'italic', fontWeight: 'bold' }}>
                        {amountInWords}
                    </span>
                </div>
            </div>

            <table style={{ width: '100%', marginTop: 24, borderCollapse: 'collapse', textAlign: 'center' }}>
                <tbody>
                    <tr style={{ fontWeight: 'bold' }}>
                        <td style={{ width: '25%' }}>Giám đốc</td>
                        <td style={{ width: '25%' }}>Kế toán trưởng</td>
                        <td style={{ width: '25%' }}>Người lập phiếu</td>
                        <td style={{ width: '25%' }}>Ngân hàng xác nhận</td>
                    </tr>
                    <tr style={{ fontSize: 12, fontStyle: 'italic', color: '#666' }}>
                        <td>(Ký, họ tên, đóng dấu)</td>
                        <td>(Ký, họ tên)</td>
                        <td>(Ký, họ tên)</td>
                        <td>(Ký, đóng dấu)</td>
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
    );

    // 3. Mẫu UNC chuẩn Thông tư 200 (Generic UNC)
    const renderUNCTT200 = () => (
        <div>
            <table style={{ width: '100%', borderBottom: '2px solid #000', paddingBottom: 6, marginBottom: 12 }}>
                <tbody>
                    <tr>
                        <td style={{ width: '50%', verticalAlign: 'top' }}>
                            <div style={{ fontSize: 16, fontWeight: 'bold', textTransform: 'uppercase' }}>ỦY NHIỆM CHI (PAYMENT ORDER)</div>
                            <div style={{ fontStyle: 'italic', fontSize: 13 }}>Mẫu số C4-02/KB (Ban hành theo TT 200/2014/TT-BTC)</div>
                        </td>
                        <td style={{ width: '50%', textAlign: 'right', verticalAlign: 'top' }}>
                    <div style={{ fontWeight: 'bold' }}>Số UNC: {printData.voucher_number ?? '—'}</div>
                            <div style={{ fontSize: 13 }}>Ngày {day} tháng {month} năm {year}</div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: 12, border: '1px solid #333' }}>
                <tbody>
                    <tr style={{ background: '#f5f5f5', borderBottom: '1px solid #333' }}>
                        <td style={{ width: '50%', padding: '6px 8px', fontWeight: 'bold' }}>ĐƠN VỊ TRẢ TIỀN (APPLICANT)</td>
                        <td style={{ width: '50%', padding: '6px 8px', fontWeight: 'bold', borderLeft: '1px solid #333' }}>ĐƠN VỊ THỤ HƯỞNG (BENEFICIARY)</td>
                    </tr>
                    <tr>
                        <td style={{ padding: '8px', verticalAlign: 'top', borderRight: '1px solid #333' }}>
                            <div>Đơn vị: <strong>{printData.company_name ?? '—'}</strong></div>
                            <div style={{ marginTop: 4 }}>Số tài khoản: <strong style={{ fontSize: 14 }}>{sourceAccount}</strong></div>
                            <div style={{ marginTop: 4 }}>Tại ngân hàng: <strong>{sourceBank}</strong></div>
                        </td>
                        <td style={{ padding: '8px', verticalAlign: 'top' }}>
                            <div>Đơn vị: <strong>{printData.payee_name ?? printData.contact_name ?? '—'}</strong></div>
                            <div style={{ marginTop: 4 }}>Số tài khoản: <strong style={{ fontSize: 14 }}>{destAccount}</strong></div>
                            <div style={{ marginTop: 4 }}>Tại ngân hàng: <strong>{destBank}</strong></div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div style={{ border: '1px solid #333', padding: 10, marginBottom: 12 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6 }}>
                    <span>Số tiền bằng số (Amount in figures):</span>
                    <strong style={{ fontSize: 16 }}>{amountDisplay} {printData.currency ?? '—'}</strong>
                </div>
                <div style={{ display: 'flex', marginBottom: 6 }}>
                    <span style={{ width: 220, flexShrink: 0 }}>Số tiền bằng chữ (In words):</span>
                    <span style={{ fontStyle: 'italic', fontWeight: 'bold' }}>{amountInWords}</span>
                </div>
                <div style={{ display: 'flex', marginBottom: 6 }}>
                    <span style={{ width: 220, flexShrink: 0 }}>Nội dung thanh toán (Details):</span>
                    <span>{printData.description || 'Thanh toán tiền hàng / dịch vụ'}</span>
                </div>
                <div style={{ display: 'flex' }}>
                    <span style={{ width: 220, flexShrink: 0 }}>Phí chuyển tiền (Fee bearer):</span>
                    <span>{feePayerText}</span>
                </div>
            </div>

            <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'center', marginTop: 10 }}>
                <tbody>
                    <tr style={{ fontWeight: 'bold' }}>
                        <td style={{ width: '25%' }}>Kế toán trưởng</td>
                        <td style={{ width: '25%' }}>Chủ tài khoản</td>
                        <td style={{ width: '25%' }}>Giao dịch viên NH</td>
                        <td style={{ width: '25%' }}>Kiểm soát viên NH</td>
                    </tr>
                    <tr style={{ fontSize: 12, fontStyle: 'italic', color: '#666' }}>
                        <td>(Ký, họ tên)</td>
                        <td>(Ký, đóng dấu)</td>
                        <td>(Ký, họ tên)</td>
                        <td>(Ký, đóng dấu)</td>
                    </tr>
                    <tr>
                        <td style={{ height: 65 }}></td>
                        <td style={{ height: 65 }}></td>
                        <td style={{ height: 65 }}></td>
                        <td style={{ height: 65 }}></td>
                    </tr>
                </tbody>
            </table>
        </div>
    );

    // 4. Bank-specific UNC (VCB, TCB, BIDV, CTG)
    const renderUNC = (bankShortName: string, bankFullName: string, brandColor: string) => (
        <div>
            {/* Bank Header Bar */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderBottom: `3px solid ${brandColor}`, paddingBottom: 6, marginBottom: 12 }}>
                <div>
                    <div style={{ fontSize: 17, fontWeight: 'bold', color: brandColor, textTransform: 'uppercase' }}>
                        {bankFullName}
                    </div>
                    <div style={{ fontSize: 13, fontStyle: 'italic', color: '#444' }}>
                        LỆNH CHUYỂN TIỀN / ỦY NHIỆM CHI (PAYMENT ORDER)
                    </div>
                </div>
                <div style={{ textAlign: 'right' }}>
                    <div style={{ fontWeight: 'bold', fontSize: 14 }}>Số UNC: {printData.voucher_number ?? '—'}</div>
                    <div style={{ fontSize: 12, color: '#555' }}>Ngày {day}/{month}/{year}</div>
                </div>
            </div>

            {/* Account Info Box */}
            <table style={{ width: '100%', border: '1px solid #aaa', borderCollapse: 'collapse', marginBottom: 10 }}>
                <tbody>
                    <tr style={{ background: '#f8f9fa', borderBottom: '1px solid #aaa' }}>
                        <td style={{ width: '50%', padding: '6px 8px', fontWeight: 'bold', color: brandColor }}>THÔNG TIN ĐƠN VỊ TRẢ TIỀN (DEBIT ACCOUNT)</td>
                        <td style={{ width: '50%', padding: '6px 8px', fontWeight: 'bold', color: brandColor, borderLeft: '1px solid #aaa' }}>THÔNG TIN ĐƠN VỊ THỤ HƯỞNG (BENEFICIARY)</td>
                    </tr>
                    <tr>
                        <td style={{ padding: '8px', verticalAlign: 'top', borderRight: '1px solid #aaa' }}>
                            <div>Tên tài khoản: <strong>{printData.company_name ?? '—'}</strong></div>
                            <div style={{ marginTop: 4 }}>Số tài khoản trích nợ: <strong style={{ fontSize: 15, color: '#000' }}>{sourceAccount}</strong></div>
                            <div style={{ marginTop: 4 }}>Tại: <strong>{sourceBank}</strong></div>
                        </td>
                        <td style={{ padding: '8px', verticalAlign: 'top' }}>
                            <div>Tên người thụ hưởng: <strong>{printData.payee_name ?? printData.contact_name ?? '—'}</strong></div>
                            <div style={{ marginTop: 4 }}>Số tài khoản nhận: <strong style={{ fontSize: 15, color: '#000' }}>{destAccount}</strong></div>
                            <div style={{ marginTop: 4 }}>Tại: <strong>{destBank}</strong></div>
                        </td>
                    </tr>
                </tbody>
            </table>

            {/* Payment Details Box */}
            <div style={{ border: '1px solid #aaa', padding: 10, marginBottom: 12, borderRadius: 2 }}>
                <table style={{ width: '100%' }}>
                    <tbody>
                        <tr>
                            <td style={{ width: '220px', padding: '4px 0' }}>Số tiền bằng số (In figures):</td>
                            <td><strong style={{ fontSize: 17, color: brandColor }}>{amountDisplay} {printData.currency ?? '—'}</strong></td>
                        </tr>
                        <tr>
                            <td style={{ padding: '4px 0', verticalAlign: 'top' }}>Số tiền bằng chữ (In words):</td>
                            <td><span style={{ fontStyle: 'italic', fontWeight: 'bold' }}>{amountInWords}</span></td>
                        </tr>
                        <tr>
                            <td style={{ padding: '4px 0', verticalAlign: 'top' }}>Nội dung chuyển tiền (Details):</td>
                            <td><span>{printData.description || 'Thanh toán tiền hàng hóa / dịch vụ'}</span></td>
                        </tr>
                        <tr>
                            <td style={{ padding: '4px 0' }}>Phí giao dịch (Fee bearer):</td>
                            <td><span>{feePayerText}</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {/* Signatures */}
            <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'center', marginTop: 10 }}>
                <tbody>
                    <tr style={{ fontWeight: 'bold' }}>
                        <td style={{ width: '25%' }}>Kế toán trưởng</td>
                        <td style={{ width: '25%' }}>Chủ tài khoản</td>
                        <td style={{ width: '25%' }}>Giao dịch viên {bankShortName}</td>
                        <td style={{ width: '25%' }}>Kiểm soát viên {bankShortName}</td>
                    </tr>
                    <tr style={{ fontSize: 11.5, fontStyle: 'italic', color: '#666' }}>
                        <td>(Ký, họ tên)</td>
                        <td>(Ký, đóng dấu)</td>
                        <td>(Ký, họ tên)</td>
                        <td>(Ký, đóng dấu)</td>
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
    );

    return (
        <Modal
            open={open}
            onCancel={handleClose}
            width={880}
            centered={true}
            className="misa-clean-modal misa-bank-voucher-print-modal"
            zIndex={2500}
            title={
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', paddingRight: 24 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <BankOutlined style={{ color: '#1677ff', fontSize: 18 }} />
                        <span style={{ fontWeight: 600 }}>In chứng từ Ngân hàng ({printData.voucher_number || ''})</span>
                    </div>
                </div>
            }
            footer={[
                <Button key="close" onClick={handleClose}>Đóng</Button>,
                <Button key="print" type="primary" icon={<PrinterOutlined />} onClick={handlePrint} style={{ background: '#1677ff' }}>
                    In chứng từ
                </Button>
            ]}
        >
            <ModalFrame className="misa-bank-voucher-print-modal__frame">
            {/* Template Selector Bar */}
            <div style={{ padding: '8px 12px', background: '#f5f7f8', borderBottom: '1px solid #e0e0e0', marginBottom: 12, display: 'flex', alignItems: 'center', gap: 12, borderRadius: 4 }}>
                <span style={{ fontWeight: 600, color: '#333', fontSize: 13 }}>Chọn mẫu in chuẩn:</span>
                <Select
                    value={selectedTemplate}
                    onChange={(val) => setSelectedTemplate(val)}
                    style={{ width: 340 }}
                    options={[
                        { label: '📄 Giấy Báo Có (Mẫu 01-TT)', value: 'bao_co' },
                        { label: '📄 Giấy Báo Nợ (Mẫu 02-TT)', value: 'bao_no' },
                        { label: '🏛️ Ủy nhiệm chi chuẩn TT200 (Mẫu C4-02/KB)', value: 'unc_tt200' },
                        { label: '🟢 Ủy nhiệm chi Vietcombank (VCB)', value: 'unc_vcb' },
                        { label: '🔴 Ủy nhiệm chi Techcombank (TCB)', value: 'unc_tcb' },
                        { label: '🔵 Ủy nhiệm chi BIDV', value: 'unc_bidv' },
                        { label: '🔷 Ủy nhiệm chi VietinBank (CTG)', value: 'unc_ctg' },
                    ]}
                />
            </div>

            {/* Printable Paper Area */}
            <div 
                id="bank-voucher-printable-area"
                style={{
                    fontFamily: '"Times New Roman", Times, serif',
                    fontSize: '13.5px',
                    color: '#000',
                    padding: '24px',
                    background: '#fff',
                    border: '1px solid #d9d9d9',
                    borderRadius: '4px',
                    minHeight: '460px',
                    boxShadow: '0 1px 3px rgba(0,0,0,0.05)'
                }}
            >
                {renderTemplate()}
            </div>
            </ModalFrame>
        </Modal>
    );
};

export default BankVoucherPrintModal;
