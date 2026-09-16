import React, { useState, useEffect } from 'react';
import { Form, Input, Select, Radio, Checkbox, Button, DatePicker, InputNumber, Tabs, Popconfirm } from 'antd';
import { toast as message } from '../feedback/toast';
import Modal from '../layout/AppModal';
import {
    PlusOutlined,
    DeleteOutlined,
    QuestionCircleOutlined
} from '@ant-design/icons';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import ModalFrame from '../layout/ModalFrame';
import { MisaButton } from './MisaButton';


interface QuickAddEmployeeModalProps {
    open: boolean;
    onCancel: () => void;
    onSuccess?: (newEmployee: any) => void;
}

interface BankAccountRow {
    id: string;
    account_number: string;
    bank_name: string;
    branch: string;
    province: string;
}

interface DependentRow {
    id: string;
    name: string;
    relationship: string;
    birth_date: string;
    tax_code: string;
    id_type: string;
    id_number: string;
    start_date: string;
    end_date: string;
}

export const QuickAddEmployeeModal: React.FC<QuickAddEmployeeModalProps> = ({
    open,
    onCancel,
    onSuccess
}) => {
    const [activeTab, setActiveTab] = useState('1');
    const [gender, setGender] = useState<'Nam' | 'Nữ'>('Nam');
    const [isCustomerChecked, setIsCustomerChecked] = useState(false);
    const [isSupplierChecked, setIsSupplierChecked] = useState(false);

    // Sub-modals for + buttons
    const [isDeptModalOpen, setIsDeptModalOpen] = useState(false);
    const [isPositionModalOpen, setIsPositionModalOpen] = useState(false);
    const [deptForm] = Form.useForm();
    const [posForm] = Form.useForm();

    // Tables inside tabs
    const [bankAccounts, setBankAccounts] = useState<BankAccountRow[]>([
        { id: '1', account_number: '', bank_name: '', branch: '', province: '' }
    ]);
    const [dependents, setDependents] = useState<DependentRow[]>([]);

    // Dropdowns
    const [departments, setDepartments] = useState([
        { value: 'Ban Giám đốc', label: 'Ban Giám đốc' },
        { value: 'Phòng Kế toán', label: 'Phòng Kế toán' },
        { value: 'Phòng Kinh doanh', label: 'Phòng Kinh doanh' },
        { value: 'Phòng Marketing', label: 'Phòng Marketing' },
        { value: 'Phòng Kỹ thuật & Công nghệ', label: 'Phòng Kỹ thuật & Công nghệ' },
        { value: 'Phòng Nhân sự & Hành chính', label: 'Phòng Nhân sự & Hành chính' },
        { value: 'Phòng Mua hàng & Kho vận', label: 'Phòng Mua hàng & Kho vận' },
    ]);

    const [positions, setPositions] = useState([
        { value: 'Giám đốc', label: 'Giám đốc' },
        { value: 'Phó Giám đốc', label: 'Phó Giám đốc' },
        { value: 'Kế toán trưởng', label: 'Kế toán trưởng' },
        { value: 'Kế toán tổng hợp', label: 'Kế toán tổng hợp' },
        { value: 'Kế toán thanh toán', label: 'Kế toán thanh toán' },
        { value: 'Kế toán kho', label: 'Kế toán kho' },
        { value: 'Trưởng phòng Kinh doanh', label: 'Trưởng phòng Kinh doanh' },
        { value: 'Nhân viên kinh doanh', label: 'Nhân viên kinh doanh' },
        { value: 'Kỹ sư phần mềm', label: 'Kỹ sư phần mềm' },
        { value: 'Nhân viên hành chính', label: 'Nhân viên hành chính' },
    ]);

    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    // Keyboard shortcuts
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if (!open) return;
            if (e.key === 'Escape' && !isDeptModalOpen && !isPositionModalOpen) {
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
    }, [open, isDeptModalOpen, isPositionModalOpen]);

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            const payload: any = {
                // Employee numbering is tenant/server policy. Do not invent a
                // code in the browser when the user has not supplied one.
                code: values.code?.trim() || undefined,
                name: values.name,
                is_customer: isCustomerChecked,
                is_supplier: isSupplierChecked,
                department: values.department || 'Phòng Kế toán',
                position: values.position || 'Nhân viên',
                gender: gender,
                birth_date: values.birth_date ? values.birth_date.format('YYYY-MM-DD') : null,
                id_card_number: values.id_card_number || '',
                id_card_date: values.id_card_date ? values.id_card_date.format('YYYY-MM-DD') : null,
                id_card_place: values.id_card_place || '',
                passport_number: values.passport_number || '',
                address: values.address || '',
                email: values.email || '',
                phone: values.phone || '',
                landline_phone: values.landline_phone || '',
                account_email: values.account_email || '',
                account_phone: values.account_phone || '',
                contract_salary: values.contract_salary || 0,
                base_salary: values.contract_salary || 0,
                salary_coefficient: values.salary_coefficient || 0,
                insurance_salary: values.insurance_salary || 0,
                tax_code: values.tax_code || '',
                contract_type: values.contract_type || 'Cư trú và có HĐLĐ từ 3 tháng trở lên',
                dependents_count: values.dependents_count || dependents.length,
                personal_deduction: 11000000,
                bank_accounts: bankAccounts.filter(b => b.account_number || b.bank_name),
                dependents: dependents.filter(d => d.name),
                status: 'active'
            };

            return api.post('/master/employees', payload);
        },
        onSuccess: (res: any, variables: any) => {
            const persistedEmployee = res?.data?.data ?? res?.data;
            if (!persistedEmployee || persistedEmployee.id === undefined || persistedEmployee.id === null) {
                message.error('Máy chủ không trả về nhân viên đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }

            message.success('Đã lưu thông tin nhân viên vào hệ thống thành công!');
            queryClient.invalidateQueries({ queryKey: ['employees'] });
            if (onSuccess) {
                onSuccess(persistedEmployee);
            }
            if (variables._andNew) {
                initNewForm();
            } else {
                form.resetFields();
                onCancel();
            }
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || 'Có lỗi xảy ra khi lưu nhân viên!');
        }
    });

    const initNewForm = () => {
        form.resetFields();
        form.setFieldsValue({
            code: '',
            name: '',
            department: 'Phòng Kế toán',
            position: 'Kế toán viên',
            contract_salary: 0,
            salary_coefficient: 0.0,
            insurance_salary: 0,
            contract_type: 'Cư trú và có HĐLĐ từ 3 tháng trở lên',
            dependents_count: 0,
            tax_code: '',
            address: '',
            email: '',
            phone: '',
            landline_phone: '',
            account_email: '',
            account_phone: ''
        });
        setGender('Nam');
        setBankAccounts([{ id: '1', account_number: '', bank_name: '', branch: '', province: '' }]);
        setDependents([]);
    };

    const handleSave = (andNew = false) => {
        form.validateFields()
            .then(values => {
                mutation.mutate({ ...values, _andNew: andNew });
            })
            .catch(() => {
                message.error('Vui lòng kiểm tra lại các trường bắt buộc màu đỏ!');
            });
    };

    useEffect(() => {
        if (open) {
            initNewForm();
            setIsCustomerChecked(false);
            setIsSupplierChecked(false);
        }
    }, [open]);

    return (
        <>
            <Modal
                title={
                    <div className="misa-flex-align-gap16">
                        <span className="misa-modal-title">
                            Thông tin nhân viên
                        </span>
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
                className="misa-clean-modal"
                centered={true}
                zIndex={2500}
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
                <ModalFrame className="ui-modal-frame--simple">
                <Form form={form} layout="vertical" size="small" className="misa-pt-2">

                    {/* Master Form Area: 2-Column Split */}
                    <div className="misa-modal-employee-master-grid">
                        {/* LEFT COLUMN: Mã, Tên, Đơn vị with +, Chức danh with + */}
                        <div className="misa-flex-col-gap2">
                            {/* Row 1: Mã & Tên */}
                            <div className="misa-grid-1-16-gap8">
                                <Form.Item
                                    name="code"
                                    label={<span>Mã <span className="misa-text-danger">*</span></span>}
                                    rules={[{ required: true, message: 'Vui lòng nhập mã NV!' }]}
                                >
                                    <Input className="misa-input misa-font-semibold" />
                                </Form.Item>
                                <Form.Item
                                    name="name"
                                    label={<span>Tên <span className="misa-text-danger">*</span></span>}
                                    rules={[{ required: true, message: 'Vui lòng nhập họ và tên!' }]}
                                >
                                    <Input className="misa-input" placeholder="" />
                                </Form.Item>
                            </div>

                            {/* Row 2: Đơn vị (Combobox + plus button) */}
                            <div>
                                <div className="misa-field-label"><span>Đơn vị <span className="misa-text-danger">*</span></span></div>
                                <div className="misa-input-group">
                                    <Form.Item name="department" noStyle rules={[{ required: true, message: 'Vui lòng chọn đơn vị!' }]}>
                                        <Select
                                            variant="borderless"
                                            className="misa-w-full"
                                            options={departments}
                                        />
                                    </Form.Item>
                                    <button
                                        type="button"
                                        className="misa-plus-btn"
                                        title="Thêm đơn vị mới"
                                        onClick={() => setIsDeptModalOpen(true)}
                                    >
                                        <PlusOutlined className="misa-font-11" />
                                    </button>
                                </div>
                            </div>

                            {/* Row 3: Chức danh (Combobox + plus button) */}
                            <div className="misa-mt-2">
                                <div className="misa-field-label"><span>Chức danh <span className="misa-text-danger">*</span></span></div>
                                <div className="misa-input-group">
                                    <Form.Item name="position" noStyle rules={[{ required: true, message: 'Vui lòng chọn chức danh!' }]}>
                                        <Select
                                            variant="borderless"
                                            className="misa-w-full"
                                            options={positions}
                                        />
                                    </Form.Item>
                                    <button
                                        type="button"
                                        className="misa-plus-btn"
                                        title="Thêm chức danh mới"
                                        onClick={() => setIsPositionModalOpen(true)}
                                    >
                                        <PlusOutlined className="misa-font-11" />
                                    </button>
                                </div>
                            </div>
                        </div>

                        {/* RIGHT COLUMN: Ngày sinh & Giới tính, CMND & Ngày cấp, Nơi cấp & Hộ chiếu */}
                        <div className="misa-flex-col-gap2">
                            {/* Row 1: Ngày sinh & Giới tính */}
                            <div className="misa-grid-12-1-gap8">
                                <Form.Item name="birth_date" label="Ngày sinh">
                                    <DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" placeholder="DD/MM/YYYY" />
                                </Form.Item>
                                <div>
                                    <div className="misa-field-label">Giới tính</div>
                                    <Radio.Group
                                        value={gender}
                                        onChange={e => setGender(e.target.value)}
                                        className="misa-mt-4"
                                    >
                                        <Radio value="Nam">Nam</Radio>
                                        <Radio value="Nữ">Nữ</Radio>
                                    </Radio.Group>
                                </div>
                            </div>

                            {/* Row 2: Số CMND & Ngày cấp */}
                            <div className="misa-grid-12-1-gap8">
                                <Form.Item name="id_card_number" label="Số CMND">
                                    <Input className="misa-input" placeholder="" />
                                </Form.Item>
                                <Form.Item name="id_card_date" label="Ngày cấp">
                                    <DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" placeholder="DD/MM/YYYY" />
                                </Form.Item>
                            </div>

                            {/* Row 3: Nơi cấp & Số hộ chiếu */}
                            <div className="misa-grid-12-1-gap8">
                                <Form.Item name="id_card_place" label="Nơi cấp">
                                    <Input className="misa-input" placeholder="" />
                                </Form.Item>
                                <Form.Item name="passport_number" label="Số hộ chiếu">
                                    <Input className="misa-input" placeholder="" />
                                </Form.Item>
                            </div>
                        </div>
                    </div>

                    {/* Exact 4 Sub-Tabs */}
                    <div className="misa-modal-tabs-wrapper">
                        <Tabs
                            activeKey={activeTab}
                            onChange={setActiveTab}
                            size="small"
                            className="misa-mb-8"
                            items={[
                                {
                                    key: '1',
                                    label: 'Thông tin liên hệ',
                                    children: (
                                        <div className="misa-tab-content-flex-gap4">
                                            {/* Địa chỉ */}
                                            <div>
                                                <Form.Item name="address" label="Địa chỉ">
                                                    <Input className="misa-input" placeholder="" />
                                                </Form.Item>
                                            </div>

                                            {/* Email, ĐT di động, ĐT cố định */}
                                            <div className="misa-grid-3col-gap12">
                                                <Form.Item name="email" label="Email">
                                                    <Input className="misa-input" placeholder="" />
                                                </Form.Item>
                                                <Form.Item name="phone" label="Điện thoại di động">
                                                    <Input className="misa-input" placeholder="" />
                                                </Form.Item>
                                                <Form.Item name="landline_phone" label="ĐT cố định">
                                                    <Input className="misa-input" placeholder="" />
                                                </Form.Item>
                                            </div>

                                            {/* Khối THÔNG TIN TÀI KHOẢN */}
                                            <div className="misa-modal-account-box">
                                                <div className="misa-section-title-12">
                                                    THÔNG TIN TÀI KHOẢN
                                                </div>
                                                <div className="misa-grid-2col-gap16">
                                                    <div>
                                                        <div className="misa-field-label misa-flex-align-gap4">
                                                            <span>Email tài khoản</span>
                                                            <QuestionCircleOutlined className="apple-muted-text" />
                                                        </div>
                                                        <Form.Item name="account_email" noStyle>
                                                            <Input className="misa-input" placeholder="" />
                                                        </Form.Item>
                                                    </div>
                                                    <div>
                                                        <div className="misa-field-label misa-flex-align-gap4">
                                                            <span>SĐT tài khoản</span>
                                                            <QuestionCircleOutlined className="apple-muted-text" />
                                                        </div>
                                                        <Form.Item name="account_phone" noStyle>
                                                            <Input className="misa-input" placeholder="" />
                                                        </Form.Item>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    )
                                },
                                {
                                    key: '2',
                                    label: 'Thông tin tiền lương',
                                    children: (
                                        <div className="misa-tab-content-flex-gap6">
                                            {/* Row 1: Lương thỏa thuận, Hệ số lương, Lương đóng bảo hiểm */}
                                            <div className="misa-grid-salary-row1">
                                                <Form.Item name="contract_salary" label="Lương thỏa thuận" initialValue={0}>
                                                    <InputNumber
                                                        className="misa-input misa-input-number-right misa-w-full"
                                                        formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                    />
                                                </Form.Item>
                                                <Form.Item name="salary_coefficient" label="Hệ số lương" initialValue={0}>
                                                    <InputNumber
                                                        className="misa-input misa-input-number-right misa-w-full"
                                                        step={0.1}
                                                        precision={2}
                                                    />
                                                </Form.Item>
                                                <Form.Item name="insurance_salary" label="Lương đóng bảo hiểm" initialValue={0}>
                                                    <InputNumber
                                                        className="misa-input misa-input-number-right misa-w-full"
                                                        formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                    />
                                                </Form.Item>
                                            </div>

                                            {/* Row 2: Mã số thuế, Loại hợp đồng *, Số người phụ thuộc */}
                                            <div className="misa-grid-salary-row2">
                                                <Form.Item name="tax_code" label="Mã số thuế">
                                                    <Input className="misa-input" placeholder="" />
                                                </Form.Item>
                                                <Form.Item
                                                    name="contract_type"
                                                    label={<span>Loại hợp đồng <span className="misa-text-danger">*</span></span>}
                                                    initialValue="Cư trú và có HĐLĐ từ 3 tháng trở lên"
                                                >
                                                    <Select
                                                        className="misa-w-full"
                                                        options={[
                                                            { value: 'Cư trú và có HĐLĐ từ 3 tháng trở lên', label: 'Cư trú và có HĐLĐ từ 3 tháng trở lên' },
                                                            { value: 'Vãng lai / Dưới 3 tháng', label: 'Vãng lai / Dưới 3 tháng' },
                                                            { value: 'Thử việc', label: 'Thử việc' },
                                                            { value: 'Hợp đồng khoán việc / Thời vụ', label: 'Hợp đồng khoán việc / Thời vụ' }
                                                        ]}
                                                    />
                                                </Form.Item>
                                                <Form.Item name="dependents_count" label="Số người phụ thuộc" initialValue={0}>
                                                    <InputNumber className="misa-input misa-input-number-right misa-w-full" min={0} max={20} />
                                                </Form.Item>
                                            </div>
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
                                                    title="Bạn có chắc chắn muốn xóa hết tài khoản ngân hàng?"
                                                    onConfirm={() => setBankAccounts([{ id: '1', account_number: '', bank_name: '', branch: '', province: '' }])}
                                                >
                                                    <Button size="small" className="misa-btn-tool-secondary">
                                                        Xóa tất cả
                                                    </Button>
                                                </Popconfirm>
                                            </div>
                                        </div>
                                    )
                                },
                                {
                                    key: '4',
                                    label: <span>Thông tin người phụ thuộc <span className="misa-badge-new-orange">Mới</span></span>,
                                    children: (
                                        <div className="misa-pt-4-overflow-x">
                                            <table className="misa-grid-table misa-min-w-800">
                                                <thead>
                                                    <tr>
                                                        <th className="misa-min-w-140">Họ và tên</th>
                                                        <th className="misa-w-110">Quan hệ</th>
                                                        <th className="misa-w-110">Ngày sinh</th>
                                                        <th className="misa-w-110">Mã số thuế</th>
                                                        <th className="misa-w-110">Loại giấy tờ</th>
                                                        <th className="misa-w-110">Số giấy tờ</th>
                                                        <th className="misa-w-100">Bắt đầu tính</th>
                                                        <th className="misa-th-action"></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {dependents.length === 0 ? (
                                                        <tr>
                                                            <td colSpan={8} className="misa-td-empty-muted">
                                                                Chưa có người phụ thuộc nào. Bấm "Thêm người phụ thuộc" bên dưới để thêm.
                                                            </td>
                                                        </tr>
                                                    ) : (
                                                        dependents.map((d, idx) => (
                                                            <tr key={d.id}>
                                                                <td className="misa-td-p3">
                                                                    <input
                                                                        className="misa-table-input"
                                                                        placeholder="Họ tên"
                                                                        value={d.name}
                                                                        onChange={(e) => {
                                                                            const updated = [...dependents];
                                                                            updated[idx].name = e.target.value;
                                                                            setDependents(updated);
                                                                        }}
                                                                    />
                                                                </td>
                                                                <td className="misa-td-p3">
                                                                    <select
                                                                        className="misa-table-input"
                                                                        value={d.relationship}
                                                                        onChange={(e) => {
                                                                            const updated = [...dependents];
                                                                            updated[idx].relationship = e.target.value;
                                                                            setDependents(updated);
                                                                        }}
                                                                    >
                                                                        <option value="Con">Con</option>
                                                                        <option value="Vợ/Chồng">Vợ/Chồng</option>
                                                                        <option value="Cha/Mẹ">Cha/Mẹ</option>
                                                                        <option value="Khác">Khác</option>
                                                                    </select>
                                                                </td>
                                                                <td className="misa-td-p3">
                                                                    <input
                                                                        className="misa-table-input"
                                                                        placeholder="DD/MM/YYYY"
                                                                        value={d.birth_date}
                                                                        onChange={(e) => {
                                                                            const updated = [...dependents];
                                                                            updated[idx].birth_date = e.target.value;
                                                                            setDependents(updated);
                                                                        }}
                                                                    />
                                                                </td>
                                                                <td className="misa-td-p3">
                                                                    <input
                                                                        className="misa-table-input"
                                                                        placeholder="MST"
                                                                        value={d.tax_code}
                                                                        onChange={(e) => {
                                                                            const updated = [...dependents];
                                                                            updated[idx].tax_code = e.target.value;
                                                                            setDependents(updated);
                                                                        }}
                                                                    />
                                                                </td>
                                                                <td className="misa-td-p3">
                                                                    <select
                                                                        className="misa-table-input"
                                                                        value={d.id_type}
                                                                        onChange={(e) => {
                                                                            const updated = [...dependents];
                                                                            updated[idx].id_type = e.target.value;
                                                                            setDependents(updated);
                                                                        }}
                                                                    >
                                                                        <option value="CCCD/CMND">CCCD/CMND</option>
                                                                        <option value="Giấy khai sinh">Giấy khai sinh</option>
                                                                        <option value="Hộ chiếu">Hộ chiếu</option>
                                                                    </select>
                                                                </td>
                                                                <td className="misa-td-p3">
                                                                    <input
                                                                        className="misa-table-input"
                                                                        placeholder="Số GT"
                                                                        value={d.id_number}
                                                                        onChange={(e) => {
                                                                            const updated = [...dependents];
                                                                            updated[idx].id_number = e.target.value;
                                                                            setDependents(updated);
                                                                        }}
                                                                    />
                                                                </td>
                                                                <td className="misa-td-p3">
                                                                    <input
                                                                        className="misa-table-input"
                                                                        placeholder="MM/YYYY"
                                                                        value={d.start_date}
                                                                        onChange={(e) => {
                                                                            const updated = [...dependents];
                                                                            updated[idx].start_date = e.target.value;
                                                                            setDependents(updated);
                                                                        }}
                                                                    />
                                                                </td>
                                                                <td className="misa-td-action">
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => setDependents(dependents.filter((_, i) => i !== idx))}
                                                                        className="misa-btn-icon-danger"
                                                                    >
                                                                        <DeleteOutlined className="misa-font-13" />
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        ))
                                                    )}
                                                </tbody>
                                            </table>
                                            <div className="misa-flex-gap-8-mt6">
                                                <Button
                                                    size="small"
                                                    onClick={() => setDependents([...dependents, {
                                                        id: String(Date.now()),
                                                        name: '',
                                                        relationship: 'Con',
                                                        birth_date: '',
                                                        tax_code: '',
                                                        id_type: 'CCCD/CMND',
                                                        id_number: '',
                                                        start_date: '01/2026',
                                                        end_date: ''
                                                    }])}
                                                    className="misa-btn-tool-secondary"
                                                >
                                                    Thêm người phụ thuộc
                                                </Button>
                                                {dependents.length > 0 && (
                                                    <Popconfirm
                                                        title="Bạn có chắc chắn muốn xóa tất cả người phụ thuộc?"
                                                        onConfirm={() => setDependents([])}
                                                    >
                                                        <Button size="small" className="misa-btn-tool-secondary">
                                                            Xóa tất cả
                                                        </Button>
                                                    </Popconfirm>
                                                )}
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

            {/* Quick Add Department Modal */}
            <Modal
                title="Thêm đơn vị, phòng ban"
                open={isDeptModalOpen}
                onCancel={() => setIsDeptModalOpen(false)}
                zIndex={3000}
                centered={true}
                className="misa-clean-modal"
                onOk={() => {
                    deptForm.validateFields().then(values => {
                        const newOption = { value: values.name, label: values.name };
                        setDepartments(prev => [newOption, ...prev]);
                        form.setFieldValue('department', values.name);
                        message.info('Đã thêm phòng ban vào biểu mẫu; chưa lưu danh mục trên máy chủ.');
                        setIsDeptModalOpen(false);
                        deptForm.resetFields();
                    });
                }}
                width={480}
            >
                <Form form={deptForm} layout="vertical" size="small" className="misa-pt-8">
                    <Form.Item name="code" label="Mã phòng ban">
                        <Input className="misa-input" />
                    </Form.Item>
                    <Form.Item name="name" label="Tên phòng ban" rules={[{ required: true, message: 'Vui lòng nhập tên phòng ban!' }]}>
                        <Input className="misa-input" placeholder="VD: Phòng Dự án, Chi nhánh Đà Nẵng..." />
                    </Form.Item>
                </Form>
            </Modal>

            {/* Quick Add Position Modal */}
            <Modal
                title="Thêm chức danh"
                open={isPositionModalOpen}
                onCancel={() => setIsPositionModalOpen(false)}
                zIndex={3000}
                centered={true}
                className="misa-clean-modal"
                onOk={() => {
                    posForm.validateFields().then(values => {
                        const newOption = { value: values.name, label: values.name };
                        setPositions(prev => [newOption, ...prev]);
                        form.setFieldValue('position', values.name);
                        message.info('Đã thêm chức danh vào biểu mẫu; chưa lưu danh mục trên máy chủ.');
                        setIsPositionModalOpen(false);
                        posForm.resetFields();
                    });
                }}
                width={480}
            >
                <Form form={posForm} layout="vertical" size="small" className="misa-pt-8">
                    <Form.Item name="name" label="Tên chức danh" rules={[{ required: true, message: 'Vui lòng nhập tên chức danh!' }]}>
                        <Input className="misa-input" placeholder="VD: Giám đốc Tài chính, Chuyên viên phân tích..." />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
};

export default QuickAddEmployeeModal;
