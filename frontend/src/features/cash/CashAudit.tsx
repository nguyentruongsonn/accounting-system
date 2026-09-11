import React, { useEffect, useState } from 'react';
import { Table, Button, ConfigProvider, Form, Input, InputNumber, DatePicker, TimePicker, Checkbox, Collapse, Empty } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import { 
    PlusOutlined, 
    DeleteOutlined, 
    InboxOutlined, 
    DownOutlined, 
    ReloadOutlined,
    SearchOutlined,
    QrcodeOutlined,
    PaperClipOutlined,
    CalendarOutlined
} from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import dayjs from 'dayjs';
import { formatDate } from '../../utils/dateUtils';
import AccountSelect from '../../components/misa/AccountSelect';
import type { AccountItem } from '../../components/misa/AccountSelect';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';


interface DenominationRow {
    key: string;
    value: number;
    quantity: number;
    amount: number;
    description: string;
}

const DEFAULT_DENOMINATIONS: DenominationRow[] = [
    { key: 'denom-1', value: 500000, quantity: 0, amount: 0, description: 'Loại mệnh giá 500000' },
    { key: 'denom-2', value: 200000, quantity: 0, amount: 0, description: 'Loại mệnh giá 200000' },
    { key: 'denom-3', value: 100000, quantity: 0, amount: 0, description: 'Loại mệnh giá 100000' },
    { key: 'denom-4', value: 50000, quantity: 0, amount: 0, description: 'Loại mệnh giá 50000' },
    { key: 'denom-5', value: 20000, quantity: 0, amount: 0, description: 'Loại mệnh giá 20000' },
    { key: 'denom-6', value: 10000, quantity: 0, amount: 0, description: 'Loại mệnh giá 10000' },
    { key: 'denom-7', value: 5000, quantity: 0, amount: 0, description: 'Loại mệnh giá 5000' },
    { key: 'denom-8', value: 2000, quantity: 0, amount: 0, description: 'Loại mệnh giá 2000' },
    { key: 'denom-9', value: 1000, quantity: 0, amount: 0, description: 'Loại mệnh giá 1000' },
    { key: 'denom-10', value: 500, quantity: 0, amount: 0, description: 'Loại mệnh giá 500' },
];

const INTERNAL_AUDIT_MEMBER_ROLES = [
    { key: 'admin', role: 'Admin' },
    { key: 'accountant', role: 'Kế toán' },
] as const;

interface CashAuditProps {
    active?: boolean;
}

export const CashAudit: React.FC<CashAuditProps> = React.memo(({ active = true }) => {

    const [isPromptOpen, setIsPromptOpen] = useState(false);
    const [isAuditModalVisible, setIsAuditModalVisible] = useState(false);
    const [auditDate, setAuditDate] = useState<dayjs.Dayjs>(dayjs());
    const [denominations, setDenominations] = useState<DenominationRow[]>(DEFAULT_DENOMINATIONS);
    const [cashAccountCode, setCashAccountCode] = useState<string | undefined>();
    const [auditSearch, setAuditSearch] = useState('');
    
    const cashAccountsQuery = useQuery<AccountItem[]>({
        queryKey: ['cash-audit-accounts'],
        queryFn: async () => {
            const { data } = await api.get('/master/accounts');
            const rows = Array.isArray(data) ? data : (Array.isArray(data?.data) ? data.data : []);
            return rows.filter((row: any) => row && row.is_active !== false);
        },
        enabled: active,
        retry: false,
    });

    const bookBalanceQuery = useQuery({
        queryKey: ['cash-book-balance', cashAccountCode, auditDate?.format('YYYY-MM-DD')],
        queryFn: async () => {
            const { data } = await api.get('/cash/book-balance', {
                params: { account_code: cashAccountCode, as_of_date: auditDate.format('YYYY-MM-DD') },
            });
            return data?.data ?? null;
        },
        enabled: active && Boolean(cashAccountCode) && dayjs.isDayjs(auditDate),
        retry: false,
    });

    const bookBalance = bookBalanceQuery.data?.status === 'available'
        ? Number(bookBalanceQuery.data.balance)
        : null;

    useEffect(() => {
        if (bookBalanceQuery.isError) message.error('Không thể xác minh số dư sổ sách tiền mặt.');
    }, [bookBalanceQuery.isError]);
    useEffect(() => {
        if (cashAccountsQuery.isError) message.error('Không thể tải danh mục tài khoản tiền mặt.');
    }, [cashAccountsQuery.isError]);
    const actualTotal = (Array.isArray(denominations) ? denominations : []).reduce((sum, row) => sum + (Number(row?.amount) || 0), 0);
    const difference = bookBalance === null ? null : bookBalance - actualTotal;

    const queryClient = useQueryClient();

    const auditListQuery = useQuery({
        queryKey: ['cash-inventories'],
        queryFn: async () => {
            const { data } = await api.get('/cash-inventories');
            if (!Array.isArray(data)) {
                throw new Error('Malformed cash-inventories response');
            }
            return data;
        },
        enabled: active,
    });
    useEffect(() => {
        if (auditListQuery.isError) message.error('Không thể tải danh sách kiểm kê quỹ.');
    }, [auditListQuery.isError]);
    const auditList = Array.isArray(auditListQuery.data) ? auditListQuery.data : [];
    const filteredAuditList = (Array.isArray(auditList) ? auditList : []).filter((row: any) => {
        const needle = auditSearch.trim().toLowerCase();
        if (!needle) return true;
        return [row.audit_number, row.purpose, row.audit_date, row.status]
            .some((value) => String(value ?? '').toLowerCase().includes(needle));
    });

    const mutation = useMutation({
        mutationFn: async (payload: any) => {
            const { data } = await api.post('/cash-inventories', payload);
            return data;
        },
        onSuccess: (response: any) => {
            // CashInventoryController returns the persisted model directly (201).
            // Do not close/invalidate the form on a malformed 2xx response.
            const persisted = response?.data ?? response;
            if (!persisted || persisted.id === undefined || persisted.id === null) {
                message.error('Máy chủ chưa trả về bảng kiểm kê quỹ đã lưu.');
                return;
            }
            message.success('Đã lưu bảng kiểm kê quỹ thành công!');
            setIsAuditModalVisible(false);
            queryClient.invalidateQueries({ queryKey: ['cash-inventories'] });
        },
        onError: (err: any) => {
            message.error(err?.response?.data?.error || 'Lỗi khi lưu bảng kiểm kê');
        }
    });

    const [form] = Form.useForm();
    const auditNumber = Form.useWatch('audit_number', form);

    const handleQuantityChange = (key: string, qty: number | null) => {
        const val = Number(qty) || 0;
        setDenominations(prev => (Array.isArray(prev) ? prev : []).map(row => {
            if (row.key === key) {
                return {
                    ...row,
                    quantity: val,
                    amount: (Number(row.value) || 0) * val
                };
            }
            return row;
        }));
    };

    const handleOpenAudit = async () => {
        setIsPromptOpen(false);
        setDenominations(DEFAULT_DENOMINATIONS);
        const formattedDate = dayjs.isDayjs(auditDate) ? auditDate.format('DD/MM/YYYY') : formatDate(auditDate);
        let nextAuditNumber: string | undefined;
        try {
            const { data } = await api.get('/cash-inventories/next-code');
            nextAuditNumber = data?.data?.code;
        } catch {
            // The server remains the authority if number allocation is unavailable.
        }
        form.setFieldsValue({
            audit_number: nextAuditNumber,
            purpose: `Kiểm kê tiền mặt tại quỹ đến ngày ${formattedDate}`,
            audit_date: auditDate,
            doc_date: dayjs(),
            doc_time: dayjs(),
            currency: 'VND'
        });
        setIsAuditModalVisible(true);
    };

    const handleSaveAudit = () => {
        if (bookBalance === null || difference === null) {
            message.warning('Chưa thể cất kiểm kê: backend chưa cung cấp số dư sổ sách tiền mặt.');
            return;
        }

        const values = form.getFieldsValue();
        const payload = {
            audit_number: values.audit_number,
            audit_date: dayjs(values.audit_date).format('YYYY-MM-DD'),
            purpose: values.purpose,
            currency: values.currency,
            account_code: cashAccountCode,
            book_balance: bookBalance,
            actual_balance: actualTotal,
            difference: difference,
            status: 'Đã hoàn thành',
            lines: denominations.filter(d => d.quantity > 0).map(d => ({
                denomination: d.value,
                quantity: d.quantity,
                amount: d.amount
            }))
        };
        mutation.mutate(payload);
    };

    return (
        <PageShell title={<PageHeader eyebrow="Tiền mặt" title="Kiểm kê quỹ" description="Đối chiếu tiền mặt thực tế với số dư sổ kế toán." />}>
            <PageToolbar
                filters={<Input className="misa-input misa-w-280" placeholder="Tìm kiếm chứng từ kiểm kê..." prefix={<SearchOutlined />} allowClear value={auditSearch} onChange={(event) => setAuditSearch(event.target.value)} />}
                actions={<><button type="button" className="misa-btn-tool" title="Làm mới" onClick={() => void auditListQuery.refetch()} disabled={auditListQuery.isFetching}><ReloadOutlined /></button><Button type="primary" icon={<CalendarOutlined />} className="misa-btn-primary-green misa-btn-action-h32-b600" onClick={() => setIsPromptOpen(true)}>Kiểm kê quỹ đến ngày...</Button></>}
            />

            {bookBalance === null && <div className="cash-audit-availability" role="status">
                <span>Đối chiếu sổ sách tiền mặt chưa khả dụng</span>
                {bookBalanceQuery.isError && <Button size="small" onClick={() => void bookBalanceQuery.refetch()}>Thử lại số dư sổ</Button>}
            </div>}

            {/* List of Audits */}
            <DataTableSurface>
            {auditListQuery.isLoading ? (
                <div className="misa-empty-state misa-cash-audit-empty">
                    <Empty description="Đang tải danh sách kiểm kê quỹ..." />
                </div>
            ) : auditListQuery.isError ? (
                <div className="misa-empty-state misa-cash-audit-empty">
                    <Empty description="Không thể tải danh sách kiểm kê quỹ" />
                    <Button size="small" onClick={() => void auditListQuery.refetch()}>Thử lại</Button>
                </div>
            ) : filteredAuditList.length === 0 && !auditSearch ? (
                <div className="misa-empty-state misa-cash-audit-empty">
                    <Empty description="Chưa có biên bản kiểm kê quỹ" />
                    <p className="misa-color-muted misa-text-center">Thêm biên bản để ghi nhận kết quả kiểm kê tiền mặt thực tế.</p>
                    <div className="misa-flex-center misa-justify-center misa-gap-8">
                        <Button type="primary" icon={<CalendarOutlined />} onClick={() => setIsPromptOpen(true)}>Thêm</Button>
                        <Button onClick={() => message.info('Danh sách chứng từ sẽ hiển thị khi máy chủ có dữ liệu.')}>Xem danh sách chứng từ</Button>
                    </div>
                </div>
            ) : (
            <Table 
                className="misa-voucher-table"
                columns={[
                    { title: 'Số chứng từ', dataIndex: 'audit_number', key: 'audit_number', width: 140, render: (t) => <span className="misa-btn-link-action-bold">{t}</span> },
                    { title: 'Ngày kiểm kê', dataIndex: 'audit_date', key: 'audit_date', width: 130 },
                    { title: 'Mục đích kiểm kê', dataIndex: 'purpose', key: 'purpose' },
                    { 
                        title: 'Số dư sổ sách', 
                        dataIndex: 'book_balance', 
                        key: 'book_balance', 
                        align: 'right', 
                        width: 140,
                        render: (v: number) => new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(v) 
                    },
                    { 
                        title: 'Số thực tế', 
                        dataIndex: 'actual_balance', 
                        key: 'actual_balance', 
                        align: 'right', 
                        width: 140,
                        render: (v: number) => <span className="misa-text-bold">{new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(v)}</span>
                    },
                    { 
                        title: 'Chênh lệch', 
                        dataIndex: 'difference', 
                        key: 'difference', 
                        align: 'right', 
                        width: 130,
                        render: (v: number) => <span className={v < 0 ? 'misa-color-red' : 'misa-color-primary'}>{new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(v)}</span>
                    },
                    {
                        title: 'Trạng thái',
                        dataIndex: 'status',
                        key: 'status',
                        width: 140,
                        align: 'center',
                        render: (s: string) => (
                            <span className="misa-apple-pill misa-apple-pill-green">
                                <span className="misa-apple-pill-dot" />
                                {s}
                            </span>
                        )
                    }
                ]}
                dataSource={filteredAuditList}
                rowKey="id"
                size="small"
                bordered
                scroll={{ x: 'max-content', y: 'calc(100vh - 280px)' }}
            />
            )}
            </DataTableSurface>

            {/* Prompt Dialog: Chọn ngày kiểm kê */}
            <Modal
                title="Chọn ngày kiểm kê quỹ"
                open={isPromptOpen}
                onCancel={() => setIsPromptOpen(false)}
                onOk={handleOpenAudit}
                okText="Đồng ý"
                cancelText="Hủy"
                okButtonProps={{ className: 'misa-btn-primary-green', disabled: bookBalance === null || cashAccountsQuery.isLoading || cashAccountsQuery.isError }}
                width={400}
            >
                <div className="misa-p-16">
                    {cashAccountsQuery.isError && <div className="cash-audit-inline-error" role="alert">
                        <span>Không thể tải danh mục tài khoản tiền mặt</span>
                        <Button size="small" onClick={() => void cashAccountsQuery.refetch()}>Thử lại danh mục tài khoản</Button>
                    </div>}
                    <div className="misa-field-label misa-mb-6">Tài khoản tiền mặt:</div>
                    <AccountSelect
                        accounts={cashAccountsQuery.data ?? []}
                        value={cashAccountCode}
                        onChange={(value) => setCashAccountCode(value || undefined)}
                        allowClear
                        disabled={cashAccountsQuery.isLoading}
                        placeholder="Chọn tài khoản chi tiết từ máy chủ"
                    />
                    <div className="misa-field-label misa-mt-12 misa-mb-6">Kiểm kê quỹ đến ngày:</div>
                    <DatePicker 
                        value={auditDate} 
                        onChange={(d) => d && setAuditDate(d)} 
                        format="DD/MM/YYYY" 
                        className="misa-input misa-w-full"
                    />
                </div>
            </Modal>

            {/* Full MISA Cash Audit Modal (Screenshot 2) */}
            <Modal
                title={
                    <div className="misa-audit-custom-header">
                        <div className="misa-flex-center misa-gap-12">
                            <div className="misa-circle-icon-btn">
                                <ReloadOutlined className="misa-fs-13" />
                            </div>
                            <span className="misa-fs-18 misa-fw-700 misa-color-dark">
                                Bảng kiểm kê quỹ {auditNumber || '—'}
                            </span>
                        </div>
                    </div>
                }
                open={isAuditModalVisible}
                onCancel={() => setIsAuditModalVisible(false)}
                width="96vw"
                className="misa-audit-full-modal"
                footer={
                    <ConfigProvider componentSize="small">
                        <div className="misa-modal-btn-footer-pt10">
                            <Button onClick={() => setIsAuditModalVisible(false)} className="misa-btn-secondary">
                                Hủy
                            </Button>
                            <Button onClick={handleSaveAudit} disabled={bookBalance === null} className="misa-btn-secondary">
                                Cất
                            </Button>
                            <Button
                                type="primary"
                                onClick={handleSaveAudit}
                                disabled={bookBalance === null}
                                className="misa-btn-primary"
                            >
                                <span>Cất và In</span>
                                <DownOutlined className="misa-fs-10" />
                            </Button>
                        </div>
                    </ConfigProvider>
                }
                closable={true}
            >
                <Form form={form} layout="vertical" size="small">
                    {/* Top Section */}
                    <div className="misa-audit-top-section">
                        <div className="misa-flex misa-gap-24">
                            {/* Left (65%) */}
                            <div className="misa-flex-1">
                                <div className="misa-mb-8">
                                    <div className="misa-field-label">Mục đích</div>
                                    <Form.Item name="purpose" noStyle>
                                        <Input 
                                            className="misa-input" 
                                            suffix={<QrcodeOutlined className="misa-color-purple misa-fs-14" />} 
                                        />
                                    </Form.Item>
                                </div>
                                <div className="misa-flex misa-gap-16">
                                    <div className="misa-w-160">
                                        <div className="misa-field-label">Kiểm kê đến ngày</div>
                                        <Form.Item name="audit_date" noStyle>
                                            <DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" />
                                        </Form.Item>
                                    </div>
                                    <div className="misa-w-120">
                                        <div className="misa-field-label">Loại tiền</div>
                                        <Form.Item name="currency" noStyle initialValue="VND">
                                            <Input className="misa-input" disabled />
                                        </Form.Item>
                                    </div>
                                </div>
                                <div className="misa-mt-8">
                                    <div className="misa-field-label">Tài khoản tiền mặt</div>
                                    <AccountSelect
                                        accounts={cashAccountsQuery.data ?? []}
                                        value={cashAccountCode}
                                        onChange={(value) => setCashAccountCode(value || undefined)}
                                        allowClear
                                        disabled={cashAccountsQuery.isLoading}
                                        placeholder="Chọn từ danh mục máy chủ"
                                    />
                                </div>
                                <div className="misa-mt-8 misa-fs-12">
                                    <span className="misa-color-muted misa-fw-500">Tham chiếu</span>
                                    <button type="button" className="misa-btn-link-action misa-ml-8 misa-fw-600">...</button>
                                </div>
                            </div>

                            {/* Right (35%) */}
                            <div className="misa-w-300 misa-border-l misa-pl-24 misa-flex-col-gap-8">
                                <div className="misa-flex-between">
                                    <span className="misa-fs-12 misa-fw-500 misa-color-label misa-w-80">Số</span>
                                    <Form.Item name="audit_number" noStyle>
                                        <Input className="misa-input misa-w-180 misa-fw-600" />
                                    </Form.Item>
                                </div>
                                <div className="misa-flex-between">
                                    <span className="misa-fs-12 misa-fw-500 misa-color-label misa-w-80">Ngày</span>
                                    <Form.Item name="doc_date" noStyle>
                                        <DatePicker className="misa-input misa-w-180" format="DD/MM/YYYY" />
                                    </Form.Item>
                                </div>
                                <div className="misa-flex-between">
                                    <span className="misa-fs-12 misa-fw-500 misa-color-label misa-w-80">Giờ</span>
                                    <Form.Item name="doc_time" noStyle>
                                        <TimePicker className="misa-input misa-w-180" format="HH:mm:ss" />
                                    </Form.Item>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Thành viên tham gia Collapse */}
                    <Collapse 
                        ghost 
                        size="small" 
                        className="misa-audit-collapse"
                        items={[{
                            key: 'audit-members',
                            label: <span className="misa-audit-collapse-label">Thành viên tham gia</span>,
                            children: (
                                <Table
                                    className="misa-voucher-table misa-audit-members-table"
                                    size="small"
                                    pagination={false}
                                    rowKey="key"
                                    dataSource={INTERNAL_AUDIT_MEMBER_ROLES}
                                    columns={[
                                        { title: '#', width: 42, render: (_: unknown, __: unknown, index: number) => index + 1 },
                                        { title: 'Họ và tên', render: () => <Input className="misa-input" placeholder="Họ và tên" /> },
                                        { title: 'Chức danh', dataIndex: 'role', width: 180 },
                                        { title: 'Đại diện', width: 100, render: () => <Checkbox /> },
                                    ]}
                                />
                            )
                        }]}
                    />

                    {/* Kết quả kiểm kê thực tế */}
                    <div className="misa-audit-card-section">
                        <div className="misa-detail-header-text misa-mb-8">
                            Kết quả kiểm kê thực tế
                        </div>
                        
                        <div className="misa-table-container">
                            <table className="misa-voucher-table misa-min-w-1000">
                                <thead>
                                    <tr>
                                        <th className="misa-w-40 misa-text-center">#</th>
                                        <th className="misa-w-150">Mệnh giá</th>
                                        <th className="misa-w-120 misa-text-right">Số lượng</th>
                                        <th className="misa-w-160 misa-text-right">Số tiền</th>
                                        <th className="misa-min-w-200">Diễn giải</th>
                                        <th className="misa-w-40 misa-text-center"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {denominations.map((row, index) => (
                                        <tr key={row.key}>
                                            <td className="misa-text-center misa-color-muted misa-fw-600">{index + 1}</td>
                                            <td className="misa-fw-600">{new Intl.NumberFormat('vi-VN').format(row.value)}</td>
                                            <td className="misa-text-right">
                                                <InputNumber 
                                                    min={0}
                                                    value={row.quantity}
                                                    onChange={(val) => handleQuantityChange(row.key, val)}
                                                    variant="borderless"
                                                    className="misa-table-input misa-w-full misa-text-right misa-fw-600"
                                                />
                                            </td>
                                            <td className="misa-text-right misa-fw-700 misa-color-darker">
                                                {new Intl.NumberFormat('vi-VN').format(row.amount)}
                                            </td>
                                            <td>
                                                <input className="misa-table-input" defaultValue={row.description} />
                                            </td>
                                            <td className="misa-text-center">
                                                <button 
                                                    type="button" 
                                                    className="misa-btn-icon-del"
                                                    onClick={() => setDenominations(prev => prev.filter(r => r.key !== row.key))}
                                                >
                                                    <DeleteOutlined className="misa-fs-13" />
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                    <tr className="summary-row">
                                        <td className="misa-text-center"></td>
                                        <td>Tổng cộng</td>
                                        <td className="misa-text-right">
                                            {(Array.isArray(denominations) ? denominations : []).reduce((sum, r) => sum + (Number(r?.quantity) || 0), 0)}
                                        </td>
                                        <td className="misa-text-right misa-fw-900 misa-color-darker">
                                            {new Intl.NumberFormat('vi-VN').format(actualTotal)}
                                        </td>
                                        <td></td>
                                        <td></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        {/* Action buttons under table */}
                        <div className="misa-flex misa-gap-8 misa-mt-8">
                            <button 
                                type="button"
                                className="misa-btn-tool"
                                onClick={() => {
                                    const newKey = String(Date.now());
                                    setDenominations(prev => [...prev, { key: newKey, value: 0, quantity: 0, amount: 0, description: 'Mệnh giá khác' }]);
                                }}
                            >
                                <PlusOutlined className="misa-color-primary" />
                                <span>Thêm dòng</span>
                            </button>
                            <button 
                                type="button"
                                className="misa-btn-tool misa-btn-tool-danger"
                                onClick={() => setDenominations([])}
                            >
                                <DeleteOutlined />
                                <span>Xóa hết dòng</span>
                            </button>
                        </div>
                    </div>

                    {/* Đối chiếu & Chênh lệch Box */}
                    <div className="misa-audit-diff-box">
                        <div className="misa-fs-12 misa-color-label misa-mb-6">
                            <strong>I. Số dư theo sổ kế toán tiền mặt:</strong> <span className="misa-fw-600">{bookBalance === null ? 'Chưa khả dụng' : `${new Intl.NumberFormat('vi-VN').format(bookBalance)} ₫`}</span>
                        </div>
                        <div className="misa-flex-between misa-fs-12 misa-color-label misa-mb-6">
                            <div>
                                <strong>II. Số dư kiểm kê thực tế:</strong> <span className="misa-fw-700 misa-color-darker">{new Intl.NumberFormat('vi-VN').format(actualTotal)} ₫</span>
                            </div>
                            <Button size="small" className="misa-fs-11 misa-h-24 misa-border-radius-4">Đối chiếu</Button>
                        </div>
                        <div className="misa-fs-12 misa-color-label">
                            <strong>III. Chênh lệch (I - II) :</strong> <span className="misa-fw-700 misa-color-disabled">{bookBalance === null ? 'Chưa thể tính' : `${new Intl.NumberFormat('vi-VN').format(difference ?? 0)} ₫`}</span>
                        </div>
                    </div>

                    {/* Lý do & Kết luận */}
                    <div className="misa-audit-card-section">
                        <div className="misa-mb-8">
                            <div className="misa-field-label">Lý do (Thừa/Thiếu)</div>
                            <Form.Item name="reason_diff" noStyle>
                                <Input className="misa-input" placeholder="Lý do chênh lệch thừa/thiếu quỹ..." />
                            </Form.Item>
                        </div>
                        <div className="misa-mb-8">
                            <div className="misa-field-label">Kết luận</div>
                            <Form.Item name="conclusion" noStyle>
                                <Input.TextArea rows={2} className="misa-input misa-w-full" placeholder="Ý kiến chỉ đạo, phương án xử lý..." />
                            </Form.Item>
                        </div>
                        <Checkbox disabled>Đã xử lý chênh lệch</Checkbox>
                    </div>

                    {/* Đính kèm box */}
                    <div className="misa-upload-box misa-mb-12">
                        <div className="misa-flex-center misa-gap-8 misa-fs-12 misa-color-label misa-mb-2">
                            <PaperClipOutlined className="misa-color-primary misa-fs-14" />
                            <span className="misa-fw-600 misa-color-dark">Đính kèm</span>
                            <span className="misa-color-disabled">Dung lượng tối đa 5MB</span>
                        </div>
                        <div className="misa-flex-center misa-gap-6 misa-fs-12 misa-color-muted">
                            <InboxOutlined className="misa-color-blue misa-fs-16" />
                            <span><span className="misa-color-blue misa-fw-600">Chọn tệp</span> hoặc kéo và thả tệp vào đây</span>
                        </div>
                    </div>

                </Form>
            </Modal>
        </PageShell>
    );
});

export default CashAudit;
