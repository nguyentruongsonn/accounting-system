import React, { useEffect, useState, useMemo, useCallback } from 'react';
import { Alert, Drawer, Form, Input, Tooltip, Spin } from 'antd';
import { toast as message } from '../feedback/toast';
import { AdaptiveSelect as Select } from '../layout/AdaptiveSelect';
import { CloseOutlined, QuestionCircleOutlined, SearchOutlined } from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { MisaButton } from './MisaButton';

export interface ReasonItem {
    id?: number | null;
    value: string;
    label: string;
    debit?: string;
    credit?: string;
    reason: string;
    operation?: string;
    voucher_type?: string;
    filter_debit?: string;
    filter_credit?: string;
    is_system?: boolean;
}

interface QuickAddReasonModalProps {
    open: boolean;
    onCancel: () => void;
    category?: 'cash_receipt' | 'cash_payment' | 'bank_receipt' | 'bank_payment';
    onSuccess?: (newReason: ReasonItem) => void;
}

// Map category → default voucher_type
const CATEGORY_VOUCHER_MAP: Record<string, string> = {
    cash_receipt: 'thu_tien_mat',
    cash_payment: 'chi_tien_mat',
    bank_receipt: 'thu_tien_gui',
    bank_payment: 'chi_tien_gui',
};

/**
 * Lọc tài khoản theo các prefix cách nhau dấu phẩy.
 * VD: "131, 511, 711" → lọc TK bắt đầu bằng 131 HOẶC 511 HOẶC 711
 */
function filterAccountsByPrefix(accounts: any[], filterStr: string): any[] {
    if (!filterStr?.trim()) return accounts;
    const prefixes = filterStr.split(',').map(p => p.trim()).filter(Boolean);
    if (prefixes.length === 0) return accounts;
    return accounts.filter(acc =>
        prefixes.some(prefix => String(acc.code ?? '').startsWith(prefix))
    );
}

export const QuickAddReasonModal: React.FC<QuickAddReasonModalProps> = ({
    open,
    onCancel,
    category = 'cash_receipt',
    onSuccess,
}) => {
    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const [debitFilter, setDebitFilter] = useState('');
    const [creditFilter, setCreditFilter] = useState('');

    // Voucher types are tenant-owned catalogue data. Never use a MISA-like
    // static list as a fallback when the server has not published its values.
    const { data: rawVoucherTypes = [], isLoading: voucherTypesLoading, isError: voucherTypesError } = useQuery({
        queryKey: ['voucher-type-settings', 'types'],
        queryFn: async () => {
            const { data } = await api.get('/master/voucher-type-settings/types');
            const payload = Array.isArray(data) ? data : data?.data;
            if (!Array.isArray(payload)) throw new Error('Invalid voucher-type catalogue response.');
            return payload.filter((option: any) => typeof option?.value === 'string' && typeof option?.label === 'string');
        },
        enabled: open,
    });

    const voucherTypeOptions = useMemo(() => {
        const serverOptions = rawVoucherTypes as Array<{ value: string; label: string }>;
        if (category !== 'cash_receipt' && category !== 'cash_payment') return serverOptions;
        return serverOptions.filter((option) => option.value !== 'thu_tien_gui' && option.value !== 'chi_tien_gui');
    }, [rawVoucherTypes, category]);

    // Fetch chart of accounts (cached 15 min)
    const { data: rawAccounts = [], isLoading: accountsLoading } = useQuery({
        queryKey: ['chart-of-accounts'],
        queryFn: async () => {
            const { data } = await api.get('/master/accounts');
            return Array.isArray(data) ? data : (data?.data ?? []);
        },
        staleTime: 15 * 60 * 1000,
        gcTime: 30 * 60 * 1000,
    });

    // Flatten accounts: only leaf accounts (not parent)
    const accounts = useMemo(
        () => rawAccounts.filter((a: any) => !a.is_parent && a.is_active !== false),
        [rawAccounts]
    );

    // Filtered lists for TK Nợ / TK Có
    const debitAccounts = useMemo(
        () => filterAccountsByPrefix(accounts, debitFilter),
        [accounts, debitFilter]
    );
    const creditAccounts = useMemo(
        () => filterAccountsByPrefix(accounts, creditFilter),
        [accounts, creditFilter]
    );

    // Save to DB via API
    const saveMutation = useMutation({
        mutationFn: async (payload: Record<string, unknown>) => {
            const { data } = await api.post('/master/voucher-type-settings', payload);
            return data;
        },
        onSuccess: (data) => {
            const persistedSetting = data?.data ?? data;
            if (!persistedSetting || persistedSetting.id === undefined || persistedSetting.id === null) {
                message.error('Máy chủ không trả về tài khoản ngầm định đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }

            queryClient.invalidateQueries({ queryKey: ['voucher-type-settings'] });
            message.success('Thêm tài khoản ngầm định thành công!');

            if (onSuccess) {
                const record = data?.data ?? data;
                onSuccess({
                    id: record.id,
                    value: record.name,
                    label: record.name,
                    debit: record.debit_account,
                    credit: record.credit_account,
                    reason: record.name,
                    voucher_type: record.voucher_type,
                    filter_debit: record.filter_debit,
                    filter_credit: record.filter_credit,
                });
            }
            form.resetFields();
            onCancel();
        },
        onError: (err: any) => {
            message.error(err?.response?.data?.message ?? 'Có lỗi xảy ra, vui lòng thử lại');
        },
    });

    // Reset form when drawer opens
    useEffect(() => {
        if (open) {
            const requestedVoucherType = CATEGORY_VOUCHER_MAP[category];
            const defaultVoucherType = voucherTypeOptions.some((option) => option.value === requestedVoucherType)
                ? requestedVoucherType
                : undefined;

            setDebitFilter('');
            setCreditFilter('');

            form.setFieldsValue({
                voucher_type: defaultVoucherType,
                name: '',
                debit_account: undefined,
                credit_account: undefined,
            });
        }
    }, [open, category, form, voucherTypeOptions]);

    const handleSave = useCallback(async () => {
        try {
            const values = await form.validateFields();
            saveMutation.mutate({
                voucher_type: values.voucher_type,
                name: values.name,
                debit_account: values.debit_account ?? null,
                credit_account: values.credit_account ?? null,
                filter_debit: debitFilter || null,
                filter_credit: creditFilter || null,
                is_active: true,
            });
        } catch {
            // validation failed, antd shows field errors
        }
    }, [form, saveMutation, debitFilter, creditFilter]);

    const handleCancel = useCallback(() => {
        form.resetFields();
        onCancel();
    }, [form, onCancel]);

    // Account option renderer — 2-column table style (chuẩn MISA AMIS)
    const renderAccountOption = useCallback((account: any) => ({
        value: account.code,
        label: (
            <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
                <span style={{ minWidth: 60, fontWeight: 700, color: '#1677ff', fontFamily: 'monospace' }}>
                    {account.code}
                </span>
                <span style={{ color: '#333', flex: 1 }}>{account.name}</span>
            </div>
        ),
        searchValue: `${account.code} ${account.name}`,
    }), []);

    const debitOptions = useMemo(() => debitAccounts.map(renderAccountOption), [debitAccounts, renderAccountOption]);
    const creditOptions = useMemo(() => creditAccounts.map(renderAccountOption), [creditAccounts, renderAccountOption]);

    const isSaving = saveMutation.isPending;

    return (
        <Drawer
            className="misa-reason-drawer"
            open={open}
            onClose={handleCancel}
            size={800}
            placement="right"
            closable={false}
            maskClosable={false}
            styles={{
                header: { display: 'none' },
            }}
        >
            {/* Custom Header — chuẩn MISA AMIS */}
            <div className="misa-reason-drawer__header">
                <div className="misa-reason-drawer__header-main">
                    <span className="misa-reason-drawer__title">
                        Thêm Tài khoản ngầm định
                    </span>
                    <Tooltip title="Tài khoản ngầm định giúp tự động điền TK Nợ/Có khi lập chứng từ">
                        <QuestionCircleOutlined className="misa-reason-drawer__help" />
                    </Tooltip>
                </div>
                <MisaButton
                    variant="tool"
                    aria-label="Đóng"
                    className="misa-reason-drawer__close"
                    icon={<CloseOutlined />}
                    onClick={handleCancel}
                />
            </div>

            {/* Body */}
            <div className="misa-reason-drawer__body">
                <Spin spinning={accountsLoading || voucherTypesLoading}>
                    {(voucherTypesError || voucherTypeOptions.length === 0) && !voucherTypesLoading && (
                        <Alert
                            type="warning"
                            showIcon
                            style={{ marginBottom: 16 }}
                            message="Chưa có loại chứng từ từ máy chủ"
                            description="Không dùng danh sách tĩnh thay thế. Hãy cấu hình catalogue loại chứng từ trên máy chủ trước khi lưu tài khoản ngầm định."
                        />
                    )}
                    <Form form={form} layout="horizontal" colon={false} labelCol={{ span: 7 }} wrapperCol={{ span: 17 }}>

                        {/* Loại chứng từ */}
                        <Form.Item
                            name="voucher_type"
                            label={<span>Loại chứng từ <span style={{ color: '#ff4d4f' }}>*</span></span>}
                            rules={[{ required: true, message: 'Vui lòng chọn loại chứng từ' }]}
                        >
                            <Select
                                options={voucherTypeOptions}
                                placeholder="Chọn loại chứng từ"
                                style={{ width: '100%' }}
                            />
                        </Form.Item>

                        {/* Tên định khoản */}
                        <Form.Item
                            name="name"
                            label={<span>Tên định khoản <span style={{ color: '#ff4d4f' }}>*</span></span>}
                            rules={[{ required: true, message: 'Vui lòng nhập tên định khoản' }]}
                        >
                            <Input
                                placeholder="Nhập tên định khoản..."
                                maxLength={255}
                                autoFocus
                            />
                        </Form.Item>

                        {/* Bảng Tài khoản ngầm định — 3 cột */}
                        <Form.Item label="Tài khoản ngầm định" style={{ marginBottom: 0 }}>
                            <div style={{ border: '1px solid #e8e8e8', borderRadius: 4, overflow: 'hidden' }}>
                                {/* Header */}
                                <div style={{
                                    display: 'grid',
                                    gridTemplateColumns: '100px 1fr 1fr',
                                    background: '#fafafa',
                                    borderBottom: '1px solid #e8e8e8',
                                }}>
                                    {['Tên cột', 'Lọc tài khoản', 'TK ngầm định'].map((h) => (
                                        <div key={h} style={{ padding: '8px 12px', fontWeight: 600, fontSize: 13, color: '#555' }}>
                                            {h}
                                        </div>
                                    ))}
                                </div>

                                {/* TK Nợ row */}
                                <div style={{
                                    display: 'grid',
                                    gridTemplateColumns: '100px 1fr 1fr',
                                    borderBottom: '1px solid #f0f0f0',
                                    alignItems: 'center',
                                }}>
                                    <div style={{ padding: '10px 12px', fontWeight: 500, color: '#333' }}>TK Nợ</div>
                                    <div style={{ padding: '6px 8px' }}>
                                        <Input
                                            prefix={<SearchOutlined style={{ color: '#bbb' }} />}
                                            value={debitFilter}
                                            onChange={(e) => setDebitFilter(e.target.value)}
                                            placeholder="Lọc theo tiền tố từ catalogue máy chủ"
                                            size="small"
                                        />
                                    </div>
                                    <div style={{ padding: '6px 8px' }}>
                                        <Form.Item name="debit_account" style={{ margin: 0 }}>
                                            <Select
                                                showSearch
                                                allowClear
                                                placeholder="Chọn TK Nợ..."
                                                size="small"
                                                optionFilterProp="searchValue"
                                                options={debitOptions}
                                                listHeight={200}
                                                dropdownStyle={{ minWidth: 380 }}
                                                notFoundContent={<span style={{ color: '#999', fontSize: 12 }}>Không tìm thấy tài khoản</span>}
                                            />
                                        </Form.Item>
                                    </div>
                                </div>

                                {/* TK Có row */}
                                <div style={{
                                    display: 'grid',
                                    gridTemplateColumns: '100px 1fr 1fr',
                                    alignItems: 'center',
                                }}>
                                    <div style={{ padding: '10px 12px', fontWeight: 500, color: '#333' }}>TK Có</div>
                                    <div style={{ padding: '6px 8px' }}>
                                        <Input
                                            prefix={<SearchOutlined style={{ color: '#bbb' }} />}
                                            value={creditFilter}
                                            onChange={(e) => setCreditFilter(e.target.value)}
                                            placeholder="Lọc theo tiền tố từ catalogue máy chủ"
                                            size="small"
                                        />
                                    </div>
                                    <div style={{ padding: '6px 8px' }}>
                                        <Form.Item name="credit_account" style={{ margin: 0 }}>
                                            <Select
                                                showSearch
                                                allowClear
                                                placeholder="Chọn TK Có..."
                                                size="small"
                                                optionFilterProp="searchValue"
                                                options={creditOptions}
                                                listHeight={200}
                                                dropdownStyle={{ minWidth: 380 }}
                                                notFoundContent={<span style={{ color: '#999', fontSize: 12 }}>Không tìm thấy tài khoản</span>}
                                            />
                                        </Form.Item>
                                    </div>
                                </div>
                            </div>
                        </Form.Item>

                    </Form>
                </Spin>
            </div>

            {/* Footer — sticky bottom, chuẩn MISA AMIS */}
            <div className="misa-reason-drawer__footer">
                <MisaButton onClick={handleCancel} disabled={isSaving}>
                    Hủy
                </MisaButton>
                <MisaButton
                    variant="primary"
                    onClick={handleSave}
                    loading={isSaving}
                    disabled={voucherTypesLoading || voucherTypesError || voucherTypeOptions.length === 0}
                >
                    Cất
                </MisaButton>
            </div>
        </Drawer>
    );
};

export default QuickAddReasonModal;
