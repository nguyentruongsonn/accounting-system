import React from 'react';
import { Button, Popconfirm } from 'antd';
import { PlusOutlined, FileAddOutlined, DeleteOutlined } from '@ant-design/icons';

export interface MisaGridActionFooterProps {
    onAddLine: () => void;
    onAddNote?: () => void;
    onDeleteAll?: () => void;
    lineCount?: number;
    disabled?: boolean;
    extraActions?: React.ReactNode;
}

export const MisaGridActionFooter: React.FC<MisaGridActionFooterProps> = ({
    onAddLine,
    onAddNote,
    onDeleteAll,
    lineCount = 0,
    disabled = false,
    extraActions
}) => {
    return (
        <div className="misa-grid-action-footer">
            <div className="misa-grid-actions-left">
                <Button 
                    type="dashed" 
                    size="small" 
                    icon={<PlusOutlined />} 
                    onClick={onAddLine}
                    disabled={disabled}
                    className="misa-btn-grid-add"
                >
                    Thêm dòng
                </Button>

                {onAddNote && (
                    <Button 
                        size="small" 
                        icon={<FileAddOutlined />} 
                        onClick={onAddNote}
                        disabled={disabled}
                        className="misa-btn-grid-note"
                    >
                        Thêm ghi chú
                    </Button>
                )}

                {onDeleteAll && lineCount > 0 && (
                    <Popconfirm
                        title="Xóa toàn bộ các dòng chi tiết?"
                        description="Hành động này sẽ xóa tất cả các dòng trên chứng từ."
                        onConfirm={onDeleteAll}
                        okText="Xóa tất cả"
                        cancelText="Hủy"
                        okButtonProps={{ danger: true }}
                    >
                        <Button 
                            size="small" 
                            danger
                            icon={<DeleteOutlined />} 
                            disabled={disabled}
                            style={{ borderRadius: 4, height: 26, fontSize: 12 }}
                        >
                            Xóa hết dòng
                        </Button>
                    </Popconfirm>
                )}

                {extraActions}
            </div>

            <div style={{ fontSize: 12, color: '#64748b' }}>
                Tổng số dòng: <strong style={{ color: '#10141b' }}>{lineCount}</strong>
            </div>
        </div>
    );
};

export default MisaGridActionFooter;
