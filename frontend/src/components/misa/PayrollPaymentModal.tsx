import React, { useState, useMemo } from 'react';
import { Alert, InputNumber, Button, Table, DatePicker, Select } from 'antd';
import { toast as message } from '../feedback/toast';
import Modal from '../layout/AppModal';
import type { ColumnsType } from 'antd/es/table';
import { TeamOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import api from '../../api/axios';

export interface PayrollEmployeeItem {
    id: number | string;
    employee_code: string;
    employee_name: string;
    department: string;
    net_salary: number; // Lương thực lĩnh
    paid_salary: number; // Đã trả
    remaining_salary: number; // Còn phải trả
    pay_amount: number; // Số trả lần này
}

interface PayrollPaymentModalProps {
    open: boolean;
    onClose: () => void;
    onSuccess?: (employees: PayrollEmployeeItem[], total: number) => void;
}


export const PayrollPaymentModal: React.FC<PayrollPaymentModalProps> = ({
    open,
    onClose,
    onSuccess
}) => {
    const [period, setPeriod] = useState<string>(`Bảng lương Tháng ${dayjs().subtract(1, 'month').format('MM/YYYY')}`);
    const [departmentFilter, setDepartmentFilter] = useState('all');
    const [tableData, setTableData] = useState<PayrollEmployeeItem[]>([]);
    const [selectedIds, setSelectedIds] = useState<any[]>([]);

    const { data: rawEmployees } = useQuery({
        queryKey: ['employees'],
        queryFn: async () => {
            const { data } = await api.get('/master/employees');
            return Array.isArray(data) ? data : (data?.data || []);
        },
        enabled: open
    });

    React.useEffect(() => {
        if (!open) return;
        const list: PayrollEmployeeItem[] = (rawEmployees || [])
            .map((emp: any) => {
                // The employee master endpoint is not a payroll statement endpoint.
                // Only expose rows when the server supplied payroll amounts explicitly;
                // never manufacture salary values or employee identities in the UI.
                const netSalary = emp?.net_salary ?? emp?.payroll?.net_salary;
                if (emp?.id === undefined || emp?.id === null || netSalary === undefined || netSalary === null) {
                    return null;
                }
                const paidSalary = emp?.paid_salary ?? emp?.payroll?.paid_salary ?? 0;
                const remainingSalary = emp?.remaining_salary ?? Math.max(0, Number(netSalary) - Number(paidSalary));
                return {
                    id: emp.id,
                    employee_code: emp.code || emp.employee_code || '',
                    employee_name: emp.name || emp.employee_name || '',
                    department: emp.department || emp.department_name || '',
                    net_salary: Number(netSalary),
                    paid_salary: Number(paidSalary),
                    remaining_salary: Number(remainingSalary),
                    pay_amount: Number(emp?.pay_amount ?? remainingSalary)
                };
            })
            .filter((item: PayrollEmployeeItem | null): item is PayrollEmployeeItem => item !== null);
        setTableData(list);
        setSelectedIds(list.map(e => e.id));
    }, [open, rawEmployees]);

    const handleAmountChange = (id: number | string, val: number) => {
        const safeVal = Number(val) || 0;
        setTableData(prev => prev.map(item => item.id === id ? { ...item, pay_amount: safeVal } : item));
        if (safeVal > 0) {
            setSelectedIds(prev => prev.includes(id) ? prev : [...prev, id]);
        }
    };

    const handleRowSelectionChange = (newSelectedKeys: React.Key[]) => {
        const keySet = new Set(newSelectedKeys.map(String));
        setSelectedIds(newSelectedKeys);
        setTableData(prev => prev.map(item => {
            if (keySet.has(String(item.id))) {
                return {
                    ...item,
                    pay_amount: (item.pay_amount || 0) > 0 ? item.pay_amount : (item.remaining_salary || 0)
                };
            } else {
                return {
                    ...item,
                    pay_amount: 0
                };
            }
        }));
    };

    const selectedEmployees = useMemo(() => {
        return (Array.isArray(tableData) ? tableData : []).filter(item => selectedIds.includes(item.id) && (item.pay_amount || 0) > 0);
    }, [tableData, selectedIds]);

    const totalPay = useMemo(() => {
        return (Array.isArray(selectedEmployees) ? selectedEmployees : []).reduce(
            (sum, item) => sum + (Number(item?.pay_amount) || 0), 
            0
        );
    }, [selectedEmployees]);

    const handleConfirm = () => {
        if (!Array.isArray(selectedEmployees) || selectedEmployees.length === 0 || totalPay <= 0) {
            message.warning('Vui lòng chọn ít nhất một nhân viên có số tiền trả lương > 0!');
            return;
        }

        if (onSuccess) {
            onSuccess(selectedEmployees, totalPay);
        } else {
            window.dispatchEvent(new CustomEvent('open-cash-payment', {
                detail: {
                    presetType: '5. Trả lương cho nhân viên',
                    prefillData: {
                        contact_id: undefined,
                        contact_name: undefined,
                        receiver_name: undefined,
                        receiver_address: undefined,
                        reason: `Chi trả lương nhân viên ${period} (${selectedEmployees.length} nhân viên)`,
                        lines: selectedEmployees.map((emp, idx) => ({
                            key: String(idx + 1),
                            description: `Trả lương ${emp.employee_name || ''} (${emp.employee_code || ''}) - ${period}`,
                            amount: Number(emp.pay_amount) || 0,
                            operation: 'Trả lương cho nhân viên',
                            line_contact_id: emp.employee_code || '',
                            line_contact_name: emp.employee_name || ''
                        }))
                    }
                }
            }));
        }

        onClose();
    };

    const columns: ColumnsType<PayrollEmployeeItem> = [
        {
            title: 'Mã NV',
            dataIndex: 'employee_code',
            key: 'employee_code',
            width: 100,
            render: (text) => <span className="misa-fw-700">{text}</span>
        },
        {
            title: 'Họ và tên',
            dataIndex: 'employee_name',
            key: 'employee_name',
            width: 200,
            render: (text) => <span className="misa-fw-600 misa-color-blue">{text}</span>
        },
        {
            title: 'Phòng ban',
            dataIndex: 'department',
            key: 'department'
        },
        {
            title: 'Lương thực lĩnh',
            dataIndex: 'net_salary',
            key: 'net_salary',
            align: 'right',
            width: 140,
            render: (val) => `${new Intl.NumberFormat('vi-VN').format(Number(val) || 0)} ₫`
        },
        {
            title: 'Còn phải trả',
            dataIndex: 'remaining_salary',
            key: 'remaining_salary',
            align: 'right',
            width: 140,
            render: (val) => <span className="misa-fw-700 misa-color-red">{new Intl.NumberFormat('vi-VN').format(Number(val) || 0)} ₫</span>
        },
        {
            title: 'Số trả lần này',
            dataIndex: 'pay_amount',
            key: 'pay_amount',
            align: 'right',
            width: 170,
            render: (val, record) => (
                <InputNumber
                    className="misa-w-full text-right font-semibold"
                    min={0}
                    max={record.remaining_salary}
                    value={val}
                    formatter={v => (v !== undefined && v !== null && v !== '') ? `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',') : ''}
                    parser={(v) => (v ? Number(String(v).replace(/\$\s?|(,*)/g, '')) : 0) as any}
                    onChange={(n) => handleAmountChange(record.id, Number(n) || 0)}
                />
            )
        }
    ];

    return (
        <Modal
            title={
                <div className="misa-flex-between misa-pr-24">
                    <span className="misa-modal-title">Chi trả tiền lương nhân viên</span>
                </div>
            }
            open={open}
            onCancel={onClose}
            width={1100}
            footer={
                <div className="misa-flex-between">
                    <div>
                        <span className="apple-muted-text">Tổng số tiền trả lương ({(selectedEmployees || []).length} NV): </span>
                        <strong className="misa-fs-16 misa-color-red">
                            {new Intl.NumberFormat('vi-VN').format(totalPay)} ₫
                        </strong>
                    </div>
                    <div className="misa-flex misa-gap-8">
                        <Button onClick={onClose}>Hủy bỏ</Button>
                        <Button type="primary" danger className="misa-btn-danger" onClick={handleConfirm} disabled={selectedEmployees.length === 0}>
                            <TeamOutlined /> Trả lương ({(selectedEmployees || []).length})
                        </Button>
                    </div>
                </div>
            }
            centered
            className="misa-custom-modal"
        >
            {tableData.length === 0 && (
                <Alert
                    className="apple-section-gap"
                    type="warning"
                    showIcon
                    message="Chi trả lương chưa khả dụng"
                    description="Chưa có API cung cấp bảng lương và số dư phải trả đã kiểm chứng; không thể lập phiếu chi từ danh mục nhân viên."
                />
            )}
            <div className="apple-section-gap misa-bg-light misa-p-12 misa-rounded-6">
                <div className="misa-form-grid">
                    <div className="misa-col-6">
                        <div className="misa-field-label">Bảng lương</div>
                        <Select
                            className="misa-w-full"
                            value={period}
                            onChange={setPeriod}
                            options={[
                                { value: `Bảng lương Tháng ${dayjs().subtract(1, 'month').format('MM/YYYY')}`, label: `Bảng lương Tháng ${dayjs().subtract(1, 'month').format('MM/YYYY')}` },
                                { value: `Bảng lương Tháng ${dayjs().subtract(2, 'month').format('MM/YYYY')}`, label: `Bảng lương Tháng ${dayjs().subtract(2, 'month').format('MM/YYYY')}` }
                            ]}
                        />
                    </div>
                    <div className="misa-col-3">
                        <div className="misa-field-label">Phòng ban</div>
                        <Select
                            className="misa-w-full"
                            value={departmentFilter}
                            onChange={setDepartmentFilter}
                            options={[
                                { value: 'all', label: 'Tất cả phòng ban' },
                                { value: 'bgd', label: 'Ban Giám đốc' },
                                { value: 'kt', label: 'Phòng Kế toán' },
                                { value: 'kd', label: 'Phòng Kinh doanh' }
                            ]}
                        />
                    </div>
                    <div className="misa-col-3">
                        <div className="misa-field-label">Ngày trả lương</div>
                        <DatePicker className="misa-w-full" defaultValue={dayjs()} format="DD/MM/YYYY" />
                    </div>
                </div>
            </div>

            <Table
                columns={columns}
                dataSource={tableData}
                rowKey="id"
                size="small"
                pagination={false}
                bordered
                rowSelection={{
                    selectedRowKeys: selectedIds,
                    onChange: (keys) => handleRowSelectionChange(keys)
                }}
            />
        </Modal>
    );
};
