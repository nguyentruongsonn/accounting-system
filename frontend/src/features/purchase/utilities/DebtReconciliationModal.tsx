import React, { useState } from 'react';
import { Button, Select, Upload, Space } from 'antd';
import { toast as message } from '../../../components/feedback/toast';
import Modal from '../../../components/layout/AppModal';
import {
    FileExcelOutlined,
    UploadOutlined
} from '@ant-design/icons';

interface Props {
    open: boolean;
    onCancel: () => void;
}

export const DebtReconciliationModal: React.FC<Props> = ({ open, onCancel }) => {
    const [supplierCode, setSupplierCode] = useState('');
    const [fileList, setFileList] = useState<any[]>([]);
    const [mappingTemplate, setMappingTemplate] = useState('s10_dn');

    const handleReconcile = () => {
        if (fileList.length === 0) {
            message.warning('Vui lòng chọn tệp Excel báo cáo công nợ từ nhà cung cấp!');
            return;
        }

        message.info('Backend chưa công bố API tiếp nhận và đối chiếu file công nợ; không ghi nhận kết quả cục bộ.');
    };

    return (
        <Modal
            title={
                <div className="misa-modal-title">
                    <FileExcelOutlined className="misa-text-green-bold text-xl" />
                    <span className="misa-font-18-bold">
                        Đối chiếu công nợ Nhà cung cấp (Tự động từ file Excel)
                    </span>
                </div>
            }
            open={open}
            onCancel={onCancel}
            width={600}
            footer={
                <div className="misa-modal-footer">
                    <Button onClick={onCancel} className="misa-btn-cancel">Hủy</Button>
                    <Space>
                        <Button
                            type="primary"
                            className="misa-btn-modal-action"
                            onClick={handleReconcile}
                            disabled
                        >
                            Bắt đầu đối chiếu
                        </Button>
                    </Space>
                </div>
            }
        >
            <div className="misa-flex-col-gap-14 pt-2">
                <div>
                    <div className="misa-label-bold">Nhà cung cấp cần đối chiếu *:</div>
                    <Select
                        className="misa-w-full"
                        value={supplierCode || undefined}
                        onChange={setSupplierCode}
                        disabled
                        placeholder="Chưa có dữ liệu nhà cung cấp từ backend"
                        options={[]}
                    />
                </div>

                <div>
                    <div className="misa-label-bold">Tệp báo cáo công nợ từ nhà cung cấp (.xls, .xlsx) *:</div>
                    <Upload
                        fileList={fileList}
                        disabled
                        beforeUpload={(file) => {
                            setFileList([file]);
                            return false;
                        }}
                        onRemove={() => setFileList([])}
                    >
                        <Button disabled icon={<UploadOutlined />}>Chọn file Excel công nợ</Button>
                    </Upload>
                    <div className="misa-upload-hint-sm">Hỗ trợ file Excel định dạng .xlsx, .xls tối đa 5MB</div>
                </div>

                <div>
                    <div className="misa-label-bold">Mẫu báo cáo đối chiếu *:</div>
                    <Select
                        className="misa-w-full"
                        value={mappingTemplate}
                        onChange={setMappingTemplate}
                        options={[
                            { value: 's10_dn', label: 'S01 - Sổ chi tiết công nợ phải trả người bán (S10-DN)' },
                            { value: 'bk_hhdv', label: 'S02 - Bảng kê đối chiếu phát sinh mua hàng & thanh toán' }
                        ]}
                    />
                </div>
            </div>
        </Modal>
    );
};

export default DebtReconciliationModal;
