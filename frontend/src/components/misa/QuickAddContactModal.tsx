import React, { useState, useEffect, useMemo } from 'react';
import { Form, Input, Radio, Checkbox, Button, Select, InputNumber, Tabs, DatePicker, Popconfirm, Row, Col } from 'antd';
import { toast as message } from '../feedback/toast';
import Modal from '../layout/AppModal';
import { 
    PlusOutlined, 
    DeleteOutlined, 
    SearchOutlined,
    QuestionCircleOutlined,
    EditOutlined
} from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import QuickAddEmployeeModal from './QuickAddEmployeeModal';
import { QuickAddPaymentTermModal } from './QuickAddPaymentTermModal';
import ModalFrame from '../layout/ModalFrame';
import { MisaButton } from './MisaButton';


interface QuickAddContactModalProps {
    open: boolean;
    onCancel: () => void;
    contactType?: 'customer' | 'supplier';
    onSuccess?: (newContact: any) => void;
}

interface BankAccountRow {
    id: string;
    account_number: string;
    bank_name: string;
    branch: string;
    province: string;
}

interface DeliveryAddressRow {
    id: string;
    address: string;
    receiver_name: string;
    receiver_phone: string;
}

export const QuickAddContactModal: React.FC<QuickAddContactModalProps> = ({
    open,
    onCancel,
    contactType = 'customer',
    onSuccess
}) => {
    const [personType, setPersonType] = useState<'org' | 'personal'>('org');
    const [isCustomerChecked, setIsCustomerChecked] = useState(contactType === 'customer');
    const [isSupplierChecked, setIsSupplierChecked] = useState(contactType === 'supplier');
    const [isInternalChecked, setIsInternalChecked] = useState(false);
    const [activeTab, setActiveTab] = useState('1');

    // Sub-modals
    const [isEmployeeModalOpen, setIsEmployeeModalOpen] = useState(false);
    const [isGroupModalOpen, setIsGroupModalOpen] = useState(false);
    const [isPaymentTermModalOpen, setIsPaymentTermModalOpen] = useState(false);
    const [groupForm] = Form.useForm();

    // Table rows inside tabs
    const [bankAccounts, setBankAccounts] = useState<BankAccountRow[]>([
        { id: '1', account_number: '', bank_name: '', branch: '', province: '' }
    ]);
    const [deliveryAddresses, setDeliveryAddresses] = useState<DeliveryAddressRow[]>([
        { id: '1', address: '', receiver_name: '', receiver_phone: '' }
    ]);

    // Dropdown options
    const [contactGroups, setContactGroups] = useState([
        { value: 'VIP', label: 'Khách hàng VIP' },
        { value: 'Doanh nghiệp lớn', label: 'Doanh nghiệp lớn' },
        { value: 'SME', label: 'Doanh nghiệp vừa và nhỏ' },
        { value: 'Đại lý phân phối', label: 'Đại lý phân phối' },
        { value: 'Khách lẻ', label: 'Khách hàng cá nhân lẻ' },
    ]);

    const [paymentTerms, setPaymentTerms] = useState([
        { value: 'Thanh toán ngay', label: 'Thanh toán ngay (COD)', days: 0 },
        { value: 'Gối đầu 15 ngày', label: 'Gối đầu 15 ngày', days: 15 },
        { value: 'Gối đầu 30 ngày', label: 'Gối đầu 30 ngày', days: 30 },
        { value: 'Gối đầu 45 ngày', label: 'Gối đầu 45 ngày', days: 45 },
        { value: 'Gối đầu 60 ngày', label: 'Gối đầu 60 ngày', days: 60 },
    ]);

    const [customFieldLabels, setCustomFieldLabels] = useState([
        'Trường mở rộng 1',
        'Trường mở rộng 2',
        'Trường mở rộng 3',
        'Trường mở rộng 4',
        'Trường mở rộng 5'
    ]);
    const [editingFieldIdx, setEditingFieldIdx] = useState<number | null>(null);
    const [labelForm] = Form.useForm();

    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const isCustomer = contactType === 'customer';
    const apiEndpoint = isCustomer ? '/master/customers' : '/master/suppliers';
    const queryKey = isCustomer ? 'customers' : 'suppliers';

    // Fetch employees for dropdown
    const { data: employees = [] } = useQuery({
        queryKey: ['employees'],
        queryFn: async () => {
            const { data } = await api.get('/master/employees');
            return Array.isArray(data) ? data : (data?.data || []);
        }
    });

    // Debt-account choices are evidence from the tenant catalogue only.  Do
    // not preselect or manufacture 131/331 (or any other account) in this
    // contact helper when the server has not published a usable catalogue.
    const { data: rawAccounts = [] } = useQuery({
        queryKey: ['chart-of-accounts'],
        enabled: open,
        queryFn: async () => {
            const { data } = await api.get('/master/accounts');
            if (Array.isArray(data)) return data;
            if (Array.isArray(data?.data)) return data.data;
            if (Array.isArray(data?.data?.data)) return data.data.data;
            return [];
        },
    });
    const debtAccountOptions = useMemo(() => (Array.isArray(rawAccounts) ? rawAccounts : [])
        .filter((account: any) => typeof account?.code === 'string'
            && account.code.trim() !== ''
            && account.is_parent !== true
            && account.is_active !== false)
        .map((account: any) => {
            const code = account.code.trim();
            const name = String(account.name ?? account.account_name ?? '').trim();
            return { value: code, label: name ? `${code} - ${name}` : code };
        }), [rawAccounts]);

    // Keyboard shortcuts (Ctrl+S, Ctrl+Shift+S, Esc)
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if (!open) return;
            if (e.key === 'Escape' && !isEmployeeModalOpen && !isGroupModalOpen && !isPaymentTermModalOpen) {
                e.preventDefault();
                onCancel();
            } else if (e.ctrlKey && e.shiftKey && e.key.toLowerCase() === 's') {
                e.preventDefault();
                handleSave(true);
            } else if (e.ctrlKey && e.key.toLowerCase() === 's') {
                e.preventDefault();
                handleSave(false);
            }
        };
        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [open, isEmployeeModalOpen, isGroupModalOpen, isPaymentTermModalOpen]);

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            const autoCode = values.code?.trim();
            if (!autoCode) {
                throw new Error(`Mã ${isCustomer ? 'khách hàng' : 'nhà cung cấp'} phải do người dùng nhập hoặc máy chủ cấp.`);
            }
            const payload: any = {
                code: autoCode,
                customer_type: personType,
                supplier_type: personType,
                is_customer: isCustomerChecked,
                is_supplier: isSupplierChecked,
                is_internal: isInternalChecked,
                is_employee: values.is_employee || false,
                name: values.name,
                tax_code: values.tax_code || '',
                dvqhns_code: values.dvqhns_code || '',
                address: values.address || '',
                country: values.country || 'Việt Nam',
                province: values.province || '',
                district: values.district || '',
                ward: values.ward || '',
                same_as_main_address: values.same_as_main_address || false,
                phone: values.phone || values.mobile_phone || '',
                mobile_phone: values.mobile_phone || values.phone || '',
                landline_phone: values.landline_phone || '',
                email: values.email || '',
                contact_group: values.contact_group || '',
                assigned_employee_id: values.assigned_employee_id || '',

                // Org fields
                website: values.website || '',
                legal_representative: values.legal_representative || '',
                contact_person_salutation: values.contact_person_salutation || 'Ông',
                contact_person_name: values.contact_person_name || '',
                contact_person_title: values.contact_person_title || '',
                contact_person_phone: values.contact_person_phone || '',
                contact_person_email: values.contact_person_email || '',
                einvoice_contact_name: values.einvoice_contact_name || '',
                einvoice_contact_email: values.einvoice_contact_email || '',
                einvoice_contact_phone: values.einvoice_contact_phone || '',

                // Individual fields
                identity_card_number: values.identity_card_number || '',
                identity_card_date: values.identity_card_date ? values.identity_card_date.format('YYYY-MM-DD') : null,
                identity_card_place: values.identity_card_place || '',
                passport_number: values.passport_number || '',
                gender: values.gender || 'Nam',
                birth_date: values.birth_date ? values.birth_date.format('YYYY-MM-DD') : null,

                // Terms & Debt: preserve only explicit user/API evidence.
                // The backend contract currently permits these fields to be
                // omitted; do not choose a statutory account or credit policy
                // in the browser.
                default_account: values.debt_account || undefined,
                payment_term: values.payment_term || undefined,
                due_days: values.due_days ?? undefined,
                debt_limit: values.debt_limit ?? undefined,
                debt_account: values.debt_account || undefined,

                // Sub-tables & Extra
                bank_accounts: bankAccounts.filter(b => b.account_number || b.bank_name),
                delivery_addresses: deliveryAddresses.filter(d => d.address || d.receiver_name),
                note: values.note || '',
                custom_field_1: values.custom_field_1 || '',
                custom_field_2: values.custom_field_2 || '',
                custom_field_3: values.custom_field_3 || '',
                custom_field_4: values.custom_field_4 || '',
                custom_field_5: values.custom_field_5 || '',
                is_active: true
            };

            return api.post(apiEndpoint, payload);
        },
        onSuccess: (res: any, variables: any) => {
            const persistedContact = res?.data;
            if (!persistedContact || persistedContact.id === undefined || persistedContact.id === null) {
                message.error('Máy chủ không trả về đối tượng đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }

            message.success(`Đã lưu ${isCustomer ? 'khách hàng' : 'nhà cung cấp'} vào hệ thống thành công!`);
            queryClient.invalidateQueries({ queryKey: [queryKey] });
            if (onSuccess && res.data) {
                onSuccess(res.data);
            }
            if (variables._andNew) {
                initNewForm();
            } else {
                form.resetFields();
                onCancel();
            }
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || err.message || 'Có lỗi xảy ra khi lưu đối tượng!');
        }
    });

    const initNewForm = () => {
        form.resetFields();
        form.setFieldsValue({
            code: undefined,
            name: '',
            tax_code: '',
            dvqhns_code: '',
            phone: '',
            mobile_phone: '',
            landline_phone: '',
            address: '',
            country: 'Việt Nam',
            email: '',
            website: '',
            gender: 'Nam',
            contact_person_salutation: 'Ông',
            salutation: 'Ông',
            due_days: undefined,
            debt_limit: undefined,
            debt_account: undefined,
            payment_term: undefined
        });
        setBankAccounts([{ id: '1', account_number: '', bank_name: '', branch: '', province: '' }]);
        setDeliveryAddresses([{ id: '1', address: '', receiver_name: '', receiver_phone: '' }]);
    };

    const handleSave = (andNew = false) => {
        form.validateFields()
            .then(values => {
                mutation.mutate({ ...values, _andNew: andNew });
            })
            .catch(() => {
                message.error(`Vui lòng kiểm tra lại các trường bắt buộc màu đỏ!`);
            });
    };

    useEffect(() => {
        if (open) {
            initNewForm();
            setIsCustomerChecked(isCustomer);
            setIsSupplierChecked(!isCustomer);
            setIsInternalChecked(false);
        }
    }, [open, isCustomer]);

    return (
        <>
            <Modal
                title={
                    <div className="misa-flex-align-gap16">
                        <span className="misa-modal-title">
                            Thông tin {isCustomer ? 'khách hàng' : 'nhà cung cấp'}
                        </span>
                        <Radio.Group value={personType} onChange={(e) => setPersonType(e.target.value)} size="small">
                            <Radio value="org">Tổ chức</Radio>
                            <Radio value="personal">Cá nhân</Radio>
                        </Radio.Group>
                        <Checkbox checked={isCustomerChecked} onChange={e => setIsCustomerChecked(e.target.checked)}>
                            Là khách hàng
                        </Checkbox>
                        <Checkbox checked={isSupplierChecked} onChange={e => setIsSupplierChecked(e.target.checked)}>
                            Là nhà cung cấp
                        </Checkbox>
                    </div>
                }
                open={open}
                onCancel={onCancel}
                width={1040}
                centered={true}
                destroyOnHidden={true}
                className="misa-clean-modal"
                zIndex={2000}
                footer={
                    <div className="misa-modal-footer">
                        <MisaButton onClick={onCancel}>
                            Hủy
                        </MisaButton>
                        <div className="misa-flex-gap-8">
                            <MisaButton
                                onClick={() => handleSave(false)} 
                                loading={mutation.isPending}
                            >
                                Cất
                            </MisaButton>
                            <MisaButton
                                variant="primary"
                                loading={mutation.isPending}
                                onClick={() => handleSave(true)}
                            >
                                Cất và Thêm
                            </MisaButton>
                        </div>
                    </div>
                }
            >
                <ModalFrame className="misa-quick-contact-modal__frame ui-modal-frame--simple">
                <Form form={form} layout="vertical" size="small" className="misa-pt-4">
                    {/* Master Form Area: Unified Aligned Rows */}
                    {personType === 'org' ? (
                        <div className="misa-modal-contact-master-container misa-mb-8">
                            {/* Row 1: MST | DVQHNS | Mã KH | Điện thoại */}
                            <Row gutter={[16, 8]}>
                                <Col span={6}>
                                    <Form.Item name="tax_code" label="Mã số thuế/CCCD chủ hộ">
                                        <Input className="misa-input" suffix={<SearchOutlined className="apple-muted-text" />} placeholder="Nhập MST..." />
                                    </Form.Item>
                                </Col>
                                <Col span={5}>
                                    <Form.Item name="dvqhns_code" label="Mã số ĐVQHNS">
                                        <Input className="misa-input" placeholder="Mã ĐVQHNS..." />
                                    </Form.Item>
                                </Col>
                                <Col span={7}>
                                    <Form.Item 
                                        name="code" 
                                        label={<span>Mã {isCustomer ? 'khách hàng' : 'nhà cung cấp'} <span className="misa-text-danger">*</span></span>}
                                        rules={[{ required: true, message: 'Vui lòng nhập mã!' }]}
                                    >
                                        <Input className="misa-input" placeholder={isCustomer ? 'Mã khách hàng...' : 'Mã nhà cung cấp...'} />
                                    </Form.Item>
                                </Col>
                                <Col span={6}>
                                    <Form.Item name="phone" label="Điện thoại">
                                        <Input className="misa-input" placeholder="Số điện thoại..." />
                                    </Form.Item>
                                </Col>
                            </Row>

                            {/* Row 2: Tên đối tượng | Nhóm đối tượng */}
                            <Row gutter={[16, 8]}>
                                <Col span={14}>
                                    <Form.Item 
                                        name="name" 
                                        label={<span>Tên {isCustomer ? 'khách hàng' : 'nhà cung cấp'} <span className="misa-text-danger">*</span></span>}
                                        rules={[{ required: true, message: 'Vui lòng nhập tên đối tượng!' }]}
                                    >
                                        <Input className="misa-input" placeholder="Tên công ty / tổ chức..." />
                                    </Form.Item>
                                </Col>
                                <Col span={10}>
                                    <Form.Item label={isSupplierChecked ? 'Nhóm KH, NCC' : 'Nhóm khách hàng'}>
                                        <div className="misa-input-group">
                                            <Form.Item name="contact_group" noStyle>
                                                <Select 
                                                    variant="borderless"
                                                    allowClear
                                                    placeholder="Chọn nhóm"
                                                    className="misa-w-full"
                                                    options={contactGroups}
                                                />
                                            </Form.Item>
                                            <button 
                                                type="button" 
                                                className="misa-plus-btn" 
                                                title="Thêm nhóm mới"
                                                onClick={() => setIsGroupModalOpen(true)}
                                            >
                                                <PlusOutlined className="misa-font-11" />
                                            </button>
                                        </div>
                                    </Form.Item>
                                </Col>
                            </Row>

                            {/* Row 3: Địa chỉ | Nhân viên bán hàng */}
                            <Row gutter={[16, 8]}>
                                <Col span={14}>
                                    <Form.Item name="address" label="Địa chỉ">
                                        <Input className="misa-input" placeholder="VD: Số 82 Duy Tân, Dịch Vọng Hậu, Cầu Giấy, Hà Nội" />
                                    </Form.Item>
                                </Col>
                                <Col span={10}>
                                    <Form.Item label="Nhân viên bán hàng">
                                        <div className="misa-input-group">
                                            <Form.Item name="assigned_employee_id" noStyle>
                                                <Select 
                                                    variant="borderless"
                                                    allowClear
                                                    placeholder="Chọn nhân viên"
                                                    className="misa-w-full"
                                                    options={employees.map((e: any) => ({
                                                        value: e.id || e.code,
                                                        label: `${e.code} - ${e.name}`
                                                    }))}
                                                />
                                            </Form.Item>
                                            <button 
                                                type="button" 
                                                className="misa-plus-btn" 
                                                title="Thêm nhân viên mới"
                                                onClick={() => setIsEmployeeModalOpen(true)}
                                            >
                                                <PlusOutlined className="misa-font-11" />
                                            </button>
                                        </div>
                                    </Form.Item>
                                </Col>
                            </Row>

                            {/* Row 4: Website | Checkbox Là đối tượng nội bộ */}
                            <Row gutter={[16, 8]} align="middle">
                                <Col span={14}>
                                    <Form.Item name="website" label="Website">
                                        <Input className="misa-input" placeholder="https://example.com" />
                                    </Form.Item>
                                </Col>
                                <Col span={10} className="misa-pt-12">
                                    <div className="misa-flex-align-gap6">
                                        <Checkbox checked={isInternalChecked} onChange={e => setIsInternalChecked(e.target.checked)}>
                                            Là Đối tượng nội bộ
                                        </Checkbox>
                                        <QuestionCircleOutlined className="misa-icon-help-muted cursor-pointer" />
                                    </div>
                                </Col>
                            </Row>
                        </div>
                    ) : (
                        /* Individual Aligned Rows */
                        <div className="misa-modal-contact-master-container misa-mb-8">
                            {/* Row 1: Số CCCD | Ngày cấp | Nơi cấp | Mã đối tượng */}
                            <Row gutter={[16, 8]}>
                                <Col span={6}>
                                    <Form.Item name="identity_card_number" label="Số CCCD/CMND">
                                        <Input className="misa-input" suffix={<SearchOutlined className="apple-muted-text" />} placeholder="Số CCCD..." />
                                    </Form.Item>
                                </Col>
                                <Col span={5}>
                                    <Form.Item name="identity_card_date" label="Ngày cấp">
                                        <DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" placeholder="DD/MM/YYYY" />
                                    </Form.Item>
                                </Col>
                                <Col span={7}>
                                    <Form.Item name="identity_card_place" label="Nơi cấp">
                                        <Input className="misa-input" placeholder="Công an tỉnh/thành..." />
                                    </Form.Item>
                                </Col>
                                <Col span={6}>
                                    <Form.Item 
                                        name="code" 
                                        label={<span>Mã {isCustomer ? 'khách hàng' : 'nhà cung cấp'} <span className="misa-text-danger">*</span></span>}
                                        rules={[{ required: true, message: 'Vui lòng nhập mã!' }]}
                                    >
                                        <Input className="misa-input" placeholder={isCustomer ? 'Mã khách hàng...' : 'Mã nhà cung cấp...'} />
                                    </Form.Item>
                                </Col>
                            </Row>

                            {/* Row 2: Xưng hô + Họ và tên | Mã số thuế | Nhóm đối tượng */}
                            <Row gutter={[16, 8]}>
                                <Col span={10}>
                                    <Form.Item 
                                        label={<span>Họ và tên <span className="misa-text-danger">*</span></span>}
                                        required
                                    >
                                        <div className="misa-flex-gap-6">
                                            <Form.Item name="salutation" noStyle initialValue="Ông">
                                                <Select className="misa-w-85" options={[{ value: 'Ông', label: 'Ông' }, { value: 'Bà', label: 'Bà' }, { value: 'Anh', label: 'Anh' }, { value: 'Chị', label: 'Chị' }]} />
                                            </Form.Item>
                                            <Form.Item 
                                                name="name" 
                                                noStyle
                                                rules={[{ required: true, message: 'Vui lòng nhập họ và tên!' }]}
                                            >
                                                <Input className="misa-input misa-flex-1" placeholder="Họ và tên cá nhân" />
                                            </Form.Item>
                                        </div>
                                    </Form.Item>
                                </Col>
                                <Col span={4}>
                                    <Form.Item name="tax_code" label="Mã số thuế cá nhân">
                                        <Input className="misa-input" placeholder="MST cá nhân..." />
                                    </Form.Item>
                                </Col>
                                <Col span={10}>
                                    <Form.Item label={isSupplierChecked ? 'Nhóm KH, NCC' : 'Nhóm khách hàng'}>
                                        <div className="misa-input-group">
                                            <Form.Item name="contact_group" noStyle>
                                                <Select 
                                                    variant="borderless"
                                                    allowClear
                                                    placeholder="Chọn nhóm"
                                                    className="misa-w-full"
                                                    options={contactGroups}
                                                />
                                            </Form.Item>
                                            <button 
                                                type="button" 
                                                className="misa-plus-btn" 
                                                title="Thêm nhóm mới"
                                                onClick={() => setIsGroupModalOpen(true)}
                                            >
                                                <PlusOutlined className="misa-font-11" />
                                            </button>
                                        </div>
                                    </Form.Item>
                                </Col>
                            </Row>

                            {/* Row 3: Địa chỉ | Nhân viên phụ trách */}
                            <Row gutter={[16, 8]}>
                                <Col span={14}>
                                    <Form.Item name="address" label="Địa chỉ">
                                        <Input className="misa-input" placeholder="Địa chỉ thường trú..." />
                                    </Form.Item>
                                </Col>
                                <Col span={10}>
                                    <Form.Item label="Nhân viên phụ trách">
                                        <div className="misa-input-group">
                                            <Form.Item name="assigned_employee_id" noStyle>
                                                <Select 
                                                    variant="borderless"
                                                    allowClear
                                                    placeholder="Chọn nhân viên"
                                                    className="misa-w-full"
                                                    options={employees.map((e: any) => ({
                                                        value: e.id || e.code,
                                                        label: `${e.code} - ${e.name}`
                                                    }))}
                                                />
                                            </Form.Item>
                                            <button 
                                                type="button" 
                                                className="misa-plus-btn" 
                                                title="Thêm nhân viên mới"
                                                onClick={() => setIsEmployeeModalOpen(true)}
                                            >
                                                <PlusOutlined className="misa-font-11" />
                                            </button>
                                        </div>
                                    </Form.Item>
                                </Col>
                            </Row>

                            {/* Row 4: ĐT di động | Email | Checkbox Là đối tượng nội bộ */}
                            <Row gutter={[16, 8]} align="middle">
                                <Col span={6}>
                                    <Form.Item name="mobile_phone" label="Điện thoại di động">
                                        <Input className="misa-input" placeholder="SĐT di động..." />
                                    </Form.Item>
                                </Col>
                                <Col span={8}>
                                    <Form.Item name="email" label="Email">
                                        <Input className="misa-input" placeholder="Email liên hệ..." />
                                    </Form.Item>
                                </Col>
                                <Col span={10} className="misa-pt-12">
                                    <div className="misa-flex-align-gap6">
                                        <Checkbox checked={isInternalChecked} onChange={e => setIsInternalChecked(e.target.checked)}>
                                            Là Đối tượng nội bộ
                                        </Checkbox>
                                        <QuestionCircleOutlined className="misa-icon-help-muted cursor-pointer" />
                                    </div>
                                </Col>
                            </Row>
                        </div>
                    )}

                    {/* Exact 6 Sub Tabs Section */}
                    <div className="misa-modal-tabs-wrapper-p4">
                        <Tabs 
                            activeKey={activeTab} 
                            onChange={setActiveTab}
                            size="small"
                            className="misa-mb-8"
                            items={[
                                {
                                    key: '1',
                                    label: 'Thông tin liên hệ',
                                    children: personType === 'org' ? (
                                        <div className="misa-grid-12-1-gap24-pt4">
                                            {/* LEFT: Người liên hệ & Đại diện theo PL */}
                                            <div className="misa-flex-col-gap4">
                                                <div className="misa-section-title-12">Người liên hệ</div>
                                                <div className="misa-flex-gap-6">
                                                    <Form.Item name="contact_person_salutation" noStyle initialValue="Ông">
                                                        <Select className="misa-w-85" options={[{ value: 'Ông', label: 'Ông' }, { value: 'Bà', label: 'Bà' }, { value: 'Anh', label: 'Anh' }, { value: 'Chị', label: 'Chị' }]} />
                                                    </Form.Item>
                                                    <Form.Item name="contact_person_name" noStyle>
                                                        <Input className="misa-input misa-flex-1" placeholder="Họ và tên" />
                                                    </Form.Item>
                                                </div>
                                                <div>
                                                    <Form.Item name="contact_person_email" noStyle>
                                                        <Input className="misa-input" placeholder="Email" />
                                                    </Form.Item>
                                                </div>
                                                <div>
                                                    <Form.Item name="contact_person_phone" noStyle>
                                                        <Input className="misa-input misa-w-220" placeholder="Số điện thoại" />
                                                    </Form.Item>
                                                </div>

                                                <div className="misa-section-title-12 misa-mt-4">Đại diện theo PL</div>
                                                <div>
                                                    <Form.Item name="legal_representative" noStyle>
                                                        <Input className="misa-input" placeholder="Đại diện theo PL" />
                                                    </Form.Item>
                                                </div>
                                            </div>

                                            {/* RIGHT: Người nhận hóa đơn điện tử */}
                                            <div className="misa-flex-col-gap4">
                                                <div className="misa-section-title-12">Người nhận hóa đơn điện tử</div>
                                                <div>
                                                    <Form.Item name="einvoice_contact_name" noStyle>
                                                        <Input className="misa-input" placeholder="Họ và tên" />
                                                    </Form.Item>
                                                </div>
                                                <div>
                                                    <Form.Item name="einvoice_contact_email" noStyle>
                                                        <Input className="misa-input" placeholder='Email (Ngăn cách nhiều email bởi dấu ";")' />
                                                    </Form.Item>
                                                </div>
                                                <div>
                                                    <Form.Item name="einvoice_contact_phone" noStyle>
                                                        <Input className="misa-input misa-w-220" placeholder="Số điện thoại" />
                                                    </Form.Item>
                                                </div>
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="misa-grid-12-1-gap24-pt4">
                                            {/* LEFT (Individual): Email, ĐT di động, ĐT cố định, Đại diện theo PL */}
                                            <div className="misa-flex-col-gap4">
                                                <div className="misa-section-title-12">Thông tin liên hệ</div>
                                                <div>
                                                    <Form.Item name="email" noStyle>
                                                        <Input className="misa-input" placeholder="Email" />
                                                    </Form.Item>
                                                </div>
                                                <div>
                                                    <Form.Item name="mobile_phone" noStyle>
                                                        <Input className="misa-input misa-w-220" placeholder="Điện thoại di động" />
                                                    </Form.Item>
                                                </div>
                                                <div>
                                                    <Form.Item name="landline_phone" noStyle>
                                                        <Input className="misa-input misa-w-220" placeholder="Điện thoại cố định" />
                                                    </Form.Item>
                                                </div>

                                                <div className="misa-section-title-12 misa-mt-4">Đại diện theo PL</div>
                                                <div>
                                                    <Form.Item name="legal_representative" noStyle>
                                                        <Input className="misa-input" placeholder="Đại diện theo PL" />
                                                    </Form.Item>
                                                </div>
                                            </div>
                                        </div>
                                    )
                                },
                                {
                                    key: '2',
                                    label: 'Điều khoản thanh toán',
                                    children: (
                                        <div className="misa-pt-8">
                                            <Row gutter={[16, 12]}>
                                                {/* Row 1: Điều khoản thanh toán & Số ngày được nợ */}
                                                <Col span={12}>
                                                    <Form.Item label="Điều khoản thanh toán">
                                                        <div className="misa-input-group">
                                                            <Form.Item name="payment_term" noStyle>
                                                                <Select 
                                                                    variant="borderless"
                                                                    className="misa-w-full"
                                                                    options={paymentTerms}
                                                                    onChange={(val) => {
                                                                        const found = paymentTerms.find(t => t.value === val);
                                                                        if (found) form.setFieldValue('due_days', found.days);
                                                                    }}
                                                                />
                                                            </Form.Item>
                                                            <button 
                                                                type="button" 
                                                                className="misa-plus-btn"
                                                                title="Thêm điều khoản"
                                                                onClick={() => setIsPaymentTermModalOpen(true)}
                                                            >
                                                                <PlusOutlined className="misa-font-11" />
                                                            </button>
                                                        </div>
                                                    </Form.Item>
                                                </Col>

                                                <Col span={12}>
                                                    <Form.Item name="due_days" label="Số ngày được nợ">
                                                        <InputNumber className="misa-input misa-input-number-right misa-w-full" min={0} max={365} />
                                                    </Form.Item>
                                                </Col>

                                                {/* Row 2: Số nợ tối đa & Tài khoản công nợ */}
                                                <Col span={12}>
                                                    <Form.Item name="debt_limit" label="Số nợ tối đa">
                                                        <InputNumber 
                                                            className="misa-input misa-input-number-right misa-w-full" 
                                                            formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} 
                                                        />
                                                    </Form.Item>
                                                </Col>

                                                <Col span={12}>
                                                    <Form.Item 
                                                        name="debt_account" 
                                                        label={isCustomer ? "Tài khoản công nợ phải thu" : "Tài khoản công nợ phải trả"} 
                                                    >
                                                        <Select
                                                            allowClear
                                                            placeholder="Chọn tài khoản công nợ"
                                                            className="misa-w-full"
                                                            options={debtAccountOptions}
                                                            notFoundContent="Chưa có tài khoản từ máy chủ"
                                                        />
                                                    </Form.Item>
                                                </Col>
                                            </Row>
                                        </div>
                                    )
                                },
                                {
                                    key: '3',
                                    label: 'Tài khoản ngân hàng',
                                    children: (
                                        <div className="misa-pt-4">
                                            <table className="misa-grid-table">
                                                <thead>
                                                    <tr>
                                                        <th className="misa-w-180">Số tài khoản</th>
                                                        <th className="misa-min-w-200">Tên ngân hàng</th>
                                                        <th className="misa-w-180">Chi nhánh</th>
                                                        <th className="misa-w-160">Tỉnh/TP của ngân hàng</th>
                                                        <th className="misa-th-action"></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {bankAccounts.map((b, idx) => (
                                                        <tr key={b.id}>
                                                            <td className="misa-td-p3">
                                                                <input 
                                                                    className="misa-table-input" 
                                                                    placeholder="" 
                                                                    value={b.account_number}
                                                                    onChange={(e) => {
                                                                        const updated = [...bankAccounts];
                                                                        updated[idx].account_number = e.target.value;
                                                                        setBankAccounts(updated);
                                                                    }}
                                                                />
                                                            </td>
                                                            <td className="misa-td-p3">
                                                                <input 
                                                                    className="misa-table-input" 
                                                                    placeholder="" 
                                                                    value={b.bank_name}
                                                                    onChange={(e) => {
                                                                        const updated = [...bankAccounts];
                                                                        updated[idx].bank_name = e.target.value;
                                                                        setBankAccounts(updated);
                                                                    }}
                                                                />
                                                            </td>
                                                            <td className="misa-td-p3">
                                                                <input 
                                                                    className="misa-table-input" 
                                                                    placeholder="" 
                                                                    value={b.branch}
                                                                    onChange={(e) => {
                                                                        const updated = [...bankAccounts];
                                                                        updated[idx].branch = e.target.value;
                                                                        setBankAccounts(updated);
                                                                    }}
                                                                />
                                                            </td>
                                                            <td className="misa-td-p3">
                                                                <input 
                                                                    className="misa-table-input" 
                                                                    placeholder="" 
                                                                    value={b.province}
                                                                    onChange={(e) => {
                                                                        const updated = [...bankAccounts];
                                                                        updated[idx].province = e.target.value;
                                                                        setBankAccounts(updated);
                                                                    }}
                                                                />
                                                            </td>
                                                            <td className="misa-td-action">
                                                                <button 
                                                                    type="button" 
                                                                    onClick={() => {
                                                                        if (bankAccounts.length <= 1) setBankAccounts([{ id: '1', account_number: '', bank_name: '', branch: '', province: '' }]);
                                                                        else setBankAccounts(bankAccounts.filter((_, i) => i !== idx));
                                                                    }}
                                                                    className="misa-btn-icon-danger"
                                                                >
                                                                    <DeleteOutlined className="misa-font-13" />
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                            <div className="misa-flex-gap-8-mt6">
                                                <Button 
                                                    size="small" 
                                                    onClick={() => setBankAccounts([...bankAccounts, { id: String(Date.now()), account_number: '', bank_name: '', branch: '', province: '' }])}
                                                    className="misa-btn-tool-secondary"
                                                >
                                                    Thêm dòng
                                                </Button>
                                                <Popconfirm
                                                    title="Bạn có chắc chắn muốn xóa hết các dòng tài khoản ngân hàng?"
                                                    onConfirm={() => setBankAccounts([{ id: '1', account_number: '', bank_name: '', branch: '', province: '' }])}
                                                >
                                                    <Button size="small" className="misa-btn-tool-secondary">
                                                        Xóa hết dòng
                                                    </Button>
                                                </Popconfirm>
                                            </div>
                                        </div>
                                    )
                                },
                                {
                                    key: '4',
                                    label: 'Địa chỉ khác',
                                    children: (
                                        <div className="misa-pt-4">
                                            {/* Vị trí địa lý */}
                                            <div className="apple-section-gap">
                                                <div className="misa-section-title-13 misa-mb-8">Vị trí địa lý</div>
                                                <Row gutter={[12, 8]}>
                                                    <Col span={6}>
                                                        <Form.Item name="country" label="Quốc gia" initialValue="Việt Nam">
                                                            <Select className="misa-w-full" options={[{ value: 'Việt Nam', label: 'Việt Nam' }, { value: 'Khác', label: 'Quốc gia khác' }]} />
                                                        </Form.Item>
                                                    </Col>
                                                    <Col span={6}>
                                                        <Form.Item name="province" label="Tỉnh/Thành phố">
                                                            <Input className="misa-input" placeholder="Tỉnh/Thành phố..." />
                                                        </Form.Item>
                                                    </Col>
                                                    <Col span={6}>
                                                        <Form.Item name="district" label="Quận/Huyện">
                                                            <Input className="misa-input" placeholder="Quận/Huyện..." />
                                                        </Form.Item>
                                                    </Col>
                                                    <Col span={6}>
                                                        <Form.Item name="ward" label="Xã/Phường">
                                                            <Input className="misa-input" placeholder="Xã/Phường..." />
                                                        </Form.Item>
                                                    </Col>
                                                </Row>
                                            </div>

                                            {/* Địa chỉ giao hàng */}
                                            <div>
                                                <div className="misa-flex-between-center misa-mb-8">
                                                    <div className="misa-section-title-13">Địa chỉ giao hàng</div>
                                                    <Form.Item name="same_as_main_address" valuePropName="checked" noStyle>
                                                        <Checkbox onChange={e => {
                                                            if (e.target.checked) {
                                                                const mainAddr = form.getFieldValue('address');
                                                                const mainName = form.getFieldValue('name');
                                                                const mainPhone = form.getFieldValue('phone') || form.getFieldValue('mobile_phone');
                                                                setDeliveryAddresses([{ id: '1', address: mainAddr || '', receiver_name: mainName || '', receiver_phone: mainPhone || '' }]);
                                                            }
                                                        }}>
                                                            Giống địa chỉ khách hàng
                                                        </Checkbox>
                                                    </Form.Item>
                                                </div>

                                                <table className="misa-grid-table">
                                                    <thead>
                                                        <tr>
                                                            <th className="misa-min-w-200">Địa chỉ giao hàng</th>
                                                            <th className="misa-w-180">Người nhận</th>
                                                            <th className="misa-w-160">Điện thoại người nhận</th>
                                                            <th className="misa-th-action"></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {deliveryAddresses.map((d, idx) => (
                                                            <tr key={d.id}>
                                                                <td className="misa-td-p3">
                                                                    <input 
                                                                        className="misa-table-input" 
                                                                        placeholder="Địa chỉ giao hàng chi tiết..." 
                                                                        value={d.address}
                                                                        onChange={(e) => {
                                                                            const updated = [...deliveryAddresses];
                                                                            updated[idx].address = e.target.value;
                                                                            setDeliveryAddresses(updated);
                                                                        }}
                                                                    />
                                                                </td>
                                                                <td className="misa-td-p3">
                                                                    <input 
                                                                        className="misa-table-input" 
                                                                        placeholder="Họ tên người nhận..." 
                                                                        value={d.receiver_name}
                                                                        onChange={(e) => {
                                                                            const updated = [...deliveryAddresses];
                                                                            updated[idx].receiver_name = e.target.value;
                                                                            setDeliveryAddresses(updated);
                                                                        }}
                                                                    />
                                                                </td>
                                                                <td className="misa-td-p3">
                                                                    <input 
                                                                        className="misa-table-input" 
                                                                        placeholder="Số điện thoại..." 
                                                                        value={d.receiver_phone}
                                                                        onChange={(e) => {
                                                                            const updated = [...deliveryAddresses];
                                                                            updated[idx].receiver_phone = e.target.value;
                                                                            setDeliveryAddresses(updated);
                                                                        }}
                                                                    />
                                                                </td>
                                                                <td className="misa-td-action">
                                                                    <button 
                                                                        type="button" 
                                                                        onClick={() => {
                                                                            if (deliveryAddresses.length <= 1) setDeliveryAddresses([{ id: '1', address: '', receiver_name: '', receiver_phone: '' }]);
                                                                            else setDeliveryAddresses(deliveryAddresses.filter((_, i) => i !== idx));
                                                                        }}
                                                                        className="misa-btn-icon-danger"
                                                                    >
                                                                        <DeleteOutlined className="misa-font-13" />
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>

                                                <div className="misa-flex-gap-8-mt6">
                                                    <Button 
                                                        size="small" 
                                                        onClick={() => setDeliveryAddresses([...deliveryAddresses, { id: String(Date.now()), address: '', receiver_name: '', receiver_phone: '' }])}
                                                        className="misa-btn-tool-secondary"
                                                    >
                                                        Thêm dòng
                                                    </Button>
                                                    <Button 
                                                        size="small" 
                                                        onClick={() => setDeliveryAddresses([{ id: '1', address: '', receiver_name: '', receiver_phone: '' }])}
                                                        className="misa-btn-tool-secondary"
                                                    >
                                                        Xóa hết dòng
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>
                                    )
                                },
                                {
                                    key: '5',
                                    label: 'Ghi chú',
                                    children: (
                                        <div className="misa-pt-4">
                                            <Form.Item name="note" noStyle>
                                                <Input.TextArea rows={6} className="misa-input" placeholder="Nhập ghi chú chi tiết về khách hàng / nhà cung cấp..." />
                                            </Form.Item>
                                        </div>
                                    )
                                },
                                {
                                    key: '6',
                                    label: 'Thông tin bổ sung',
                                    children: (
                                        <div className="misa-pt-4">
                                            {/* 5 Custom Expandable Fields */}
                                            <div className="misa-flex-col-gap8">
                                                {customFieldLabels.map((label, idx) => (
                                                    <Row gutter={12} align="middle" key={idx}>
                                                        <Col span={6}>
                                                            <div 
                                                                className="misa-custom-field-label-btn"
                                                                title="Click để đổi tên trường mở rộng"
                                                                onClick={() => {
                                                                    setEditingFieldIdx(idx);
                                                                    labelForm.setFieldValue('label_name', label);
                                                                }}
                                                                style={{
                                                                    height: 34,
                                                                    border: '1px solid #d9d9d9',
                                                                    borderRadius: 6,
                                                                    padding: '0 10px',
                                                                    display: 'flex',
                                                                    alignItems: 'center',
                                                                    justifyContent: 'space-between',
                                                                    backgroundColor: '#ffffff',
                                                                    color: '#1f2937',
                                                                    fontSize: 13,
                                                                    cursor: 'pointer',
                                                                    transition: 'all 0.2s ease'
                                                                }}
                                                            >
                                                                <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                                    {label}
                                                                </span>
                                                                <EditOutlined style={{ color: '#1677ff', fontSize: 13, flexShrink: 0 }} />
                                                            </div>
                                                        </Col>
                                                        <Col span={18}>
                                                            <Form.Item name={`custom_field_${idx + 1}`} noStyle>
                                                                <Input className="misa-input misa-w-full" placeholder="" />
                                                            </Form.Item>
                                                        </Col>
                                                    </Row>
                                                ))}
                                            </div>
                                        </div>
                                    )
                                }
                            ]}
                        />
                    </div>
                </Form>
                </ModalFrame>
            </Modal>

            {/* Quick Add Employee Modal */}
            <QuickAddEmployeeModal
                open={isEmployeeModalOpen}
                onCancel={() => setIsEmployeeModalOpen(false)}
                onSuccess={(newEmp) => {
                    form.setFieldValue('assigned_employee_id', newEmp.id || newEmp.code);
                    queryClient.invalidateQueries({ queryKey: ['employees'] });
                }}
            />

            {/* Quick Add Contact Group Modal */}
            <Modal
                title="Thêm nhóm khách hàng, nhà cung cấp"
                open={isGroupModalOpen}
                onCancel={() => setIsGroupModalOpen(false)}
                zIndex={2500}
                centered={true}
                className="misa-clean-modal"
                onOk={() => {
                    groupForm.validateFields().then(values => {
                        const newOption = { value: values.name, label: values.name };
                        setContactGroups(prev => [newOption, ...prev]);
                        form.setFieldValue('contact_group', values.name);
                        message.info('Đã thêm nhóm vào biểu mẫu; chưa lưu danh mục trên máy chủ.');
                        setIsGroupModalOpen(false);
                        groupForm.resetFields();
                    });
                }}
                width={480}
            >
                <Form form={groupForm} layout="vertical" size="small" className="misa-pt-8">
                    <Form.Item name="code" label="Mã nhóm">
                        <Input className="misa-input" />
                    </Form.Item>
                    <Form.Item name="name" label="Tên nhóm" rules={[{ required: true, message: 'Vui lòng nhập tên nhóm!' }]}>
                        <Input className="misa-input" placeholder="VD: Khách hàng dự án, Khách bán sỉ..." />
                    </Form.Item>
                </Form>
            </Modal>

            {/* Edit Custom Field Label Modal */}
            <Modal
                title="Đổi tên trường mở rộng"
                open={editingFieldIdx !== null}
                onCancel={() => setEditingFieldIdx(null)}
                zIndex={2600}
                centered={true}
                className="misa-clean-modal"
                onOk={() => {
                    labelForm.validateFields().then(values => {
                        if (editingFieldIdx !== null) {
                            const updated = [...customFieldLabels];
                            updated[editingFieldIdx] = values.label_name || `Trường mở rộng ${editingFieldIdx + 1}`;
                            setCustomFieldLabels(updated);
                        message.info('Đã cập nhật tên trường trong biểu mẫu; chưa lưu danh mục trên máy chủ.');
                            setEditingFieldIdx(null);
                            labelForm.resetFields();
                        }
                    });
                }}
                width={420}
            >
                <Form form={labelForm} layout="vertical" size="small" className="misa-pt-8">
                    <Form.Item 
                        name="label_name" 
                        label="Tên hiển thị của trường" 
                        rules={[{ required: true, message: 'Vui lòng nhập tên trường!' }]}
                    >
                        <Input className="misa-input" placeholder="VD: Kênh bán hàng, Mã tham chiếu..." />
                    </Form.Item>
                </Form>
            </Modal>

            {/* Quick Add Payment Term Modal (Reusable Standard MISA) */}
            <QuickAddPaymentTermModal
                open={isPaymentTermModalOpen}
                onCancel={() => setIsPaymentTermModalOpen(false)}
                onSuccess={(newTerm: any) => {
                    const newOption = { value: newTerm.name, label: newTerm.name, days: newTerm.due_days || 0 };
                    setPaymentTerms(prev => [newOption, ...prev]);
                    form.setFieldValue('payment_term', newTerm.name);
                    form.setFieldValue('due_days', newTerm.due_days || 0);
                    if (newTerm.discount_days) form.setFieldValue('discount_days', newTerm.discount_days);
                    if (newTerm.discount_rate) form.setFieldValue('discount_rate', newTerm.discount_rate);
                }}
            />
        </>
    );
};

export default QuickAddContactModal;
