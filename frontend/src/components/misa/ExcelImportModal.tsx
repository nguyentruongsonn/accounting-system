import React, { useState } from 'react';
import { Steps, Button, Upload, Table, Tag, Radio, Select, Alert } from 'antd';
import { toast as message } from '../feedback/toast';
import Modal from '../layout/AppModal';
import type { ColumnsType } from 'antd/es/table';
import {
    InboxOutlined, 
    DownloadOutlined, 
    CheckCircleOutlined, 
    CloseCircleOutlined,
    FileExcelOutlined,
    ArrowRightOutlined,
    ArrowLeftOutlined
} from '@ant-design/icons';
import ModalFrame from '../layout/ModalFrame';

interface ExcelImportModalProps {
    open: boolean;
    onClose: () => void;
    voucherType: 'receipt' | 'payment';
    onSuccess?: (count: number) => void;
}

export const ExcelImportModal: React.FC<ExcelImportModalProps> = ({
    open,
    onClose,
    voucherType
}) => {
    const [currentStep, setCurrentStep] = useState(0);
    const [fileName, setFileName] = useState<string | null>(null);
    const [importMode, setImportMode] = useState<'insert' | 'update'>('insert');

    const [previewData] = useState<any[]>([]);

    const handleDownloadTemplate = () => {
        message.info('Backend chưa công bố API tải mẫu hoặc nhập dữ liệu Excel cho chứng từ này.');
    };

    const handleFinish = () => {
        message.info('Backend chưa công bố API nhập Excel; không tạo chứng từ cục bộ.');
    };

    const columns: ColumnsType<any> = [
        {
            title: 'Trạng thái',
            dataIndex: 'status',
            key: 'status',
            width: 110,
            align: 'center',
            render: (st, record) => st === 'valid' ? (
                <Tag color="success" icon={<CheckCircleOutlined />}>Hợp lệ</Tag>
            ) : (
                <Tag color="error" icon={<CloseCircleOutlined />} title={record.error}>Lỗi</Tag>
            )
        },
        {
            title: 'Ngày HT',
            dataIndex: 'posting_date',
            key: 'posting_date',
            width: 100
        },
        {
            title: 'Số chứng từ',
            dataIndex: 'voucher_number',
            key: 'voucher_number',
            width: 110,
            render: (text) => <span className="misa-fw-700 misa-color-blue">{text}</span>
        },
        {
            title: 'Mã ĐT',
            dataIndex: 'contact_code',
            key: 'contact_code',
            width: 100
        },
        {
            title: 'Tên đối tượng',
            dataIndex: 'contact_name',
            key: 'contact_name',
            width: 180
        },
        {
            title: 'Diễn giải / Lý do',
            dataIndex: 'reason',
            key: 'reason'
        },
        {
            title: 'TK Nợ',
            dataIndex: 'debit_account',
            key: 'debit_account',
            width: 80,
            align: 'center'
        },
        {
            title: 'TK Có',
            dataIndex: 'credit_account',
            key: 'credit_account',
            width: 80,
            align: 'center'
        },
        {
            title: 'Số tiền',
            dataIndex: 'amount',
            key: 'amount',
            align: 'right',
            width: 130,
            render: (val) => `${new Intl.NumberFormat('vi-VN').format(Number(val) || 0)} ₫`
        },
        {
            title: 'Mô tả lỗi',
            dataIndex: 'error',
            key: 'error',
            render: (text) => text ? <span className="misa-color-red misa-fs-12">{text}</span> : '-'
        }
    ];

    return (
        <Modal
            title={
                <div className="misa-flex-between misa-pr-24">
                    <span className="misa-modal-title">
                        Nhập khẩu dữ liệu {voucherType === 'receipt' ? 'Phiếu Thu' : 'Phiếu Chi'} từ Excel
                    </span>
                </div>
            }
            open={open}
            onCancel={onClose}
            width={1100}
            footer={null}
            centered
            className="misa-custom-modal"
        >
            <ModalFrame
                className="misa-excel-import-modal__frame"
                footer={
                <div className="misa-flex-between">
                    <div>
                        {currentStep === 0 && (
                            <Button icon={<DownloadOutlined />} onClick={handleDownloadTemplate} disabled>
                                Tải tệp mẫu chuẩn MISA
                            </Button>
                        )}
                    </div>
                    <div className="misa-flex misa-gap-8">
                        {currentStep > 0 && (
                            <Button icon={<ArrowLeftOutlined />} onClick={() => setCurrentStep(currentStep - 1)}>
                                Quay lại
                            </Button>
                        )}
                        {currentStep < 2 && (
                            <Button 
                                type="primary" 
                                className="misa-btn-primary"
                                disabled
                                onClick={() => {
                                    if (currentStep === 0 && !fileName) return;
                                    setCurrentStep(currentStep + 1);
                                }}
                            >
                                Tiếp tục <ArrowRightOutlined />
                            </Button>
                        )}
                        {currentStep === 2 && (
                            <Button type="primary" className="misa-btn-primary" onClick={handleFinish} disabled>
                                <CheckCircleOutlined /> Thực hiện nhập khẩu
                            </Button>
                        )}
                    </div>
                </div>
                }
            >
            <Alert className="apple-section-gap" type="warning" showIcon message="Nhập Excel chưa khả dụng" description="Backend chưa công bố endpoint upload/validate/import Excel cho phiếu thu, phiếu chi hoặc chứng từ mua hàng. Giao diện không báo nhập thành công giả." />
            <div className="misa-mb-24">
                <Steps
                    current={currentStep}
                    items={[
                        { title: 'Chọn tệp nguồn' },
                        { title: 'Ghép cột' },
                        { title: 'Kiểm tra dữ liệu' }
                    ]}
                />
            </div>

            {currentStep === 0 && (
                <div>
                    <div className="apple-section-gap">
                        <Upload.Dragger
                            name="file"
                            multiple={false}
                            accept=".xlsx,.xls,.csv"
                            beforeUpload={(file) => {
                                setFileName(file.name);
                                message.info(`Đã chọn tệp cục bộ: ${file.name}. Chưa tải lên máy chủ.`);
                                return false;
                            }}
                            className="misa-upload-box"
                        >
                            <p className="ant-upload-drag-icon">
                                <InboxOutlined className="misa-color-blue" />
                            </p>
                            <p className="ant-upload-text">Kéo thả tệp Excel vào đây hoặc bấm để chọn tệp</p>
                            <p className="ant-upload-hint">Hỗ trợ định dạng .xlsx, .xls (Dung lượng tối đa 10MB)</p>
                            {fileName && (
                                <div className="misa-mt-12">
                                    <Tag color="blue" icon={<FileExcelOutlined />}>
                                        {fileName}
                                    </Tag>
                                </div>
                            )}
                        </Upload.Dragger>
                    </div>

                    <div className="misa-bg-light misa-p-12 misa-rounded-6">
                        <span className="misa-field-label">Chế độ nhập khẩu:</span>
                        <Radio.Group value={importMode} onChange={e => setImportMode(e.target.value)} className="misa-mt-6">
                            <Radio value="insert">Thêm mới (Bỏ qua nếu trùng số chứng từ)</Radio>
                            <Radio value="update">Cập nhật (Ghi đè nếu trùng số chứng từ)</Radio>
                        </Radio.Group>
                    </div>
                </div>
            )}

            {currentStep === 1 && (
                 <div>
                     <p className="apple-muted-text">Chưa có dữ liệu ánh xạ từ máy chủ.</p>
                     <table className="misa-voucher-table">
                        <thead>
                            <tr>
                                <th>Trường dữ liệu phần mềm</th>
                                <th>Cột trong tệp Excel</th>
                                <th>Mẫu dữ liệu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Ngày hạch toán (*)</strong></td>
                                <td><Select className="misa-w-full" placeholder="Chưa có ánh xạ từ máy chủ" disabled options={[]} /></td>
                                <td>—</td>
                            </tr>
                            <tr>
                                <td><strong>Số chứng từ (*)</strong></td>
                                <td><Select className="misa-w-full" placeholder="Chưa có ánh xạ từ máy chủ" disabled options={[]} /></td>
                                <td>—</td>
                            </tr>
                            <tr>
                                <td><strong>Mã đối tượng</strong></td>
                                <td><Select className="misa-w-full" placeholder="Chưa có ánh xạ từ máy chủ" disabled options={[]} /></td>
                                <td>—</td>
                            </tr>
                            <tr>
                                <td><strong>TK Nợ (*)</strong></td>
                                <td><Select className="misa-w-full" placeholder="Chưa có ánh xạ từ máy chủ" disabled options={[]} /></td>
                                <td>—</td>
                            </tr>
                            <tr>
                                <td><strong>TK Có (*)</strong></td>
                                <td><Select className="misa-w-full" placeholder="Chưa có ánh xạ từ máy chủ" disabled options={[]} /></td>
                                <td>—</td>
                            </tr>
                            <tr>
                                <td><strong>Số tiền (*)</strong></td>
                                <td><Select className="misa-w-full" placeholder="Chưa có ánh xạ từ máy chủ" disabled options={[]} /></td>
                                <td>—</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            )}

            {currentStep === 2 && (
                <div>
                    <div className="misa-flex-between misa-mb-12">
                        <Tag color="warning">Chưa có dữ liệu kiểm tra từ máy chủ</Tag>
                    </div>

                    <Table
                        columns={columns}
                        dataSource={previewData}
                        size="small"
                        pagination={false}
                        bordered
                    />
                </div>
            )}
            </ModalFrame>
        </Modal>
    );
};
