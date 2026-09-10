import * as XLSX from 'xlsx';
import dayjs from 'dayjs';
import { formatDate } from './dateUtils';

interface CashTransactionExportItem {
    type: 'receipt' | 'payment' | string;
    voucher_number: string;
    posting_date: string;
    voucher_date: string;
    contact_code?: string;
    contact_name?: string;
    reason?: string;
    total_amount: number;
    is_posted?: boolean;
}

interface ExportCompanyEvidence {
    name?: string | null;
    address?: string | null;
    tax_code?: string | null;
}

export const exportCashTransactionsToExcel = (
    transactions: CashTransactionExportItem[],
    periodLabel: string = 'Đầu năm tới hiện tại',
    company?: ExportCompanyEvidence
) => {
    // 1. Prepare Header Info
    const companyName = company?.name?.trim();
    const companyAddress = company?.address?.trim();
    const companyTaxCode = company?.tax_code?.trim();
    const companyHeader = [
        [companyName || 'CHƯA CÓ THÔNG TIN DOANH NGHIỆP TỪ MÁY CHỦ'],
        [`Địa chỉ: ${companyAddress || '—'}`],
        [`Mã số thuế: ${companyTaxCode || '—'}`],
        [],
        ['SỔ TỔNG HỢP DANH SÁCH THU CHI TIỀN MẶT (TK 111)'],
        [`Kỳ báo cáo: ${periodLabel} | Ngày lập báo cáo: ${dayjs().format('DD/MM/YYYY HH:mm')}`],
        ['Đơn vị tính: Đồng Việt Nam (VND)'],
        []
    ];

    // 2. Table Column Headers
    const tableHeaders = [
        'STT',
        'Loại chứng từ',
        'Số chứng từ',
        'Ngày hạch toán',
        'Ngày chứng từ',
        'Mã đối tượng',
        'Tên đối tượng',
        'Lý do / Diễn giải',
        'Số tiền Thu (VND)',
        'Số tiền Chi (VND)',
        'Trạng thái ghi sổ'
    ];

    let totalThu = 0;
    let totalChi = 0;

    // 3. Table Rows
    const dataRows = transactions.map((item, index) => {
        const isReceipt = item.type === 'receipt';
        const thuAmt = isReceipt ? Number(item.total_amount) || 0 : 0;
        const chiAmt = !isReceipt ? Number(item.total_amount) || 0 : 0;
        totalThu += thuAmt;
        totalChi += chiAmt;

        return [
            index + 1,
            isReceipt ? 'Phiếu thu' : 'Phiếu chi',
            item.voucher_number,
            formatDate(item.posting_date),
            formatDate(item.voucher_date),
            item.contact_code || '',
            item.contact_name || '',
            item.reason || '',
            thuAmt > 0 ? thuAmt : '',
            chiAmt > 0 ? chiAmt : '',
            item.is_posted === true ? 'Đã ghi sổ' : item.is_posted === false ? 'Bản nháp' : ''
        ];
    });

    // 4. Summary Row
    const summaryRow = [
        '',
        'TỔNG CỘNG',
        '',
        '',
        '',
        '',
        '',
        '',
        totalThu,
        totalChi,
        `Tồn: ${totalThu - totalChi >= 0 ? '+' : ''}${new Intl.NumberFormat('vi-VN').format(totalThu - totalChi)} ₫`
    ];

    // 5. Combine All Rows
    const wsData = [
        ...companyHeader,
        tableHeaders,
        ...dataRows,
        summaryRow,
        [],
        ['', '', '', '', 'Người lập biểu', '', '', '', '', 'Kế toán trưởng', 'Giám đốc'],
        ['', '', '', '', '(Ký, họ tên)', '', '', '', '', '(Ký, họ tên)', '(Ký, đóng dấu)']
    ];

    const ws = XLSX.utils.aoa_to_sheet(wsData);

    // Set Column Widths for professional layout
    ws['!cols'] = [
        { wch: 6 },   // STT
        { wch: 14 },  // Loại chứng từ
        { wch: 14 },  // Số chứng từ
        { wch: 14 },  // Ngày hạch toán
        { wch: 14 },  // Ngày chứng từ
        { wch: 14 },  // Mã đối tượng
        { wch: 28 },  // Tên đối tượng
        { wch: 36 },  // Diễn giải
        { wch: 18 },  // Số tiền Thu
        { wch: 18 },  // Số tiền Chi
        { wch: 16 }   // Trạng thái
    ];

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Thu_Chi_Tien_Mat');

    // Generate and Download Excel
    XLSX.writeFile(wb, `So_Thu_Chi_Tien_Mat_${dayjs().format('YYYYMMDD_HHmm')}.xlsx`);
};
