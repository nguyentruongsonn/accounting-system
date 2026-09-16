export interface ExportColumn {
    title: string;
    key: string;
    render?: (val: any, row: any) => string | number;
}

interface ExportCompanyEvidence {
    name?: string | null;
}

export const exportDataToExcel = (
    title: string,
    periodText: string,
    columns: ExportColumn[],
    data: any[],
    filenamePrefix: string,
    company?: ExportCompanyEvidence,
) => {
    // Generate CSV content with UTF-8 BOM for perfect Excel Vietnamese font rendering
    let csvContent = "\uFEFF";
    csvContent += `${company?.name?.trim() || 'CHƯA CÓ THÔNG TIN DOANH NGHIỆP TỪ MÁY CHỦ'}\n`;
    csvContent += `${title.toUpperCase()} - KỲ: ${periodText}\n\n`;

    // Header row
    const headers = columns.map(c => `"${c.title.replace(/"/g, '""')}"`);
    csvContent += headers.join(",") + "\n";

    // Data rows
    data.forEach(row => {
        const line = columns.map(col => {
            let val = row[col.key];
            if (col.render) {
                val = col.render(val, row);
            }
            if (val === null || val === undefined) val = '';
            if (typeof val === 'number') return `"${val}"`;
            return `"${String(val).replace(/"/g, '""')}"`;
        });
        csvContent += line.join(",") + "\n";
    });

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.setAttribute("href", url);
    link.setAttribute("download", `${filenamePrefix}_${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
};
