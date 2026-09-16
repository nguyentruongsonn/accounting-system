import React, { useState } from 'react';
import { Form, Input, Button, Select, Row, Col } from 'antd';
import { toast as message } from '../feedback/toast';
import Modal from '../layout/AppModal';
import {
    QuestionCircleOutlined,
    CloseOutlined,
    BankOutlined,
    SafetyCertificateOutlined
} from '@ant-design/icons';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { MisaButton } from './MisaButton';
import ModalFrame from '../layout/ModalFrame';

const { TextArea } = Input;

export const VIETNAM_BANKS = [
    { code: 'VCB', shortName: 'Vietcombank', name: 'Ngân hàng Ngoại thương Việt Nam (Vietcombank)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/vcb.png' },
    { code: 'BIDV', shortName: 'BIDV', name: 'Ngân hàng Đầu tư và Phát triển Việt Nam (BIDV)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/bidv.png' },
    { code: 'CTG', shortName: 'VietinBank', name: 'Ngân hàng Công Thương Việt Nam (VietinBank)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/icb.png' },
    { code: 'VBA', shortName: 'Agribank', name: 'Ngân hàng Nông nghiệp & Phát triển Nông thôn (Agribank)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/vba.png' },
    { code: 'TCB', shortName: 'Techcombank', name: 'Ngân hàng Kỹ thương Việt Nam (Techcombank)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/tcb.png' },
    { code: 'MB', shortName: 'MBBank', name: 'Ngân hàng Quân đội (MBBank)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/mb.png' },
    { code: 'ACB', shortName: 'ACB', name: 'Ngân hàng Á Châu (ACB)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/acb.png' },
    { code: 'VPB', shortName: 'VPBank', name: 'Ngân hàng Việt Nam Thịnh Vượng (VPBank)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/vpb.png' },
    { code: 'TPB', shortName: 'TPBank', name: 'Ngân hàng Tiên Phong (TPBank)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/tpb.png' },
    { code: 'STB', shortName: 'Sacombank', name: 'Ngân hàng Sài Gòn Thương Tín (Sacombank)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/stb.png' },
    { code: 'VIB', shortName: 'VIB', name: 'Ngân hàng Quốc tế Việt Nam (VIB)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/vib.png' },
    { code: 'SHB', shortName: 'SHB', name: 'Ngân hàng Sài Gòn - Hà Nội (SHB)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/shb.png' },
    { code: 'MSB', shortName: 'MSB', name: 'Ngân hàng Hàng Hải (MSB)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/msb.png' },
    { code: 'HDB', shortName: 'HDBank', name: 'Ngân hàng Phát triển TP.HCM (HDBank)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/hdb.png' },
    { code: 'OCB', shortName: 'OCB', name: 'Ngân hàng Phương Đông (OCB)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/ocb.png' },
    { code: 'SSB', shortName: 'SeABank', name: 'Ngân hàng Đông Nam Á (SeABank)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/seab.png' },
    { code: 'BAB', shortName: 'Bac A Bank', name: 'Ngân hàng Bắc Á (Bac A Bank)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/baca.png' },
    { code: 'PVC', shortName: 'PVcomBank', name: 'Ngân hàng Đại chúng Việt Nam (PVcomBank)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/pvcom.png' },
    { code: 'NCB', shortName: 'NCB', name: 'Ngân hàng Quốc Dân (NCB)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/ncb.png' },
    { code: 'NAB', shortName: 'Nam A Bank', name: 'Ngân hàng Nam Á (Nam A Bank)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/namab.png' },
    { code: 'KLB', shortName: 'Kienlongbank', name: 'Ngân hàng Kiên Long (Kienlongbank)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/klb.png' },
    { code: 'VBB', shortName: 'VietBank', name: 'Ngân hàng Việt Nam Thương Tín (VietBank)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/vbb.png' },
    { code: 'BVB', shortName: 'BaoVietBank', name: 'Ngân hàng Bảo Việt (BaoVietBank)', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/baoviet.png' },
    { code: 'SHBVN', shortName: 'Shinhan Bank', name: 'Ngân hàng Shinhan Việt Nam', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/shinhan.png' },
    { code: 'HSBC', shortName: 'HSBC', name: 'Ngân hàng HSBC Việt Nam', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/hsbc.png' },
    { code: 'SCB', shortName: 'Standard Chartered', name: 'Ngân hàng Standard Chartered', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/scb.png' },
    { code: 'PBVN', shortName: 'Public Bank', name: 'Ngân hàng Public Bank Việt Nam', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/pbvn.png' },
    { code: 'UOB', shortName: 'UOB', name: 'Ngân hàng United Overseas Bank Việt Nam', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/uob.png' },
    { code: 'WRB', shortName: 'Woori Bank', name: 'Ngân hàng Woori Việt Nam', logo: 'https://img.mservice.com.vn/momo_app_v2/img/bank/woori.png' },
];

interface QuickAddBankAccountModalProps {
    open: boolean;
    onCancel: () => void;
    onSuccess?: (newAccount: any) => void;
}

export const QuickAddBankAccountModal: React.FC<QuickAddBankAccountModalProps> = ({
    open,
    onCancel,
    onSuccess
}) => {
    const [form] = Form.useForm();
    const queryClient = useQueryClient();
    const [selectedBank, setSelectedBank] = useState<any>(null);
    const [saveAndAddMore, setSaveAndAddMore] = useState(false);

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            return api.post('/bank/accounts', {
                account_number: values.account_number,
                bank_name: values.bank_name,
                bank_code: selectedBank?.code || values.bank_code || '',
                province: values.province || '',
                branch: values.branch || '',
                branch_address: values.branch_address || '',
                account_holder: values.account_holder || '',
                description: values.description || '',
                currency: values.currency || 'VND',
                is_active: true
            });
        },
        onSuccess: (res: any) => {
            const persistedAccount = res?.data;
            if (!persistedAccount || persistedAccount.id === undefined || persistedAccount.id === null) {
                message.error('Máy chủ không trả về tài khoản ngân hàng đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }

            message.success('Đã lưu tài khoản ngân hàng thành công!');
            queryClient.invalidateQueries({ queryKey: ['bank-accounts'] });

            if (onSuccess && res.data) {
                onSuccess(res.data);
            }

            if (saveAndAddMore) {
                form.resetFields();
                setSelectedBank(null);
            } else {
                form.resetFields();
                setSelectedBank(null);
                onCancel();
            }
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || 'Có lỗi xảy ra khi lưu tài khoản ngân hàng!');
        }
    });

    const handleSave = (addMore: boolean = false) => {
        setSaveAndAddMore(addMore);
        form.validateFields().then(values => {
            mutation.mutate(values);
        }).catch(() => {
            message.error('Vui lòng kiểm tra lại các trường bắt buộc!');
        });
    };

    const handleBankChange = (value: string) => {
        const bank = VIETNAM_BANKS.find(b => b.name === value || b.shortName === value || b.code === value);
        if (bank) {
            setSelectedBank(bank);
            form.setFieldsValue({
                bank_name: bank.name,
                bank_code: bank.code
            });
        }
    };

    return (
        <Modal
            open={open}
            onCancel={onCancel}
            width={640}
            centered={true}
            className="misa-clean-modal misa-quick-bank-modal"
            zIndex={2200}
            footer={null}
            closable={false}
            title={
                <div className="misa-bank-header-wrap">
                    <span className="misa-bank-header-title">Thêm Tài khoản ngân hàng</span>
                    <div className="misa-bank-header-actions">
                        <QuestionCircleOutlined className="misa-bank-header-icon" title="Trợ giúp" />
                        <CloseOutlined className="misa-bank-header-icon" onClick={onCancel} title="Đóng" />
                    </div>
                </div>
            }
        >
            <ModalFrame
                className="misa-quick-bank-modal__frame ui-modal-frame--simple"
                footer={(
                    <div className="misa-modal-footer misa-modal-footer--simple">
                        <MisaButton onClick={onCancel}>Hủy</MisaButton>
                        <MisaButton onClick={() => handleSave(false)} loading={mutation.isPending}>
                            Cất
                        </MisaButton>
                        <MisaButton variant="primary" onClick={() => handleSave(true)} loading={mutation.isPending}>
                            Cất và Thêm
                        </MisaButton>
                    </div>
                )}
            >
            <Form
                form={form}
                layout="vertical"
                size="small"
                className="misa-bank-form-layout ui-modal-form-grid--responsive"
                initialValues={{ currency: 'VND' }}
            >
                {/* Row 1: Số tài khoản, Tên ngân hàng, Tỉnh/Thành phố, Chi nhánh & Bank Logo Card */}
                <Row gutter={12} align="stretch">
                    <Col span={17}>
                        <Row gutter={10}>
                            <Col span={10}>
                                <Form.Item
                                    name="account_number"
                                    label="Số tài khoản"
                                    required
                                    rules={[{ required: true, message: 'Vui lòng nhập số tài khoản' }]}
                                    style={{ marginBottom: 12 }}
                                >
                                    <Input placeholder="Nhập số tài khoản" className="misa-input" />
                                </Form.Item>
                            </Col>
                            <Col span={14}>
                                <Form.Item
                                    name="bank_name"
                                    label="Tên ngân hàng"
                                    required
                                    rules={[{ required: true, message: 'Vui lòng chọn hoặc nhập tên ngân hàng' }]}
                                    style={{ marginBottom: 12 }}
                                >
                                    <Select
                                        showSearch
                                        allowClear
                                        placeholder="Chọn hoặc nhập tên ngân hàng"
                                        className="misa-input"
                                        onChange={handleBankChange}
                                        filterOption={(input, option) =>
                                            (option?.label ?? '').toLowerCase().includes(input.toLowerCase()) ||
                                            (option?.value ?? '').toLowerCase().includes(input.toLowerCase())
                                        }
                                        options={VIETNAM_BANKS.map(b => ({
                                            value: b.name,
                                            label: `${b.shortName} - ${b.name}`
                                        }))}
                                    />
                                </Form.Item>
                            </Col>
                        </Row>

                        <Row gutter={10}>
                            <Col span={10}>
                                <Form.Item name="province" label="Tỉnh/Thành phố" style={{ marginBottom: 0 }}>
                                    <Input placeholder="Tỉnh / Thành phố" className="misa-input" />
                                </Form.Item>
                            </Col>
                            <Col span={14}>
                                <Form.Item name="branch" label="Chi nhánh" style={{ marginBottom: 0 }}>
                                    <Input placeholder="Chi nhánh ngân hàng" className="misa-input" />
                                </Form.Item>
                            </Col>
                        </Row>
                    </Col>

                    {/* Right Bank Logo / Card Preview */}
                    <Col span={7}>
                        <div className="misa-bank-card-preview">
                            {selectedBank ? (
                                <>
                                    <img
                                        src={selectedBank.logo}
                                        alt={selectedBank.shortName}
                                        className="misa-bank-logo-img"
                                        onError={(e: any) => { e.target.style.display = 'none'; }}
                                    />
                                    <span className="misa-bank-preview-text">{selectedBank.shortName}</span>
                                </>
                            ) : (
                                <>
                                    <BankOutlined style={{ fontSize: 28, color: '#9ca3af', marginBottom: 4 }} />
                                    <span className="misa-bank-preview-text">Chọn ngân hàng</span>
                                </>
                            )}
                        </div>
                    </Col>
                </Row>

                {/* Row 2: Địa chỉ chi nhánh */}
                <Form.Item name="branch_address" label="Địa chỉ chi nhánh" style={{ marginBottom: 12 }}>
                    <Input placeholder="Địa chỉ chi tiết của chi nhánh ngân hàng" className="misa-input" />
                </Form.Item>

                {/* Row 3: Chủ tài khoản */}
                <Form.Item name="account_holder" label="Chủ tài khoản" style={{ marginBottom: 12 }}>
                    <Input placeholder="Nhập tên chủ tài khoản" className="misa-input" />
                </Form.Item>

                {/* Row 4: Diễn giải */}
                <Form.Item name="description" label="Diễn giải" style={{ marginBottom: 8 }}>
                    <TextArea rows={2} placeholder="Nhập diễn giải..." className="misa-input" style={{ height: 'auto', minHeight: 52 }} />
                </Form.Item>

                {/* Banner: Kết nối ngân hàng điện tử */}
                <div className="misa-ebank-banner">
                    <div className="misa-ebank-banner-left">
                        <div className="misa-ebank-badge-icon">
                            <SafetyCertificateOutlined />
                        </div>
                        <div className="misa-ebank-banner-text">
                            <strong>Tự động thêm nhiều tài khoản ngân hàng cùng lúc chỉ trong 15 giây với </strong>
                            <a href="#ebank" onClick={(e) => { e.preventDefault(); message.info('Tính năng kết nối Ngân hàng điện tử MISA eBank'); }} className="misa-ebank-link">
                                Ngân hàng điện tử
                            </a>
                        </div>
                    </div>
                    <Button
                        className="misa-btn-connect-ebank"
                        onClick={() => message.info('Đang mở cổng kết nối Ngân hàng điện tử...')}
                    >
                        Kết nối ngay
                    </Button>
                </div>
            </Form>

            </ModalFrame>
        </Modal>
    );
};

export default QuickAddBankAccountModal;
