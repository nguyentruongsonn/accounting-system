import React, { useState } from 'react';
import { Alert, ConfigProvider, Table, Button, Form, Input, InputNumber, Space, Select, DatePicker, Tabs, Popconfirm } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import { PlusOutlined, DeleteOutlined, FileTextOutlined } from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import dayjs from 'dayjs';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

const { TabPane } = Tabs;

const PayrollNetAmountCell: React.FC<{ lineIndex: number }> = ({ lineIndex }) => {
    const form = Form.useFormInstance();
    const basicSalary = Number(Form.useWatch(['lines', lineIndex, 'basic_salary'], form) || 0);
    const allowance = Number(Form.useWatch(['lines', lineIndex, 'allowance'], form) || 0);
    const deduction = Number(Form.useWatch(['lines', lineIndex, 'deduction'], form) || 0);

    return <strong>{new Intl.NumberFormat('vi-VN').format(basicSalary + allowance - deduction)}</strong>;
};

const PayrollVouchers: React.FC = () => {
    const [isModalVisible, setIsModalVisible] = useState(false);
    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const { data: payrolls = [], isLoading, isError: isPayrollsError, refetch: refetchPayrolls } = useQuery({
        queryKey: ['payrolls'],
        queryFn: async () => {
            const { data } = await api.get('/payroll');
            return data;
        },
    });

    const { data: chartOfAccounts = [], isError: isAccountsError, refetch: refetchAccounts } = useQuery({
        queryKey: ['chart-of-accounts'],
        queryFn: async () => {
            const { data } = await api.get('/master/accounts');
            const rows = Array.isArray(data) ? data : data?.data;
            return Array.isArray(rows) ? rows : [];
        },
    });

    const { data: employees = [], isError: isEmployeesError, refetch: refetchEmployees } = useQuery({
        queryKey: ['employees'],
        queryFn: async () => {
            const { data } = await api.get('/master/employees');
            const rows = Array.isArray(data) ? data : data?.data;
            return Array.isArray(rows) ? rows : [];
        },
    });

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            const payload = {
                voucher_number: values.voucher_number,
                voucher_date: values.voucher_date.format('YYYY-MM-DD'),
                posting_date: values.posting_date.format('YYYY-MM-DD'),
                month: values.month.format('YYYY-MM'),
                description: values.description,
                lines: values.lines?.map((line: any) => ({
                    employee_id: line.employee_id,
                    employee_name: employees?.find((e:any) => e.id === line.employee_id)?.name || line.employee_name,
                    department: line.department,
                    basic_salary: line.basic_salary || 0,
                    allowance: line.allowance || 0,
                    deduction: line.deduction || 0,
                    net_salary: (line.basic_salary || 0) + (line.allowance || 0) - (line.deduction || 0),
                    debit_account: line.debit_account,
                    credit_account: line.credit_account,
                })) || []
            };
            return api.post('/payroll', payload);
        },
        onSuccess: (response) => {
            if (response?.data?.id === undefined || response?.data?.id === null) {
                message.error('Máy chủ không trả về bảng lương đã lưu; không thể báo thành công.');
                return;
            }
            // Creating a payroll voucher persists a draft; posting is a
            // separate guarded action in PayrollList. Do not claim that the
            // draft has already produced accounting entries.
            message.success('Tạo bảng lương dự thảo thành công; cần ghi sổ riêng.');
            setIsModalVisible(false);
            queryClient.invalidateQueries({ queryKey: ['payrolls'] });
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || 'Có lỗi xảy ra!');
        }
    });

    const postMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/payroll/${id}/post`);
        },
        onSuccess: (response) => {
            if (response?.data?.data?.id === undefined || response?.data?.data?.id === null) {
                message.error('Máy chủ không trả về bảng lương đã ghi sổ; không thể báo thành công.');
                return;
            }
            message.success('Ghi sổ thành công');
            queryClient.invalidateQueries({ queryKey: ['payrolls'] });
        },
    });

    const lines = Form.useWatch('lines', form) || [];
    const totalAmount = lines.reduce((acc: number, line: any) => acc + ((line?.basic_salary || 0) + (line?.allowance || 0) - (line?.deduction || 0)), 0);

    const handleOpenModal = () => {
        form.resetFields();
        form.setFieldsValue({
            voucher_date: dayjs(),
            posting_date: dayjs(),
            month: dayjs(),
            lines: [{}]
        });
        setIsModalVisible(true);
    };

    return (
        <PageShell title={<PageHeader eyebrow="Tiền lương" title="Bảng lương & Hạch toán chi phí" description="Quản lý các kỳ trả lương và trạng thái ghi sổ." />} toolbar={<PageToolbar leading={<Button type="default" icon={<FileTextOutlined />}>Báo cáo</Button>} actions={<Space>
                    <Button 
                        icon={<PlusOutlined />} 
                        onClick={handleOpenModal}
                        className="misa-btn-primary"
                        style={{ borderRadius: 999, height: 36, padding: '0 20px' }}
                    >
                        Thêm Bảng lương
                    </Button>
                </Space>} />}>

            <DataTableSurface className="payroll-vouchers-table-surface">
                {(isPayrollsError || isAccountsError || isEmployeesError) && (
                    <Alert
                        className="mb-3"
                        type="error"
                        showIcon
                        message="Không thể tải dữ liệu bảng lương"
                        description="Danh sách hoặc danh mục liên quan chưa được máy chủ trả về; không dùng dữ liệu rỗng thay thế."
                        action={<Button size="small" onClick={() => {
                            if (isPayrollsError) void refetchPayrolls();
                            if (isAccountsError) void refetchAccounts();
                            if (isEmployeesError) void refetchEmployees();
                        }}>Thử lại bảng lương</Button>}
                    />
                )}
                <Table 
                columns={[
                    { title: 'Kỳ trả lương (Tháng)', dataIndex: 'month', key: 'month' },
                    { title: 'Ngày hạch toán', dataIndex: 'posting_date', key: 'posting_date' },
                    { title: 'Số chứng từ', dataIndex: 'voucher_number', key: 'voucher_number' },
                    { title: 'Diễn giải', dataIndex: 'description', key: 'description' },
                    { 
                        title: 'Tổng lương thực nhận', 
                        dataIndex: 'total_amount', 
                        key: 'total_amount',
                        render: (val: number) => new Intl.NumberFormat('vi-VN').format(val)
                    },
                    { 
                        title: 'Trạng thái', 
                        dataIndex: 'is_posted', 
                        key: 'is_posted',
                        render: (posted: boolean) => (
                            <span className={`px-2 py-1 rounded text-xs ${posted ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}`}>
                                {posted ? 'Đã ghi sổ' : 'Bản nháp'}
                            </span>
                        )
                    },
                    {
                        title: 'Chức năng',
                        key: 'action',
                        render: (_: any, record: any) => (
                            <Space size="middle">
                                {!record.is_posted && (
                                    <Button type="link" onClick={() => postMutation.mutate(record.id)}>Ghi sổ</Button>
                                )}
                            </Space>
                        ),
                    },
                ]} 
                 dataSource={payrolls} 
                rowKey="id" 
                loading={isLoading} 
                size="small"
                bordered
            /></DataTableSurface>

            <Modal
                title={
                    <div className="flex justify-between items-center w-full pr-8 border-b pb-2 mb-2">
                        <span className="text-xl font-bold">Bảng tính lương & Hạch toán chi phí</span>
                        <div className="text-right flex items-center gap-4">
                            <span className="text-sm text-gray-500 block">Tổng lương thực nhận</span>
                            <span className="text-2xl font-bold text-gray-800 w-40">
                                {new Intl.NumberFormat('vi-VN').format(totalAmount)}
                            </span>
                        </div>
                    </div>
                }
                open={isModalVisible}
                onCancel={() => setIsModalVisible(false)}
                width="95vw"
                footer={
                    <ConfigProvider componentSize="small">
                        <div className="misa-modal-footer-container">
                            <div className="text-slate-600 text-sm flex gap-4">
                                <span>Trạng thái: Bản nháp</span>
                                <span className="text-blue-600 cursor-pointer hover:underline">Tệp đính kèm (0)</span>
                            </div>
                            <Space>
                                <Button 
                                    onClick={() => setIsModalVisible(false)}
                                    className="misa-btn-secondary"
                                >
                                    Hủy
                                </Button>
                                <Button 
                                    onClick={() => form.submit()} 
                                    className="misa-btn-secondary"
                                    loading={mutation.isPending}
                                >
                                    Cất
                                </Button>
                                <Button 
                                    type="primary" 
                                    onClick={() => form.submit()} 
                                    className="misa-btn-primary"
                                    loading={mutation.isPending}
                                >
                                    Cất và Thêm
                                </Button>
                            </Space>
                        </div>
                    </ConfigProvider>
                }
            >
                <Form form={form} layout="vertical" onFinish={mutation.mutate} size="small">
                    <div className="flex gap-6 mb-4">
                        <div className="flex-1 border p-4 rounded bg-gray-50">
                            <h3 className="font-bold text-slate-700 mb-2">Thông tin chung</h3>
                            <div className="grid grid-cols-2 gap-x-4">
                                <Form.Item name="month" label="Kỳ tính lương (Tháng)" rules={[{ required: true }]} className="mb-2">
                                    <DatePicker picker="month" format="YYYY-MM" className="w-full" />
                                </Form.Item>
                                <Form.Item name="description" label="Diễn giải" className="mb-2 col-span-2">
                                    <Input placeholder="Ví dụ: Hạch toán chi phí lương tháng 8/2026" />
                                </Form.Item>
                            </div>
                        </div>

                        <div className="w-[350px] border p-4 rounded bg-gray-50">
                            <h3 className="font-bold text-slate-700 mb-2">Chứng từ</h3>
                            <div className="grid grid-cols-2 gap-x-4">
                                <Form.Item name="posting_date" label="Ngày hạch toán" rules={[{ required: true }]} className="mb-2">
                                    <DatePicker className="w-full" format="YYYY-MM-DD" />
                                </Form.Item>
                                <Form.Item name="voucher_date" label="Ngày chứng từ" rules={[{ required: true }]} className="mb-2">
                                    <DatePicker className="w-full" format="YYYY-MM-DD" />
                                </Form.Item>
                                <Form.Item name="voucher_number" label="Số chứng từ" rules={[{ required: true }]} className="col-span-2 mb-2">
                                    <Input />
                                </Form.Item>
                            </div>
                        </div>
                    </div>

                    <div className="border border-gray-200 rounded">
                        <Tabs type="card" size="small" className="misa-tabs">
                            <TabPane tab="1. Chi tiết lương & Hạch toán" key="1">
                                <Form.List name="lines">
                                    {(fields, { add, remove }) => (
                                        <>
                                            <Table 
                                                dataSource={fields}
                                                pagination={false}
                                                size="small"
                                                rowKey="key"
                                                scroll={{ x: 'max-content' }}
                                                columns={[
                                                    { 
                                                        title: '#', 
                                                        width: 50,
                                                        render: (_, __, index) => (
                                                            <Popconfirm title="Xóa dòng?" onConfirm={() => remove(index)}>
                                                                <Button type="text" danger icon={<DeleteOutlined />} size="small" />
                                                            </Popconfirm>
                                                        )
                                                    },
                                                    { 
                                                        title: 'Nhân viên', 
                                                        width: 200,
                                                        render: (_, field) => (
                                                            <Form.Item name={[field.name, 'employee_id']} noStyle rules={[{ required: true }]}>
                                                                <Select showSearch className="w-full">
                                                                    {employees?.map((i:any) => <Select.Option key={i.id} value={i.id}>{i.code} - {i.name}</Select.Option>)}
                                                                </Select>
                                                            </Form.Item>
                                                        )
                                                    },
                                                    { 
                                                        title: 'Phòng ban', 
                                                        width: 150,
                                                        render: (_, field) => (
                                                            <Form.Item name={[field.name, 'department']} noStyle>
                                                                <Input />
                                                            </Form.Item>
                                                        )
                                                    },
                                                    { 
                                                        title: 'Lương cơ bản', 
                                                        width: 150,
                                                        render: (_, field) => (
                                                            <Form.Item name={[field.name, 'basic_salary']} noStyle initialValue={0}>
                                                                <InputNumber formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} className="w-full" min={0} />
                                                            </Form.Item>
                                                        )
                                                    },
                                                    { 
                                                        title: 'Phụ cấp', 
                                                        width: 120,
                                                        render: (_, field) => (
                                                            <Form.Item name={[field.name, 'allowance']} noStyle initialValue={0}>
                                                                <InputNumber formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} className="w-full" min={0} />
                                                            </Form.Item>
                                                        )
                                                    },
                                                    { 
                                                        title: 'Khấu trừ (Thuế, BH)', 
                                                        width: 150,
                                                        render: (_, field) => (
                                                            <Form.Item name={[field.name, 'deduction']} noStyle initialValue={0}>
                                                                <InputNumber formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} className="w-full" min={0} />
                                                            </Form.Item>
                                                        )
                                                    },
                                                    { 
                                                        title: 'Thực nhận', 
                                                        width: 150,
                                                        render: (_, field) => <PayrollNetAmountCell lineIndex={field.name} />
                                                    },
                                                    { 
                                                        title: 'TK Nợ (Chi phí)', 
                                                        width: 120,
                                                        render: (_, field) => (
                                                             <Form.Item name={[field.name, 'debit_account']} noStyle rules={[{ required: true, message: 'Chọn tài khoản Nợ từ danh mục máy chủ.' }]}>
                                                                <Select showSearch>
                                                                     {chartOfAccounts?.filter((a:any) => !a.is_parent && a.is_active !== false).map((acc:any) => <Select.Option key={acc.code} value={acc.code}>{acc.code}</Select.Option>)}
                                                                </Select>
                                                            </Form.Item>
                                                        )
                                                    },
                                                    { 
                                                        title: 'TK Có (Phải trả)', 
                                                        width: 120,
                                                        render: (_, field) => (
                                                             <Form.Item name={[field.name, 'credit_account']} noStyle rules={[{ required: true, message: 'Chọn tài khoản Có từ danh mục máy chủ.' }]}>
                                                                <Select showSearch>
                                                                     {chartOfAccounts?.filter((a:any) => !a.is_parent && a.is_active !== false).map((acc:any) => <Select.Option key={acc.code} value={acc.code}>{acc.code}</Select.Option>)}
                                                                </Select>
                                                            </Form.Item>
                                                        )
                                                    },
                                                ]}
                                            />
                                            <Button type="dashed" onClick={() => add()} block icon={<PlusOutlined />} className="mt-2 text-left">
                                                Thêm nhân viên
                                            </Button>
                                        </>
                                    )}
                                </Form.List>
                            </TabPane>
                        </Tabs>
                    </div>

                </Form>
            </Modal>
            <style>{`
                .misa-tabs .ant-tabs-nav { margin-bottom: 0 !important; background: #fafbfc; border-bottom: 1px solid #e5e7eb; }
                .misa-tabs .ant-tabs-tab { border-radius: 4px 4px 0 0 !important; border-bottom: none !important; }
                .misa-tabs .ant-tabs-tab-active { background: #fff !important; font-weight: bold; border-top: 2px solid var(--misa-primary, #0064e0) !important; }
            `}</style>
        </PageShell>
    );
};

export default PayrollVouchers;
