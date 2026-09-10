import React from 'react';
import { Space, Button, Typography, Popconfirm, Tag } from 'antd';
import { 
    PrinterOutlined, 
    CheckCircleOutlined, 
    StopOutlined, 
    EditOutlined, 
    DeleteOutlined, 
    RollbackOutlined 
} from '@ant-design/icons';
import { RequirePermission } from './RequirePermission';

interface VoucherToolbarProps {
    status: 'draft' | 'posted' | 'voided';
    onPost?: () => void;
    onVoid?: () => void;
    onEdit?: () => void;
    onDelete?: () => void;
    onPrint?: () => void;
}

export const VoucherToolbar: React.FC<VoucherToolbarProps> = ({
    status,
    onPost,
    onVoid,
    onEdit,
    onDelete,
    onPrint
}) => {
    return (
        <div style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            marginBottom: 16,
            padding: '12px 18px',
            background: '#FFFFFF',
            borderRadius: 10,
            border: '1px solid #E2E8F0',
            boxShadow: '0 1px 2px 0 rgba(0, 0, 0, 0.03)'
        }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <Typography.Text style={{ color: '#64748B', fontSize: 13 }}>Trạng thái chứng từ:</Typography.Text>
                {status === 'posted' ? (
                    <Tag color="success" style={{ borderRadius: 6, fontWeight: 600, padding: '2px 10px', fontSize: 12 }}>
                        ✓ ĐÃ GHI SỔ
                    </Tag>
                ) : status === 'voided' ? (
                    <Tag color="error" style={{ borderRadius: 6, fontWeight: 600, padding: '2px 10px', fontSize: 12 }}>
                        ✕ ĐÃ HỦY
                    </Tag>
                ) : (
                    <Tag color="warning" style={{ borderRadius: 6, fontWeight: 600, padding: '2px 10px', fontSize: 12 }}>
                        ● CHƯA GHI SỔ (TẠM)
                    </Tag>
                )}
            </div>
            <Space size={8}>
                {status === 'draft' && onEdit && (
                    <Button icon={<EditOutlined />} onClick={onEdit} style={{ borderRadius: 8 }}>
                        Sửa chứng từ
                    </Button>
                )}
                {status === 'draft' && onDelete && (
                    <Popconfirm title="Bạn có chắc chắn muốn xóa chứng từ này?" onConfirm={onDelete} okText="Xóa" cancelText="Hủy">
                        <Button danger icon={<DeleteOutlined />} style={{ borderRadius: 8 }}>
                            Xóa
                        </Button>
                    </Popconfirm>
                )}
                
                {status === 'draft' && onPost && (
                    <RequirePermission permission="post_documents">
                        <Button 
                            type="primary" 
                            icon={<CheckCircleOutlined />} 
                            onClick={onPost}
                            style={{ 
                                borderRadius: 8, 
                                fontWeight: 600, 
                                background: 'linear-gradient(135deg, #1677FF 0%, #0958D9 100%)',
                                border: 'none',
                                boxShadow: '0 2px 6px rgba(22, 119, 255, 0.25)' 
                            }}
                        >
                            Ghi sổ (Post)
                        </Button>
                    </RequirePermission>
                )}

                {status === 'posted' && onVoid && (
                    <RequirePermission permission="post_documents">
                        <Popconfirm title="Bạn có chắc chắn muốn bỏ ghi sổ chứng từ này?" onConfirm={onVoid} okText="Bỏ ghi" cancelText="Hủy">
                            <Button danger icon={<StopOutlined />} style={{ borderRadius: 8, fontWeight: 500 }}>
                                Bỏ ghi sổ (Unpost)
                            </Button>
                        </Popconfirm>
                    </RequirePermission>
                )}

                {onPrint && (
                    <Button icon={<PrinterOutlined />} onClick={onPrint} style={{ borderRadius: 8 }}>
                        In chứng từ
                    </Button>
                )}
                
                <Button icon={<RollbackOutlined />} onClick={() => window.history.back()} style={{ borderRadius: 8 }}>
                    Quay lại
                </Button>
            </Space>
        </div>
    );
};

export default VoucherToolbar;
