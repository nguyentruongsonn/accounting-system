interface ExportCompanyEvidence {
    name?: string | null;
}

export const exportCashTransactionsToExcel = (
    transactions: any[],
    periodText: string,
    company?: ExportCompanyEvidence,
) => {
    // Generate CSV content with UTF-8 BOM for Excel support
    let csvContent = "\uFEFF";
    csvContent += `${company?.name?.trim() || 'CHƯA CÓ THÔNG TIN DOANH NGHIỆP TỪ MÁY CHỦ'}\n`;
    csvContent += `SỔ NHẬT KÝ THU CHI TIỀN MẶT - KỲ: ${periodText}\n\n`;
    csvContent += "Ngày hạch toán,Ngày chứng từ,Số chứng từ,Loại chứng từ,Đối tượng,Lý do / Diễn giải,Số tiền (VNĐ),Trạng thái\n";

    transactions.forEach(t => {
        const row = [
            `"${t.posting_date || ''}"`,
            `"${t.voucher_date || ''}"`,
            `"${t.voucher_number || ''}"`,
            `"${t.type_label || (t.type === 'receipt' ? 'Phiếu thu' : 'Phiếu chi')}"`,
            `"${(t.contact_name || '').replace(/"/g, '""')}"`,
            `"${(t.reason || '').replace(/"/g, '""')}"`,
            `"${t.total_amount ?? ''}"`,
            `"${t.is_posted === true ? 'Đã ghi sổ' : t.is_posted === false ? 'Bản nháp' : ''}"`
        ];
        csvContent += row.join(",") + "\n";
    });

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.setAttribute("href", url);
    link.setAttribute("download", `So_Thu_Chi_Tien_Mat_${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
};
