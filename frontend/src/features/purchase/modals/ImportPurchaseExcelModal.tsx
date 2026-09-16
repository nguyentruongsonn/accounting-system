import React, { useState } from 'react';
import { Button, Space, Upload } from 'antd';
import { toast as message } from '../../../components/feedback/toast';
import Modal from '../../../components/layout/AppModal';
import { DownloadOutlined, FileExcelOutlined, InboxOutlined } from '@ant-design/icons';

interface ImportPurchaseExcelModalProps {
    open: boolean;
    onCancel: () => void;
    /** Kept for caller compatibility until an import API is available. */
    onSuccess?: () => void;
}

/**
 * The purchase import workflow is intentionally fail-closed. The API currently
 * has no upload/validate/import contract, so this modal must not manufacture
 * preview rows or report that vouchers were created locally.
 */
export const ImportPurchaseExcelModal: React.FC<ImportPurchaseExcelModalProps> = ({
    open,
    onCancel
}) => {
    const [fileName, setFileName] = useState<string | null>(null);

    const handleDownloadTemplate = () => {
        message.info('Backend chưa công bố API tải mẫu hoặc nhập dữ liệu Excel cho chứng từ mua hàng.');
    };

    return (
        <Modal
            title={
                <div className="misa-modal-title">
                    <FileExcelOutlined className="misa-text-primary-bold" />
                    <span>Nhập khẩu chứng từ mua hàng từ Excel</span>
                </div>
            }
            open={open}
            onCancel={onCancel}
            width={780}
            className="misa-modal-top-20"
            footer={
                <Space>
                    <Button onClick={onCancel} className="misa-btn-secondary">Đóng</Button>
                    <Button
                        size="small"
                        icon={<DownloadOutlined />}
                        onClick={handleDownloadTemplate}
                        disabled
                    >
                        Tải tệp mẫu
                    </Button>
                    <Button type="primary" className="misa-btn-primary" disabled>
                        Thực hiện nhập khẩu
                    </Button>
                </Space>
            }
        >
            <Upload.Dragger
                name="file"
                multiple={false}
                accept=".xlsx,.xls"
                beforeUpload={(file) => {
                    setFileName(file.name);
                    message.info(`Đã chọn tệp cục bộ: ${file.name}`);
                    return false;
                }}
                className="misa-dragger-area"
            >
                <p className="ant-upload-drag-icon">
                    <InboxOutlined className="misa-upload-icon" />
                </p>
                <p className="misa-upload-title">
                    {fileName ? `Đã chọn: ${fileName}` : 'Kéo, thả tệp Excel vào đây hoặc bấm vào đây để chọn tệp'}
                </p>
                <p className="misa-upload-hint">
                    Có thể chọn tệp để xem tên tệp cục bộ; việc tải lên và nhập dữ liệu hiện chưa được hỗ trợ.
                </p>
            </Upload.Dragger>
        </Modal>
    );
};

export default ImportPurchaseExcelModal;
