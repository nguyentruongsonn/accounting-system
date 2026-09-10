import React, { useDeferredValue, useEffect, useMemo, useState } from 'react';
import { Alert, Button, Dropdown, Empty, Form, Input, Select, Switch, Table, Tag, Tooltip } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import type { TableColumnsType } from 'antd';
import Modal from '../../components/layout/AppModal';
import { CaretDownOutlined, CaretRightOutlined, CopyOutlined, DeleteOutlined, FileExcelOutlined, MinusSquareOutlined, PlusOutlined, PlusSquareOutlined, ReloadOutlined, SearchOutlined, SettingOutlined, StopOutlined, SwapOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as XLSX from 'xlsx';
import api from '../../api/axios';
import { useAuthStore } from '../../store/useAuthStore';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import MasterDataNavigation from './MasterDataNavigation';
import DataTableSurface from '../../components/layout/DataTableSurface';
import ModalFrame from '../../components/layout/ModalFrame';
import './ChartOfAccounts.css';

interface Account {
    id: number; code: string; name: string; description?: string | null;
    type: string; nature: string; level: number; parent_code: string | null;
    is_parent: boolean; is_active: boolean; children?: Account[];
}

type AccountRow = Account & { treeDepth: number; hasChildren: boolean };

const natureLabels: Record<string, string> = { debit: 'Dư Nợ', credit: 'Dư Có', amphibious: 'Lưỡng tính' };
const typeLabels: Record<string, string> = {
    asset: 'Tài sản', liability: 'Nợ phải trả', equity: 'Vốn chủ sở hữu', revenue: 'Doanh thu',
    expense: 'Chi phí', revenue_deduction: 'Giảm trừ doanh thu',
};

export function parseAccounts(value: unknown): Account[] {
    // The current API returns a bare array, while compatible Laravel resource
    // deployments may wrap the same collection in `{ data: [...] }`. Accept
    // both envelopes, but keep strict row validation so a malformed response
    // can never be rendered as an empty catalogue.
    const rows = Array.isArray(value)
        ? value
        : value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)
            ? (value as { data: unknown[] }).data
            : null;
    if (!rows) throw new Error('Invalid chart-of-accounts response.');
    rows.forEach((item, index) => {
        if (!item || typeof item !== 'object' || !Number.isInteger((item as { id?: unknown }).id)
            || typeof (item as { code?: unknown }).code !== 'string'
            || typeof (item as { name?: unknown }).name !== 'string'
            || typeof (item as { type?: unknown }).type !== 'string'
            || typeof (item as { nature?: unknown }).nature !== 'string'
            || !Number.isInteger((item as { level?: unknown }).level)
            || (typeof (item as { parent_code?: unknown }).parent_code !== 'string' && (item as { parent_code?: unknown }).parent_code !== null)
            || typeof (item as { is_parent?: unknown }).is_parent !== 'boolean'
            || typeof (item as { is_active?: unknown }).is_active !== 'boolean') {
            throw new Error(`Invalid chart-of-accounts row at index ${index}.`);
        }
    });
    return rows as Account[];
}

function assertPersistedAccount(value: unknown): void {
    if (!value || typeof value !== 'object') throw new Error('Account response is missing persisted evidence.');
    const body = value as Record<string, unknown>;
    const entity = body.data && typeof body.data === 'object' ? body.data : body;
    if (!entity || typeof entity !== 'object' || !Number.isInteger((entity as { id?: unknown }).id)) {
        throw new Error('Account response is missing a persisted id.');
    }
}

function buildAccountTree(source: Account[]): Account[] {
    const byCode = new Map(source.map((account) => [account.code, { ...account, children: [] as Account[] }]));
    const roots: Account[] = [];
    source.forEach((account) => {
        const node = byCode.get(account.code)!;
        const parent = account.parent_code ? byCode.get(account.parent_code) : undefined;
        if (parent) parent.children!.push(node); else roots.push(node);
    });
    const clean = (nodes: Account[]) => nodes.forEach((node) => {
        if (!node.children?.length) delete node.children; else clean(node.children);
    });
    clean(roots);
    return roots;
}

function flattenAccounts(nodes: Account[]): Account[] {
    return nodes.flatMap((node) => [node, ...(node.children ? flattenAccounts(node.children) : [])]);
}

function apiErrorMessage(error: unknown, fallback: string): string {
    const response = (error as { response?: { data?: { message?: unknown; errors?: Record<string, string[]> } } })?.response;
    const validation = response?.data?.errors ? Object.values(response.data.errors).flat()[0] : undefined;
    return typeof validation === 'string' ? validation : typeof response?.data?.message === 'string' ? response.data.message : fallback;
}

const ChartOfAccounts: React.FC = () => {
    const queryClient = useQueryClient();
    const permissions = useAuthStore((state) => state.user?.permissions);
    const canTransfer = permissions?.includes('master.accounts.transfer') ?? false;
    const canDelete = permissions?.includes('master.accounts.delete') ?? false;
    const [search, setSearch] = useState('');
    const deferredSearch = useDeferredValue(search.trim());
    const [includeInactive, setIncludeInactive] = useState(true);
    const [expandedRowKeys, setExpandedRowKeys] = useState<React.Key[]>([]);
    const [pageSize, setPageSize] = useState(20);
    const [currentPage, setCurrentPage] = useState(1);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingAccount, setEditingAccount] = useState<Account | null>(null);
    const [transferAccount, setTransferAccount] = useState<Account | null>(null);
    const [form] = Form.useForm();
    const [transferForm] = Form.useForm();
    const [transferPreview, setTransferPreview] = useState<{ sourceId: number; targetCode: string; count: number; token: string } | null>(null);
    const transferTarget = Form.useWatch('target_code', transferForm);
    const previewMatches = transferPreview?.sourceId === transferAccount?.id
        && transferPreview?.targetCode === transferTarget
        && Boolean(transferPreview?.token);

    const { data: accounts = [], isLoading, isError, refetch, isFetching } = useQuery<Account[]>({
        queryKey: ['accounts', deferredSearch, includeInactive],
        queryFn: async () => {
            const { data } = await api.get('/master/accounts', {
                params: { search: deferredSearch || undefined, include_inactive: includeInactive ? 1 : 0 },
            });
            return buildAccountTree(parseAccounts(data));
        },
    });

    const { data: catalogue = [] } = useQuery<Account[]>({
        queryKey: ['accounts', 'parent-options'],
        queryFn: async () => {
            const { data } = await api.get('/master/accounts', { params: { include_inactive: 1 } });
            return buildAccountTree(parseAccounts(data));
        },
    });

    const accountRows = useMemo(() => flattenAccounts(accounts), [accounts]);
    const catalogueRows = useMemo(() => flattenAccounts(catalogue), [catalogue]);
    const parentRowKeys = useMemo(() => accountRows.filter((item) => item.children?.length).map((item) => item.id), [accountRows]);
    const allExpanded = parentRowKeys.length > 0 && parentRowKeys.every((id) => expandedRowKeys.includes(id));
    useEffect(() => { if (deferredSearch) setExpandedRowKeys(parentRowKeys); }, [deferredSearch, parentRowKeys.join('|')]);
    useEffect(() => { setCurrentPage(1); }, [deferredSearch, includeInactive]);

    const visibleRows = useMemo<AccountRow[]>(() => {
        const rows: AccountRow[] = [];
        const visit = (nodes: Account[], depth: number) => nodes.forEach((node) => {
            rows.push({ ...node, treeDepth: depth, hasChildren: Boolean(node.children?.length) });
            if (node.children?.length && expandedRowKeys.includes(node.id)) visit(node.children, depth + 1);
        });
        visit(accounts, 0);
        return rows;
    }, [accounts, expandedRowKeys]);
    const paginatedRows = useMemo(
        () => visibleRows.slice((currentPage - 1) * pageSize, currentPage * pageSize),
        [currentPage, pageSize, visibleRows],
    );
    // The tree is flattened above so pagination and our expand state stay deterministic.
    // Do not pass nested `children` back to Ant Design: it would render a second,
    // implicit tree toggle beside the explicit MISA-style toggle below.
    const tableRows = useMemo<AccountRow[]>(
        () => paginatedRows.map(({ children: _children, ...row }) => row),
        [paginatedRows],
    );

    const excludedParentCodes = useMemo(() => {
        if (!editingAccount) return new Set<string>();
        const completeAccount = catalogueRows.find((item) => item.code === editingAccount.code) ?? editingAccount;
        return new Set([completeAccount.code, ...flattenAccounts(completeAccount.children ?? []).map((item) => item.code)]);
    }, [catalogueRows, editingAccount]);
    const parentAccountOptions = catalogueRows
        .filter((item) => item.is_active && !excludedParentCodes.has(item.code))
        .map((item) => ({ value: item.code, label: `${item.code} — ${item.name}` }));

    const openCreate = (parent?: Account) => {
        setEditingAccount(null); form.resetFields();
        form.setFieldsValue({ parent_code: parent?.code, type: parent?.type ?? 'asset', nature: parent?.nature ?? 'debit', is_active: true });
        setIsModalOpen(true);
    };
    const openEdit = (account: Account) => {
        setEditingAccount(account); form.resetFields(); form.setFieldsValue(account); setIsModalOpen(true);
    };

    const mutation = useMutation({
        mutationFn: async (values: Record<string, unknown>) => {
            const response = editingAccount ? await api.put(`/master/accounts/${editingAccount.id}`, values) : await api.post('/master/accounts', values);
            assertPersistedAccount(response.data);
            return response;
        },
        onSuccess: () => {
            message.success(editingAccount ? 'Đã cập nhật tài khoản.' : 'Đã thêm tài khoản.');
            setIsModalOpen(false); setEditingAccount(null); queryClient.invalidateQueries({ queryKey: ['accounts'] });
        },
        onError: (error) => message.error(apiErrorMessage(error, 'Không thể lưu tài khoản.')),
    });

    const statusMutation = useMutation({
        mutationFn: async ({ account, is_active }: { account: Account; is_active: boolean }) => {
            const response = await api.put(`/master/accounts/${account.id}`, { is_active });
            assertPersistedAccount(response.data);
            return response;
        },
        onSuccess: (_, variables) => {
            message.success(variables.is_active ? 'Đã kích hoạt tài khoản.' : 'Đã ngừng sử dụng tài khoản.');
            queryClient.invalidateQueries({ queryKey: ['accounts'] });
        },
        onError: (error) => message.error(apiErrorMessage(error, 'Không thể cập nhật trạng thái tài khoản.')),
    });

    const transferMutation = useMutation({
        mutationFn: async ({ account, target_code, preview_token }: { account: Account; target_code: string; preview_token: string }) => {
            const response = await api.post(`/master/accounts/${account.id}/transfer`, { target_code, preview_token });
            assertPersistedAccount(response.data?.account);
            return response;
        },
        onSuccess: (response) => {
            message.success(`Đã chuyển ${response.data?.affected_references ?? 0} tham chiếu hạch toán.`);
            setTransferAccount(null);
            setTransferPreview(null);
            transferForm.resetFields();
            queryClient.invalidateQueries({ queryKey: ['accounts'] });
        },
        onError: (error) => message.error(apiErrorMessage(error, 'Không thể chuyển tài khoản hạch toán.')),
    });

    const previewMutation = useMutation({
        mutationFn: async ({ account, target_code }: { account: Account; target_code: string }) => {
            const { data } = await api.post(`/master/accounts/${account.id}/transfer`, { target_code, preview: true });
            if (data?.preview !== true || !Number.isInteger(data?.affected_references) || data.affected_references < 0
                || typeof data.preview_token !== 'string' || !/^[a-f0-9]{64}$/.test(data.preview_token)) {
                throw new Error('Máy chủ chưa trả về kết quả xem trước hợp lệ.');
            }
            return { sourceId: account.id, targetCode: target_code, count: data.affected_references as number, token: data.preview_token };
        },
        onSuccess: setTransferPreview,
        onError: (error) => { setTransferPreview(null); message.error(apiErrorMessage(error, 'Không thể kiểm tra tham chiếu tài khoản.')); },
    });

    const handleDelete = async (account: Account) => {
        try {
            const response = await api.delete(`/master/accounts/${account.id}`);
            if (response?.status !== 204) throw new Error('Máy chủ không xác nhận đã xóa tài khoản.');
            message.success('Đã xóa tài khoản.'); queryClient.invalidateQueries({ queryKey: ['accounts'] });
        } catch (error) { message.error(apiErrorMessage(error, 'Không thể xóa tài khoản này.')); }
    };

    const confirmDelete = (account: Account) => {
        Modal.confirm({
            title: 'Xóa tài khoản?',
            content: `Tài khoản ${account.code} — ${account.name} sẽ bị xóa khỏi danh mục.`,
            okText: 'Xóa', cancelText: 'Hủy', okButtonProps: { danger: true },
            onOk: () => handleDelete(account),
        });
    };

    const openDuplicate = (account: Account) => {
        setEditingAccount(null);
        form.resetFields();
        form.setFieldsValue({ ...account, code: undefined, name: `${account.name} (bản sao)`, is_active: true });
        setIsModalOpen(true);
    };

    const exportExcel = () => {
        const rows = accountRows.map((item) => ({
            'Số tài khoản': item.code, 'Tên tài khoản': item.name, 'Tính chất': natureLabels[item.nature] ?? item.nature,
            'Diễn giải': item.description ?? '', 'Loại tài khoản': typeLabels[item.type] ?? item.type,
            'Tài khoản tổng hợp': item.parent_code ?? '', 'Trạng thái': item.is_active ? 'Đang sử dụng' : 'Ngừng sử dụng',
        }));
        const sheet = XLSX.utils.json_to_sheet(rows);
        sheet['!cols'] = [{ wch: 16 }, { wch: 36 }, { wch: 16 }, { wch: 36 }, { wch: 22 }, { wch: 20 }, { wch: 18 }];
        const book = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(book, sheet, 'He_thong_tai_khoan');
        XLSX.writeFile(book, 'He_thong_tai_khoan.xlsx');
    };

    const toggleExpanded = (account: Account) => {
        setExpandedRowKeys((keys) => keys.includes(account.id)
            ? keys.filter((key) => key !== account.id)
            : [...keys, account.id]);
    };

    const columns: TableColumnsType<AccountRow> = [
        { title: 'Số tài khoản', dataIndex: 'code', key: 'code', width: 140, fixed: 'left', render: (value: string, record) => (
            <span className="coa-workbench__code-cell" style={{ paddingLeft: record.treeDepth * 22 }}>
                {record.hasChildren ? <button type="button" className="coa-workbench__tree-toggle" onClick={() => toggleExpanded(record)} aria-label={`${expandedRowKeys.includes(record.id) ? 'Thu gọn' : 'Mở rộng'} ${record.code}`}>
                    {expandedRowKeys.includes(record.id) ? <CaretDownOutlined /> : <CaretRightOutlined />}
                </button> : <span className="coa-workbench__tree-spacer" />}
                <button type="button" className="coa-workbench__account-link" onClick={() => openEdit(record)}>{value}</button>
            </span>
        ) },
        { title: 'Tên tài khoản', dataIndex: 'name', key: 'name', width: 245, render: (value: string, record) => (
            <span className={`coa-workbench__name-cell ${record.is_parent ? 'coa-workbench__parent-name' : ''}`}>
                {value}
            </span>
        ) },
        { title: 'Tính chất', dataIndex: 'nature', key: 'nature', width: 115, render: (value: string) => natureLabels[value] ?? value },
        { title: 'Diễn giải', dataIndex: 'description', key: 'description', width: 185, render: (value: string | null) => value || <span className="coa-workbench__empty">—</span> },
        { title: 'Trạng thái', dataIndex: 'is_active', key: 'is_active', width: 135, render: (active: boolean) => (
            <Tag className="coa-workbench__status" color={active ? 'success' : 'default'}>{active ? 'Đang sử dụng' : 'Ngừng sử dụng'}</Tag>
        ) },
        { title: 'Chức năng', key: 'actions', width: 115, fixed: 'right', align: 'right', render: (_, record) => (
            <span className="coa-workbench__function-actions">
                <Button type="link" className="coa-workbench__function-button" onClick={() => openEdit(record)}>Sửa</Button>
                <Dropdown menu={{ items: [
                    { key: 'duplicate', icon: <CopyOutlined />, label: 'Nhân bản', onClick: () => openDuplicate(record) },
                    { key: 'delete', icon: <DeleteOutlined />, danger: true, label: 'Xóa', disabled: !canDelete || record.hasChildren, onClick: () => confirmDelete(record) },
                    { key: 'transfer', icon: <SwapOutlined />, label: 'Chuyển tài khoản hạch toán', disabled: !canTransfer || record.hasChildren, onClick: () => { transferForm.resetFields(); setTransferPreview(null); setTransferAccount(record); } },
                    { key: 'status', icon: record.is_active ? <StopOutlined /> : <ReloadOutlined />, label: record.is_active ? 'Ngừng sử dụng' : 'Kích hoạt', onClick: () => statusMutation.mutate({ account: record, is_active: !record.is_active }) },
                ] }} trigger={['click']} placement="bottomRight">
                    <Button type="link" className="coa-workbench__function-arrow" aria-label={`Chức năng ${record.code}`} icon={<CaretDownOutlined />} />
                </Dropdown>
            </span>
        ) },
    ];

    return (
        <PageShell
            className="coa-workbench"
            navigation={<MasterDataNavigation />}
            title={<PageHeader title="Hệ thống tài khoản" />}
            toolbar={<PageToolbar className="coa-workbench__header" filters={(
                <>
                    <div className="coa-workbench__title-actions">
                        <Button disabled={!canTransfer || !accountRows.some((item) => item.is_active && !item.is_parent)} icon={<SwapOutlined />} onClick={() => {
                            const first = accountRows.find((item) => item.is_active && !item.is_parent);
                            if (first) { transferForm.resetFields(); setTransferPreview(null); setTransferAccount(first); }
                        }}>Chuyển tài khoản hạch toán</Button>
                    </div>
                    <Input allowClear value={search} onChange={(event) => setSearch(event.target.value)} prefix={<SearchOutlined />}
                        placeholder="Tìm kiếm theo số, tên tài khoản" className="coa-workbench__search" />
                    <Button icon={allExpanded ? <MinusSquareOutlined /> : <PlusSquareOutlined />} onClick={() => setExpandedRowKeys(allExpanded ? [] : parentRowKeys)}>
                        {allExpanded ? 'Thu gọn' : 'Mở rộng'}
                    </Button>
                    <label className="coa-workbench__inactive-filter"><span>Hiển thị tài khoản ngừng sử dụng</span><Switch size="small" checked={includeInactive} onChange={setIncludeInactive} /></label>
                </>
            )} actions={(
                <>
                    <Tooltip title="Làm mới"><Button aria-label="Làm mới" icon={<ReloadOutlined spin={isFetching && !isLoading} />} onClick={() => refetch()} /></Tooltip>
                    <Tooltip title="Xuất Excel"><Button aria-label="Xuất Excel" icon={<FileExcelOutlined />} onClick={exportExcel} disabled={!accountRows.length} /></Tooltip>
                    <Tooltip title="Thiết lập cột"><Button aria-label="Thiết lập cột" icon={<SettingOutlined />} /></Tooltip>
                    <span className="coa-workbench__add-group">
                        <Button type="primary" className="coa-workbench__add-button" aria-label="Thêm tài khoản" icon={<PlusOutlined />} onClick={() => openCreate()}>
                            Thêm
                        </Button>
                        <Dropdown menu={{ items: [
                            { key: 'account', icon: <PlusOutlined />, label: 'Thêm tài khoản', onClick: () => openCreate() },
                            { key: 'parent', icon: <PlusOutlined />, label: 'Thêm tài khoản tổng hợp', onClick: () => openCreate() },
                        ] }} trigger={['click']} placement="bottomRight">
                            <Button type="primary" className="coa-workbench__add-menu-button" aria-label="Tùy chọn thêm tài khoản" icon={<CaretDownOutlined />} />
                        </Dropdown>
                    </span>
                </>
            )} />}
        >
            {isError && <Alert className="coa-workbench__alert" type="error" showIcon message="Không thể tải hệ thống tài khoản"
                description="Không nhận được danh mục hợp lệ từ máy chủ. Hãy kiểm tra kết nối rồi bấm Làm mới." />}
            <DataTableSurface className="coa-workbench__table-surface">
                <Table<AccountRow> columns={columns} dataSource={tableRows} rowKey="id" loading={isLoading} size="small" sticky
                    scroll={{ x: 960, y: 'calc(100vh - 290px)' }}
                    pagination={{ current: currentPage, pageSize, total: visibleRows.length, showSizeChanger: true, pageSizeOptions: [20, 50, 100],
                        onChange: (page, size) => { setCurrentPage(size !== pageSize ? 1 : page); setPageSize(size); },
                         showTotal: () => `Tổng số: ${accountRows.length}`, placement: ['bottomEnd'] }}
                    locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={deferredSearch ? 'Không tìm thấy tài khoản phù hợp' : 'Chưa có tài khoản'} /> }} />
            </DataTableSurface>

            <Modal title={editingAccount ? `Sửa tài khoản ${editingAccount.code}` : 'Thêm tài khoản'} open={isModalOpen}
                onOk={() => form.submit()} onCancel={() => { setIsModalOpen(false); setEditingAccount(null); }}
                okText={editingAccount ? 'Cất' : 'Cất và Thêm'} cancelText="Hủy" confirmLoading={mutation.isPending} width={760} className="coa-account-modal">
                <ModalFrame>
                    <Form form={form} layout="vertical" onFinish={(values) => mutation.mutate(values)}>
                        <div className="coa-account-modal__grid">
                            <Form.Item name="code" label="Số tài khoản" rules={[{ required: true, message: 'Nhập số tài khoản.' }, { max: 20 }]}><Input disabled={Boolean(editingAccount)} autoFocus={!editingAccount} /></Form.Item>
                            <Form.Item name="parent_code" label="Tài khoản tổng hợp"><Select allowClear showSearch optionFilterProp="label" options={parentAccountOptions} placeholder="Chọn từ catalogue máy chủ (nếu có)" /></Form.Item>
                        </div>
                        <Form.Item name="name" label="Tên tài khoản" rules={[{ required: true, message: 'Nhập tên tài khoản.' }]}><Input /></Form.Item>
                        <div className="coa-account-modal__grid">
                            <Form.Item name="type" label="Loại tài khoản" rules={[{ required: true }]}><Select options={Object.entries(typeLabels).map(([value, label]) => ({ value, label }))} /></Form.Item>
                            <Form.Item name="nature" label="Tính chất" rules={[{ required: true }]}><Select options={Object.entries(natureLabels).map(([value, label]) => ({ value, label }))} /></Form.Item>
                        </div>
                        <Form.Item name="description" label="Diễn giải"><Input.TextArea rows={3} maxLength={1000} showCount /></Form.Item>
                         <Form.Item className="coa-account-modal__switch">
                             <span className="coa-account-modal__switch-label">
                                 <Form.Item name="is_active" valuePropName="checked" noStyle><Switch /></Form.Item>
                                 <span>Đang sử dụng</span>
                             </span>
                         </Form.Item>
                    </Form>
                </ModalFrame>
            </Modal>

            <Modal title="Chuyển tài khoản hạch toán" open={Boolean(transferAccount)}
                onOk={() => transferForm.submit()} onCancel={() => { if (transferMutation.isPending || previewMutation.isPending) return; setTransferAccount(null); setTransferPreview(null); transferForm.resetFields(); }}
                okText={previewMatches ? 'Xác nhận chuyển' : 'Xem trước'} cancelText="Hủy"
                okButtonProps={{ disabled: !canTransfer }}
                confirmLoading={transferMutation.isPending || previewMutation.isPending} width={620} className="coa-account-modal">
                <ModalFrame>
                    <p className="coa-transfer-modal__hint">
                        Chỉ chuyển tham chiếu còn được sửa trong kỳ mở của <strong>{transferAccount?.code} — {transferAccount?.name}</strong>. Không thay đổi lịch sử đã ghi sổ hoặc cấu hình đã duyệt. Tài khoản nguồn sẽ ngừng sử dụng sau khi xác nhận.
                    </p>
                    {previewMatches && transferPreview && <Alert type="info" showIcon title={`${transferPreview.count} tham chiếu sẽ được chuyển. Máy chủ kiểm tra lại trước khi lưu.`} />}
                    <Form form={transferForm} layout="vertical" onValuesChange={() => setTransferPreview(null)} onFinish={(values) => {
                        if (!transferAccount || !canTransfer || transferMutation.isPending || previewMutation.isPending) return;
                        const variables = { account: transferAccount, target_code: values.target_code };
                        if (previewMatches && transferPreview) {
                            transferMutation.mutate({ ...variables, preview_token: transferPreview.token });
                        } else {
                            previewMutation.mutate(variables);
                        }
                    }}>
                        <Form.Item name="target_code" label="Tài khoản hạch toán mới" rules={[{ required: true, message: 'Chọn tài khoản đích.' }]}>
                            <Select disabled={transferMutation.isPending || previewMutation.isPending} showSearch optionFilterProp="label" options={catalogueRows.filter((item) => item.is_active && !item.is_parent && item.code !== transferAccount?.code).map((item) => ({ value: item.code, label: `${item.code} — ${item.name}` }))} placeholder="Chọn tài khoản đích" />
                        </Form.Item>
                    </Form>
                </ModalFrame>
            </Modal>
        </PageShell>
    );
};

export default ChartOfAccounts;
