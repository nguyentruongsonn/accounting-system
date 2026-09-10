import React from 'react';
import { PaperClipOutlined, InboxOutlined } from '@ant-design/icons';


interface MisaUploadBoxProps {
    maxSizeMB?: number;
    onFileSelected?: (files: FileList | null) => void;
}

export const MisaUploadBox: React.FC<MisaUploadBoxProps> = ({
    maxSizeMB = 5,
}) => {
    return (
        <div className="misa-upload-box" style={{ marginTop: 10 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 8, fontSize: 12, color: '#475569', marginBottom: 2 }}>
                <PaperClipOutlined style={{ color: '#1677ff', fontSize: 14 }} />
                <span style={{ fontWeight: 600, color: '#1e293b' }}>Đính kèm</span>
                <span style={{ color: '#94a3b8' }}>Dung lượng tối đa {maxSizeMB}MB</span>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6, fontSize: 12, color: '#64748b' }}>
                <InboxOutlined style={{ color: '#2563eb', fontSize: 16 }} />
                <span><span style={{ color: '#2563eb', fontWeight: 600, cursor: 'pointer' }}>Chọn tệp</span> hoặc kéo và thả tệp vào đây</span>
            </div>
        </div>
    );
};
