import React from 'react';
import { Button } from 'antd';
import { FileExcelOutlined } from '@ant-design/icons';
import * as XLSX from 'xlsx';

interface ExportExcelButtonProps {
    data: any[];
    columns: any[];
    filename: string;
    disabled?: boolean;
    /**
     * API monetary values are canonical decimal strings. Keep these fields as
     * Excel text so the browser exporter never rounds values past Excel's
     * 15-significant-digit numeric precision.
     */
    monetaryFields?: string[];
}

const ExportExcelButton: React.FC<ExportExcelButtonProps> = ({ data, columns, filename, monetaryFields = [], disabled = false }) => {
    const handleExport = () => {
        // Build rows from columns mapping
        const exportData = data.map(record => {
            const row: any = {};
            columns.forEach(col => {
                if (col.dataIndex && col.title !== 'Hành động' && col.title !== 'Thao tác') {
                    // Extract value from record
                    let val = record[col.dataIndex];
                    
                    // Simple text format
                    if (val !== undefined && val !== null) {
                        row[col.title] = monetaryFields.includes(col.dataIndex) ? String(val) : val;
                    } else {
                        row[col.title] = '';
                    }
                }
            });
            return row;
        });

        const ws = XLSX.utils.json_to_sheet(exportData);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Sheet1');
        
        XLSX.writeFile(wb, `${filename}.xlsx`);
    };

    return (
        <Button disabled={disabled} onClick={handleExport} icon={<FileExcelOutlined />} className="misa-btn-tool">
            Xuất Excel
        </Button>
    );
};

export default ExportExcelButton;
