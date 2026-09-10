import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, Button, DatePicker, Form, Input, InputNumber, Space, Table } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import { InboxOutlined, PlusOutlined, ReloadOutlined, SearchOutlined } from '@ant-design/icons';
import { Dayjs } from 'dayjs';
import dayjs from 'dayjs';
import api from '../../api/axios';
import { formatDate } from '../../utils/dateUtils';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

interface AdvanceSettlement {
    id: number; settlement_number: string; settlement_date: string; employee_name: string;
    department?: string | null; advance_amount?: number | null; actual_spent?: number | null;
    refund_amount?: number | null; extra_amount?: number | null; reason?: string | null; status?: string | null;
}

const listPayload = (payload: any): any[] => {
    if (Array.isArray(payload?.data)) return payload.data;
    if (Array.isArray(payload?.data?.data)) return payload.data.data;
    throw new Error('Invalid cash advance settlement response');
};
const entityPayload = (payload: any): AdvanceSettlement | null => {
    const value = payload?.data?.data ?? payload?.data;
    return value && Number.isInteger(Number(value.id)) ? value : null;
};
const dateValue = (value: Dayjs | null | undefined): string | undefined => value?.isValid() ? value.format('YYYY-MM-DD') : undefined;
const moneyValue = (value: unknown): string => {
    if (value === null || value === undefined || value === '') return '—';
    const numeric = Number(value);
    return Number.isFinite(numeric) ? `${new Intl.NumberFormat('vi-VN').format(numeric)} ₫` : '—';
};
const statusLabel = (value: unknown): string => {
    if (value === 'submitted') return 'Đã gửi duyệt';
    if (value === 'draft') return 'Nháp';
    return typeof value === 'string' && value.trim() ? value : '—';
};

export const CashAdvanceSettlements: React.FC = React.memo(() => {
    const [form] = Form.useForm();
    const [records, setRecords] = useState<AdvanceSettlement[]>([]);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [open, setOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);

    const load = useCallback(async () => {
        setLoading(true);
        setLoadError(null);
        try { const response = await api.get('/cash/advance-settlements'); setRecords(listPayload(response.data).filter((v: any) => Number.isInteger(Number(v?.id))) as AdvanceSettlement[]); }
        catch (error) { setLoadError('Máy chủ không trả về danh sách quyết toán hợp lệ. Các dòng đang hiển thị được giữ nguyên.'); console.error(error); }
        finally { setLoading(false); }
    }, []);
    useEffect(() => { void load(); }, [load]);

    const closeModal = () => {
        setOpen(false);
        setEditingId(null);
        form.resetFields();
    };
    const openCreate = () => {
        setEditingId(null);
        form.resetFields();
        setOpen(true);
    };
    const openEdit = (row: AdvanceSettlement) => {
        setEditingId(row.id);
        form.setFieldsValue({
            settlement_number: row.settlement_number,
            settlement_date: row.settlement_date ? dayjs(row.settlement_date) : undefined,
            employee_name: row.employee_name,
            department: row.department ?? undefined,
            advance_amount: row.advance_amount,
            actual_spent: row.actual_spent,
            reason: row.reason,
        });
        setOpen(true);
    };
    const save = async (values: any) => {
        setSaving(true);
        try {
            const payload = { settlement_number: values.settlement_number, settlement_date: dateValue(values.settlement_date), employee_id: values.employee_id || null, employee_name: values.employee_name, department: values.department || null, advance_amount: values.advance_amount, actual_spent: values.actual_spent, reason: values.reason };
            const response = editingId === null
                ? await api.post('/cash/advance-settlements', payload)
                : await api.put(`/cash/advance-settlements/${editingId}`, payload);
            if (!entityPayload(response.data)) throw new Error('Server did not return a persisted settlement id');
            message.success(editingId === null ? 'Đã lưu quyết toán tạm ứng nháp.' : 'Đã cập nhật quyết toán tạm ứng nháp.'); closeModal(); await load();
        } catch (error) { message.error('Không thể lưu; máy chủ chưa xác nhận dữ liệu.'); console.error(error); }
        finally { setSaving(false); }
    };
    const submit = async (id: number) => {
        try { const response = await api.post(`/cash/advance-settlements/${id}/submit`); const entity = entityPayload(response.data); if (!entity || entity.status !== 'submitted') throw new Error('Submit not confirmed'); message.success('Đã gửi quyết toán tạm ứng để phê duyệt.'); await load(); }
        catch (error) { message.error('Không thể gửi quyết toán; máy chủ chưa xác nhận.'); console.error(error); }
    };
    const remove = async (id: number) => {
        try { const response = await api.delete(`/cash/advance-settlements/${id}`); if (response.status !== 204 && typeof response.data?.message !== 'string') throw new Error('Delete not confirmed'); message.success('Đã xóa quyết toán tạm ứng nháp.'); await load(); }
        catch (error) { message.error('Không thể xóa quyết toán; máy chủ chưa xác nhận.'); console.error(error); }
    };
    const filtered = useMemo(() => { const q = search.trim().toLowerCase(); return records.filter(r => !q || [r.settlement_number, r.employee_name, r.department].some(v => String(v ?? '').toLowerCase().includes(q))); }, [records, search]);
    const columns = [
        { title: 'Số quyết toán', dataIndex: 'settlement_number', key: 'settlement_number' },
        { title: 'Ngày quyết toán', dataIndex: 'settlement_date', key: 'settlement_date', render: (v: string) => formatDate(v) },
        { title: 'Người tạm ứng', dataIndex: 'employee_name', key: 'employee_name' },
        { title: 'Phòng ban', dataIndex: 'department', key: 'department', render: (v: string | null) => v || '—' },
        { title: 'Đã tạm ứng', dataIndex: 'advance_amount', key: 'advance_amount', render: (v: number | null | undefined) => moneyValue(v) },
        { title: 'Thực chi', dataIndex: 'actual_spent', key: 'actual_spent', render: (v: number | null | undefined) => moneyValue(v) },
        { title: 'Hoàn ứng', dataIndex: 'refund_amount', key: 'refund_amount', render: (v: number | null | undefined) => moneyValue(v) },
        { title: 'Chi bổ sung', dataIndex: 'extra_amount', key: 'extra_amount', render: (v: number | null | undefined) => moneyValue(v) },
        { title: 'Trạng thái', dataIndex: 'status', key: 'status', render: (v: string | null | undefined) => <span className="misa-apple-pill misa-apple-pill-orange"><span className="misa-apple-pill-dot" />{statusLabel(v)}</span> },
        { title: 'Chức năng', key: 'action', render: (_: unknown, r: AdvanceSettlement) => <Space>{r.status === 'draft' && <button type="button" className="misa-btn-link-action" onClick={() => openEdit(r)}>Sửa</button>}{r.status === 'draft' && <button type="button" className="misa-btn-link-success" onClick={() => void submit(r.id)}>Gửi duyệt</button>}{r.status === 'draft' && <button type="button" className="misa-btn-link-action" onClick={() => void remove(r.id)}>Xóa</button>}{r.status === 'submitted' && <span className="misa-color-muted">Thu/chi liên kết (chưa khả dụng)</span>}</Space> },
    ];
    return <PageShell title={<PageHeader eyebrow="Tiền mặt" title="Quyết toán tạm ứng" description="Lập và theo dõi quyết toán các khoản tạm ứng cho nhân viên." />}>
        <PageToolbar
            filters={<Input className="misa-input misa-w-280" placeholder="Tìm kiếm theo số QT, nhân viên..." prefix={<SearchOutlined />} allowClear value={search} onChange={e => setSearch(e.target.value)} />}
            actions={<><button type="button" className="misa-btn-tool" title="Làm mới" onClick={() => void load()} disabled={loading}><ReloadOutlined /></button><Button type="primary" icon={<PlusOutlined />} className="misa-btn-primary-green misa-btn-action-h32-b600" onClick={openCreate}>Lập quyết toán tạm ứng</Button></>}
        />
        {loadError && <Alert type="error" showIcon message="Không thể tải quyết toán tạm ứng" description={loadError} action={<Button size="small" onClick={() => void load()}>Thử lại</Button>} />}
        <DataTableSurface><Table className="misa-voucher-table" loading={loading} columns={columns} dataSource={filtered} rowKey="id" size="small" scroll={{ x: 'max-content' }} pagination={{ pageSize: 20 }} locale={{ emptyText: <div className="misa-table-empty-box"><InboxOutlined className="misa-table-empty-icon" />{loadError ? 'Chưa có dữ liệu tải được từ máy chủ.' : 'Chưa có dữ liệu quyết toán tạm ứng do máy chủ cung cấp.'}</div> }} /></DataTableSurface>
        <Modal title={editingId === null ? 'Lập Đề nghị quyết toán tạm ứng' : 'Sửa Đề nghị quyết toán tạm ứng'} open={open} onCancel={closeModal} destroyOnHidden footer={null} width={700}><Form form={form} layout="vertical" onFinish={save} className="misa-mt-16"><div className="misa-grid-2col-gap-16"><Form.Item name="settlement_number" label="Số quyết toán" rules={[{ required: true, message: 'Nhập số quyết toán' }]}><Input /></Form.Item><Form.Item name="settlement_date" label="Ngày quyết toán" rules={[{ required: true, message: 'Chọn ngày' }]}><DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" /></Form.Item><Form.Item name="employee_name" label="Người tạm ứng" rules={[{ required: true, message: 'Nhập người tạm ứng' }]}><Input /></Form.Item><Form.Item name="department" label="Phòng ban"><Input /></Form.Item><Form.Item name="advance_amount" label="Số tiền đã tạm ứng" rules={[{ required: true, message: 'Nhập số tiền' }, { type: 'number', min: 0.01 }]}><InputNumber className="misa-input misa-w-full" min={0.01} /></Form.Item><Form.Item name="actual_spent" label="Số tiền thực chi" rules={[{ required: true, message: 'Nhập số tiền' }, { type: 'number', min: 0 }]}><InputNumber className="misa-input misa-w-full" min={0} /></Form.Item></div><Form.Item name="reason" label="Nội dung / hồ sơ quyết toán" rules={[{ required: true, message: 'Nhập nội dung' }]}><Input.TextArea rows={3} /></Form.Item><div className="misa-modal-btn-footer"><Button onClick={closeModal}>Hủy</Button><Button type="primary" htmlType="submit" loading={saving}>{editingId === null ? 'Lưu quyết toán nháp' : 'Cập nhật quyết toán nháp'}</Button></div></Form></Modal>
    </PageShell>;
});
export default CashAdvanceSettlements;
