import React, { useState } from 'react';
import { Alert, Table, Button, Space, Tag, Form, Input, DatePicker, InputNumber, Select, Card } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import { PlusOutlined, EditOutlined, DeleteOutlined, MinusCircleOutlined } from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import AccountSelect from '../../components/misa/AccountSelect';
import type { AccountItem } from '../../components/misa/AccountSelect';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import ModalFrame from '../../components/layout/ModalFrame';
import { toJournalEntryFormLines } from './journalEntryFormMapper';
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
    const queryClient = useQueryClient();
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [invalidPersistedLineReasons, setInvalidPersistedLineReasons] = useState<string[]>([]);
    const [form] = Form.useForm();
    const [page, setPage] = useState(1);
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
            message.success('Lưu chứng từ kế toán thành công!');
            setIsModalOpen(false);
            queryClient.invalidateQueries({ queryKey: ['journal-entries'] });
        },
        onError: () => {
            message.error('Lỗi khi lưu chứng từ, vui lòng kiểm tra lại!');
        }
    });

    const handleDelete = async (id: number) => {
        try {
            const response = await api.delete(`/gl/journal-entries/${id}`);
            if (response?.status !== 204) {
                throw new Error('Máy chủ không xác nhận đã xóa chứng từ kế toán.');
            }
            message.success('Xóa chứng từ kế toán thành công!');
            queryClient.invalidateQueries({ queryKey: ['journal-entries'] });
        } catch (error) {
            message.error('Không thể xóa chứng từ này!');
        }
    };

    const handlePost = async (id: number) => {
        try {
            const response = await api.post(`/gl/journal-entries/${id}/post`);
            const persistedEntry = response?.data?.data ?? response?.data;
            if (!persistedEntry || !Number.isInteger(Number(persistedEntry.id))) {
                throw new Error('Máy chủ không trả về chứng từ kế toán đã ghi sổ.');
            }
            message.success('Ghi sổ thành công!');
            queryClient.invalidateQueries({ queryKey: ['journal-entries'] });
        } catch (error) {
            message.error('Ghi sổ thất bại!');
        }
    };

    const handleVoid = async (id: number) => {
        try {
            const response = await api.post(`/gl/journal-entries/${id}/void`);
            const persistedEntry = response?.data?.data ?? response?.data;
            if (!persistedEntry || !Number.isInteger(Number(persistedEntry.id))) {
                throw new Error('Máy chủ không trả về chứng từ kế toán đã bỏ ghi.');
            }
            message.success('Bỏ ghi thành công!');
            queryClient.invalidateQueries({ queryKey: ['journal-entries'] });
        } catch (error) {
            message.error('Bỏ ghi thất bại!');
        }
    };

    const columns = [
        { title: 'Số chứng từ', dataIndex: 'voucher_number', key: 'voucher_number', width: '12%' },
        { title: 'Ngày CT', dataIndex: 'voucher_date', key: 'voucher_date', width: '10%' },
        { title: 'Diễn giải', dataIndex: 'reason', key: 'reason' },
        { 
            title: 'Tổng tiền', 
            dataIndex: 'total_amount', 
            key: 'total_amount', 
            width: '15%',
            align: 'right' as const,
            render: (_: unknown, record: any) => formatDecimalMoney(record.total_amount_decimal ?? record.total_amount, { currency: true })
        },
        {
            title: 'Trạng thái',
            dataIndex: 'status',
            key: 'status',
            width: '10%',
            render: (status: string) => (
                <Tag color={status === 'posted' ? 'green' : 'orange'}>
                    {status === 'posted' ? 'Đã ghi sổ' : 'Bản nháp'}
                </Tag>
            )
        },
        {
            title: 'Hành động',
            key: 'action',
            width: '20%',
            render: (_: any, record: any) => (
                <Space size="middle">
                    {record.status === 'draft' && (
                        <Button type="link" size="small" onClick={() => handlePost(record.id)}>
                            Ghi sổ
                        </Button>
                    )}
                    {record.status === 'posted' && (
                        <Button type="link" danger size="small" onClick={() => handleVoid(record.id)}>
                            Bỏ ghi
                        </Button>
                    )}
                    <Button 
                        type="text" 
                        icon={<EditOutlined />} 
                        className="text-blue-600"
                        disabled={record.status === 'posted'}
                        onClick={() => {
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
                    />
                    <Button type="text" danger icon={<DeleteOutlined />} disabled={record.status === 'posted'} onClick={() => handleDelete(record.id)} />
                </Space>
            ),
        },
    ];

    return (
        <PageShell
            title={<PageHeader eyebrow="TỔNG HỢP" title="Chứng từ kế toán tổng hợp" description="Nghiệp vụ khác, kết chuyển cuối kỳ và đánh giá chênh lệch tỷ giá." />}
            toolbar={<PageToolbar actions={(
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
            )} />}
        >

            <DataTableSurface className="journal-entries-table-surface">
            {isJournalEntriesError ? <Alert
                type="error"
                showIcon
                title="Không thể tải danh sách chứng từ kế toán"
                description="Dữ liệu chưa được xác minh từ máy chủ; không hiển thị danh sách rỗng thay thế."
                action={<Button onClick={() => void refetchJournalEntries()}>Thử lại danh sách chứng từ kế toán</Button>}
            /> : <Table 
                columns={columns} 
                dataSource={data?.data || []} 
                rowKey="id" 
                loading={isLoading}
                pagination={{
                    current: page,
                    pageSize: data?.per_page || 20,
                    total: data?.total || 0,
                    onChange: (p) => setPage(p)
                }}
                className="border rounded-lg"
            />}
            </DataTableSurface>

            <Modal
                title={editingId ? "Sửa Chứng từ kế toán" : "Lập Chứng từ kế toán"}
                open={isModalOpen}
                onOk={() => form.submit()}
                onCancel={() => {
                    setIsModalOpen(false);
                    setInvalidPersistedLineReasons([]);
                }}
                confirmLoading={mutation.isPending}
                okButtonProps={{ disabled: accountsLoading || accountsError || accounts.length === 0 || invalidPersistedLineReasons.length > 0 }}
                width={900}
            >
                <ModalFrame>
                {(accountsError || accounts.length === 0) && (
                    <div className="mb-4"><Alert type="warning" showIcon message="Chưa có hệ thống tài khoản từ máy chủ" description="Không thể lập chứng từ khi catalogue tài khoản bị thiếu hoặc không hợp lệ." /></div>
                )}
                {invalidPersistedLineReasons.length > 0 && (
                    <div className="mb-4"><Alert type="error" showIcon title="Dữ liệu bút toán đã lưu không hợp lệ. Không thể lưu lại chứng từ này." description={Array.from(new Set(invalidPersistedLineReasons)).join(' ')} /></div>
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
