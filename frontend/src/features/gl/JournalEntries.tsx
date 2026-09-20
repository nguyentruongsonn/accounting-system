import React, { useState } from 'react';
import { Table, Button, Form, Input, DatePicker, InputNumber, Select, Card } from 'antd';
import type { MenuProps } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import {
    PlusOutlined,
    EditOutlined,
    DeleteOutlined,
    MinusCircleOutlined,
    CheckCircleOutlined,
    CloseCircleOutlined,
    SearchOutlined
} from '@ant-design/icons';
import { formatDate } from '../../utils/dateUtils';
import { VoucherStatusBadge } from '../../components/common/VoucherStatusBadge';
import { VoucherActionCell } from '../../components/common/VoucherActionCell';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
    optimisticTogglePostStatus,
    instantRemoveVoucher,
    rollbackVoucherCache,
    instantUpsertVoucher,
} from '../../lib/voucherCacheManager';
import { notifyDataChanged } from '../../lib/queryClient';
import api from '../../api/axios';
import AccountSelect from '../../components/misa/AccountSelect';
import type { AccountItem } from '../../components/misa/AccountSelect';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import ModalFrame from '../../components/layout/ModalFrame';
import { toJournalEntryFormLines } from './journalEntryFormMapper';
import { runManualDataLoad } from '../../components/feedback/runManualDataLoad';
import dayjs from 'dayjs';
import {
    addDecimalMoney,
    formatDecimalMoney,
    formatDecimalMoneyInput,
    normalizeDecimalMoney,
    parseDecimalMoneyInput,
} from '../../utils/decimalMoney';

function preferredMoney(...values: Array<string | number | null | undefined>): string {
    const value = values.find((candidate) => candidate !== null && candidate !== undefined && candidate !== '');
    return normalizeDecimalMoney(value ?? '0');
}

type JournalEntryListResponse = { data: unknown[]; per_page?: number; total?: number; [key: string]: unknown };

function parseJournalEntryList(value: unknown): JournalEntryListResponse {
    if (Array.isArray(value)) return { data: value };
    if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
        return value as JournalEntryListResponse;
    }
    throw new Error('Invalid journal entry list response.');
}

function parseAccountCatalogue(value: unknown): AccountItem[] {
    const rows = Array.isArray(value)
        ? value
        : value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)
            ? (value as { data: unknown[] }).data
            : null;
    if (!rows) throw new Error('Invalid account catalogue response.');
    return rows.filter((row): row is AccountItem => Boolean(row)
        && typeof row === 'object'
        && typeof (row as { code?: unknown }).code === 'string'
        && typeof (row as { name?: unknown }).name === 'string'
        && (row as { is_active?: unknown }).is_active !== false
        && (row as { is_parent?: unknown }).is_parent !== true);
}

const JournalEntries: React.FC = () => {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [invalidPersistedLineReasons, setInvalidPersistedLineReasons] = useState<string[]>([]);
    const [form] = Form.useForm();
    const queryClient = useQueryClient();
    const [page, setPage] = useState(1);
    const [searchText, setSearchText] = useState('');
    const [statusFilter, setStatusFilter] = useState<'all' | 'posted' | 'draft'>('all');

    const draftLines = Form.useWatch('lines', form) || [];
    const draftTotal = draftLines.reduce(
        (total: string, line: any) => addDecimalMoney(total, preferredMoney(
            line?.amount_decimal,
            line?.amount,
            line?.debit_amount_decimal,
            line?.debit_amount,
            line?.credit_amount_decimal,
            line?.credit_amount,
        )),
        '0.00',
    );

    const { data, isLoading, isError: isJournalEntriesError, refetch: refetchJournalEntries } = useQuery({
        queryKey: ['journal-entries', page],
        queryFn: async () => {
            const { data } = await api.get(`/gl/journal-entries?page=${page}`);
            return parseJournalEntryList(data);
        },
    });

    const filteredEntries = React.useMemo(() => {
        const rows = (data?.data || []) as any[];
        return rows.filter((item: any) => {
            if (searchText.trim()) {
                const q = searchText.toLowerCase();
                const matchVoucher = (item.voucher_number || '').toLowerCase().includes(q);
                const matchReason = (item.reason || '').toLowerCase().includes(q);
                if (!matchVoucher && !matchReason) return false;
            }
            const isPosted = item.status === 'posted';
            if (statusFilter === 'posted' && !isPosted) return false;
            if (statusFilter === 'draft' && isPosted) return false;
            return true;
        });
    }, [data?.data, searchText, statusFilter]);
    const { data: accounts = [], isLoading: accountsLoading, isError: accountsError } = useQuery<AccountItem[]>({
        queryKey: ['journal-entry-account-catalogue'],
        queryFn: async () => parseAccountCatalogue((await api.get('/master/accounts')).data),
    });

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            const normalizedLines = (values.lines || []).map((line: any) => {
                const amount = preferredMoney(
                    line.amount_decimal,
                    line.amount,
                    line.debit_amount_decimal,
                    line.debit_amount,
                    line.credit_amount_decimal,
                    line.credit_amount,
                );

                return {
                    ...line,
                    amount,
                    debit_amount: line.debit_amount !== undefined && line.debit_amount !== null && line.debit_amount !== ''
                        ? preferredMoney(line.debit_amount_decimal, line.debit_amount)
                        : amount,
                    credit_amount: line.credit_amount !== undefined && line.credit_amount !== null && line.credit_amount !== ''
                        ? preferredMoney(line.credit_amount_decimal, line.credit_amount)
                        : amount,
                };
            });
            const payload = {
                ...values,
                voucher_date: values.voucher_date.format('YYYY-MM-DD'),
                posting_date: values.posting_date.format('YYYY-MM-DD'),
                lines: normalizedLines,
                total_amount: normalizedLines.reduce((sum: string, line: any) => addDecimalMoney(sum, line.amount), '0.00'),
            };

            if (editingId) {
                return api.put(`/gl/journal-entries/${editingId}`, payload);
            }
            return api.post('/gl/journal-entries', payload);
        },
        onSuccess: (response: any) => {
            const persistedEntry = response?.data?.data ?? response?.data;
            if (!persistedEntry || !Number.isInteger(Number(persistedEntry.id))) {
                message.error('Máy chủ không trả về chứng từ kế toán đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }
            instantUpsertVoucher(queryClient, ['journal-entries', page], persistedEntry);
            message.success('Lưu chứng từ kế toán thành công!');
            setIsModalOpen(false);
            notifyDataChanged('gl');
        },
        onError: () => {
            message.error('Lỗi khi lưu chứng từ, vui lòng kiểm tra lại!');
        }
    });

    const handleDelete = async (id: number) => {
        const context = instantRemoveVoucher(queryClient, ['journal-entries', page], id);
        try {
            const response = await api.delete(`/gl/journal-entries/${id}`);
            if (response?.status !== 204 && response?.status !== 200) {
                throw new Error('Máy chủ không xác nhận đã xóa chứng từ kế toán.');
            }
            message.success('Xóa chứng từ kế toán thành công!');
            notifyDataChanged('gl');
        } catch (error: any) {
            rollbackVoucherCache(queryClient, ['journal-entries', page], context);
            message.error(error?.response?.data?.message || 'Không thể xóa chứng từ này!');
        }
    };

    const handlePost = async (id: number) => {
        const context = optimisticTogglePostStatus(queryClient, ['journal-entries', page], id, true, { status: 'posted' });
        try {
            const response = await api.post(`/gl/journal-entries/${id}/post`);
            const persistedEntry = response?.data?.data ?? response?.data;
            if (!persistedEntry || !Number.isInteger(Number(persistedEntry.id))) {
                throw new Error('Máy chủ không trả về chứng từ kế toán đã ghi sổ.');
            }
            message.success('Ghi sổ thành công!');
            notifyDataChanged('gl');
        } catch (error: any) {
            rollbackVoucherCache(queryClient, ['journal-entries', page], context);
            message.error(error?.response?.data?.message || 'Ghi sổ thất bại!');
        }
    };

    const handleVoid = async (id: number) => {
        const context = optimisticTogglePostStatus(queryClient, ['journal-entries', page], id, false, { status: 'draft' });
        try {
            const response = await api.post(`/gl/journal-entries/${id}/void`);
            const persistedEntry = response?.data?.data ?? response?.data;
            if (!persistedEntry || !Number.isInteger(Number(persistedEntry.id))) {
                throw new Error('Máy chủ không trả về chứng từ kế toán đã bỏ ghi.');
            }
            message.success('Bỏ ghi thành công!');
            notifyDataChanged('gl');
        } catch (error: any) {
            rollbackVoucherCache(queryClient, ['journal-entries', page], context);
            message.error(error?.response?.data?.message || 'Bỏ ghi thất bại!');
        }
    };

    const columns = [
        { title: 'Số chứng từ', dataIndex: 'voucher_number', key: 'voucher_number', width: 140 },
        { title: 'Ngày CT', dataIndex: 'voucher_date', key: 'voucher_date', width: 110, render: (d: string) => formatDate(d) || '—' },
        { title: 'Diễn giải', dataIndex: 'reason', key: 'reason' },
        {
            title: 'Tổng tiền',
            dataIndex: 'total_amount',
            key: 'total_amount',
            width: 150,
            align: 'right' as const,
            render: (_: unknown, record: any) => formatDecimalMoney(record.total_amount_decimal ?? record.total_amount, { currency: true })
        },
        {
            title: 'Trạng thái',
            key: 'status',
            width: 120,
            align: 'center' as const,
            render: (_: any, record: any) => <VoucherStatusBadge status={record} />
        },
        {
            title: 'Chức năng',
            key: 'action',
            width: 140,
            align: 'center' as const,
            render: (_: any, record: any) => {
                const isPosted = record.status === 'posted';
                const menuItems: MenuProps['items'] = [
                    isPosted ? {
                        key: 'unpost',
                        label: 'Bỏ ghi sổ',
                        icon: <CloseCircleOutlined className="misa-icon-warning" />,
                        onClick: () => handleVoid(record.id)
                    } : {
                        key: 'post',
                        label: 'Ghi sổ',
                        icon: <CheckCircleOutlined className="misa-icon-success" />,
                        onClick: () => handlePost(record.id)
                    },
                    {
                        key: 'edit',
                        label: isPosted ? 'Sửa (Bỏ ghi)' : 'Sửa',
                        icon: <EditOutlined className="misa-icon-primary" />,
                        onClick: () => {
                            setEditingId(record.id);
                            const lines = toJournalEntryFormLines(record.lines || []);
                            setInvalidPersistedLineReasons(lines.flatMap((line) => line.invalid_reason ? [line.invalid_reason] : []));
                            form.setFieldsValue({
                                ...record,
                                voucher_date: dayjs(record.voucher_date),
                                posting_date: dayjs(record.posting_date),
                                lines,
                            });
                            setIsModalOpen(true);
                        }
                    },
                    { type: 'divider' },
                    {
                        key: 'delete',
                        label: 'Xóa',
                        danger: true,
                        disabled: isPosted,
                        icon: <DeleteOutlined />,
                        onClick: () => handleDelete(record.id)
                    }
                ];

                return (
                    <VoucherActionCell
                        primaryActionLabel={isPosted ? 'Xem' : 'Sửa'}
                        primaryIcon={!isPosted ? <EditOutlined /> : undefined}
                        onPrimaryAction={() => {
                            setEditingId(record.id);
                            const lines = toJournalEntryFormLines(record.lines || []);
                            setInvalidPersistedLineReasons(lines.flatMap((line) => line.invalid_reason ? [line.invalid_reason] : []));
                            form.setFieldsValue({
                                ...record,
                                voucher_date: dayjs(record.voucher_date),
                                posting_date: dayjs(record.posting_date),
                                lines,
                            });
                            setIsModalOpen(true);
                        }}
                        menuItems={menuItems}
                    />
                );
            },
        },
    ];

    return (
        <PageShell
            title={<PageHeader eyebrow="TỔNG HỢP" title="Chứng từ kế toán tổng hợp" description="Nghiệp vụ khác, kết chuyển cuối kỳ và đánh giá chênh lệch tỷ giá." />}
            toolbar={
                <PageToolbar
                    filters={
                        <div className="flex items-center gap-2">
                            <Input
                                placeholder="Tìm số chứng từ, diễn giải..."
                                prefix={<SearchOutlined className="text-slate-400" />}
                                value={searchText}
                                onChange={(e) => setSearchText(e.target.value)}
                                style={{ width: 240 }}
                                size="small"
                                allowClear
                            />
                            <Select
                                value={statusFilter}
                                onChange={setStatusFilter}
                                size="small"
                                style={{ width: 140 }}
                                options={[
                                    { label: 'Tất cả trạng thái', value: 'all' },
                                    { label: 'Đã ghi sổ', value: 'posted' },
                                    { label: 'Chưa ghi sổ', value: 'draft' },
                                ]}
                            />
                        </div>
                    }
                    actions={(
                        <Button
                            type="primary"
                            icon={<PlusOutlined />}
                            className="misa-btn-primary"
                            onClick={() => {
                                setEditingId(null);
                                setInvalidPersistedLineReasons([]);
                                form.resetFields();
                                form.setFieldsValue({
                                    voucher_date: dayjs(),
                                    posting_date: dayjs(),
                                    status: 'draft',
                                    lines: [
                                        { debit_account: '', credit_account: '', amount: '0.00' },
                                        { debit_account: '', credit_account: '', amount: '0.00' }
                                    ]
                                });
                                setIsModalOpen(true);
                            }}
                        >
                            Thêm Chứng từ
                        </Button>
                    )}
                />
            }
        >

            <DataTableSurface className="journal-entries-table-surface">
                <div className="flex-1 flex flex-col p-3 overflow-hidden">
                    {isJournalEntriesError ? (
                        <div role="alert" className="misa-p-12 misa-mb-16" style={{ background: '#FEF2F2', border: '1px solid #FECACA', borderRadius: 8, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                            <div>
                                <div style={{ fontWeight: 600, color: '#991B1B', fontSize: 13 }}>Không thể tải danh sách chứng từ kế toán</div>
                                <div style={{ color: '#B91C1C', fontSize: 12 }}>Dữ liệu chưa được xác minh từ máy chủ; không hiển thị danh sách rỗng thay thế.</div>
                            </div>
                             <Button onClick={() => void runManualDataLoad(() => refetchJournalEntries(), { success: 'Tải lại danh sách chứng từ kế toán thành công.', failure: 'Không thể tải lại danh sách chứng từ kế toán.' })}>Thử lại danh sách chứng từ kế toán</Button>
                        </div>
                    ) : (
                        <div className="flex-1 bg-white rounded-md border border-slate-200 overflow-hidden flex flex-col">
                            <div className="px-3 py-2 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-2">
                                <span className="text-xs font-bold text-slate-700 uppercase">
                                    Danh sách chứng từ kế toán
                                </span>
                                <div className="flex items-center gap-2">
                                    <Input
                                        placeholder="Tìm số chứng từ, diễn giải..."
                                        prefix={<SearchOutlined className="text-slate-400" />}
                                        value={searchText}
                                        onChange={(e) => setSearchText(e.target.value)}
                                        style={{ width: 220 }}
                                        size="small"
                                        allowClear
                                    />
                                    <Select
                                        value={statusFilter}
                                        onChange={setStatusFilter}
                                        size="small"
                                        style={{ width: 130 }}
                                        options={[
                                            { label: 'Tất cả', value: 'all' },
                                            { label: 'Đã ghi sổ', value: 'posted' },
                                            { label: 'Chưa ghi sổ', value: 'draft' },
                                        ]}
                                    />
                                </div>
                            </div>
                            <div className="flex-1 overflow-auto">
                                <Table
                                    columns={columns}
                                    dataSource={filteredEntries}
                                    rowKey="id"
                                    loading={isLoading}
                                    size="small"
                                    pagination={{
                                        current: page,
                                        pageSize: data?.per_page || 20,
                                        total: data?.total || 0,
                                        onChange: (p) => setPage(p)
                                    }}
                                />
                            </div>
                        </div>
                    )}
                </div>
            </DataTableSurface>

            <Modal
                title={editingId ? "Sửa Chứng từ kế toán" : "Lập Chứng từ kế toán"}
                open={isModalOpen}
                onOk={() => form.submit()}
                onCancel={() => {
                    setIsModalOpen(false);
                    setInvalidPersistedLineReasons([]);
                }}
                okText="OK"
                cancelText="Hủy"
                confirmLoading={mutation.isPending}
                okButtonProps={{ disabled: accountsLoading || accountsError || accounts.length === 0 || invalidPersistedLineReasons.length > 0 }}
                width={900}
            >
                <ModalFrame>
                {(accountsError || accounts.length === 0) && (
                    <div className="mb-4 misa-p-12" style={{ background: '#FFFBEB', border: '1px solid #FDE68A', borderRadius: 8 }}>
                        <div style={{ fontWeight: 600, color: '#92400E', fontSize: 13 }}>Chưa có hệ thống tài khoản từ máy chủ</div>
                        <div style={{ color: '#B45309', fontSize: 12 }}>Không thể lập chứng từ khi catalogue tài khoản bị thiếu hoặc không hợp lệ.</div>
                    </div>
                )}
                {invalidPersistedLineReasons.length > 0 && (
                    <div className="mb-4 misa-p-12" style={{ background: '#FEF2F2', border: '1px solid #FECACA', borderRadius: 8 }}>
                        <div style={{ fontWeight: 600, color: '#991B1B', fontSize: 13 }}>Dữ liệu bút toán đã lưu không hợp lệ. Không thể lưu lại chứng từ này.</div>
                        <div style={{ color: '#B91C1C', fontSize: 12 }}>{Array.from(new Set(invalidPersistedLineReasons)).join(' ')}</div>
                    </div>
                )}
                <Form
                    form={form}
                    layout="vertical"
                    onFinish={(values) => {
                        if (invalidPersistedLineReasons.length > 0) {
                            message.error('Không thể lưu lại chứng từ có dòng hạch toán đã lưu không hợp lệ.');
                            return;
                        }
                        mutation.mutate(values);
                    }}
                    className="mt-4"
                >
                    {/* Master Data */}
                    <Card size="small" title="Thông tin chung" className="mb-4 bg-gray-50">
                        <div className="grid grid-cols-3 gap-4">
                            <Form.Item name="voucher_number" label="Số chứng từ" rules={[{ required: true }]}>
                                <Input />
                            </Form.Item>
                            <Form.Item name="voucher_date" label="Ngày chứng từ" rules={[{ required: true }]}>
                                <DatePicker className="w-full" format="DD/MM/YYYY" />
                            </Form.Item>
                            <Form.Item name="posting_date" label="Ngày hạch toán" rules={[{ required: true }]}>
                                <DatePicker className="w-full" format="DD/MM/YYYY" />
                            </Form.Item>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <Form.Item name="reason" label="Diễn giải chung">
                                <Input />
                            </Form.Item>
                            <Form.Item name="status" label="Trạng thái" rules={[{ required: true }]}>
                                <Select disabled options={[{ value: 'draft', label: 'Bản nháp — ghi sổ ở bước riêng' }]} />
                            </Form.Item>
                        </div>
                    </Card>

                    {/* Detail Lines */}
                    <Card size="small" title="Hạch toán chi tiết (Nợ / Có)">
                        <Form.List name="lines">
                            {(fields, { add, remove }) => (
                                <>
                                    {fields.map(({ key, name, ...restField }) => (
                                        <div key={key} className="flex gap-2 items-start mb-2">
                                            <Form.Item
                                                {...restField}
                                                name={[name, 'debit_account']}
                                                rules={[{ required: true, message: 'Nhập TK Nợ' }]}
                                                className="w-1/6 mb-0"
                                            >
                                                <AccountSelect accounts={accounts} disabled={accountsLoading || accountsError} allowClear placeholder="Chọn TK Nợ từ catalogue máy chủ" />
                                            </Form.Item>
                                            <Form.Item
                                                {...restField}
                                                name={[name, 'credit_account']}
                                                rules={[{ required: true, message: 'Nhập TK Có' }]}
                                                className="w-1/6 mb-0"
                                            >
                                                <AccountSelect accounts={accounts} disabled={accountsLoading || accountsError} allowClear placeholder="Chọn TK Có từ catalogue máy chủ" />
                                            </Form.Item>
                                            <Form.Item
                                                {...restField}
                                                name={[name, 'amount']}
                                                rules={[{ required: true, message: 'Nhập số tiền' }]}
                                                className="w-1/4 mb-0"
                                            >
                                                <InputNumber
                                                    stringMode
                                                    className="w-full"
                                                    formatter={formatDecimalMoneyInput}
                                                    parser={(value) => parseDecimalMoneyInput(value) as any}
                                                    placeholder="Số tiền"
                                                />
                                            </Form.Item>
                                            <Form.Item
                                                {...restField}
                                                name={[name, 'description']}
                                                className="flex-1 mb-0"
                                            >
                                                <Input placeholder="Diễn giải chi tiết" />
                                            </Form.Item>
                                            <Button type="text" danger icon={<MinusCircleOutlined />} onClick={() => remove(name)} />
                                        </div>
                                    ))}
                                    <Form.Item className="mt-4 mb-0">
                                        <Button type="dashed" onClick={() => add()} block icon={<PlusOutlined />}>
                                            Thêm dòng hạch toán
                                        </Button>
                                    </Form.Item>
                                    <div className="mt-3 text-right text-sm text-gray-600">
                                        Tổng tiền: <strong>{formatDecimalMoney(draftTotal, { currency: true })}</strong>
                                    </div>
                                </>
                            )}
                        </Form.List>
                    </Card>
                </Form>
                </ModalFrame>
            </Modal>
        </PageShell>
    );
};

export default JournalEntries;
