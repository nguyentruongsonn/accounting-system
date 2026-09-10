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
import ModalFrame from '../../components/layout/ModalFrame';

interface PaymentRequest {
    id: number;
    request_number: string;
    request_date: string;
    requester_name: string;
    department?: string | null;
    reason: string;
    amount: number | null;
    deadline?: string | null;
    status: 'draft' | 'submitted' | string;
}

const unwrapList = (payload: any): any[] => {
    const value = payload?.data;
    if (Array.isArray(value)) return value;
    if (Array.isArray(value?.data)) return value.data;
    throw new Error('Invalid cash payment request response');
};

const normalizePaymentRequest = (value: any): PaymentRequest | null => {
    if (!value || !Number.isInteger(Number(value.id))) return null;
    const rawAmount = value.amount;
    const amount = rawAmount === null || rawAmount === undefined || rawAmount === ''
        ? null
        : Number(rawAmount);
    return {
        ...value,
        id: Number(value.id),
        amount: amount !== null && Number.isFinite(amount) ? amount : null,
    } as PaymentRequest;
};

const unwrapEntity = (payload: any): PaymentRequest | null => {
    const value = payload?.data?.data ?? payload?.data;
    return normalizePaymentRequest(value);
};

const dateValue = (value: Dayjs | null | undefined): string | undefined => value?.isValid() ? value.format('YYYY-MM-DD') : undefined;
const statusLabel = (status: string): string => status === 'submitted' ? 'Đã gửi duyệt' : status === 'draft' ? 'Nháp' : status;

export const CashPaymentRequests: React.FC = React.memo(() => {
    // Đề nghị chi không tự suy diễn tài khoản kế toán; phiếu chi liên kết
    // chỉ được lập khi có API/source contract được công bố.
    const [form] = Form.useForm();
    const [requests, setRequests] = useState<PaymentRequest[]>([]);
    const [searchText, setSearchText] = useState('');
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [loadError, setLoadError] = useState(false);
    const [modalOpen, setModalOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);

    const loadRequests = useCallback(async () => {
        setLoading(true);
        setLoadError(false);
        try {
            const response = await api.get('/cash/payment-requests');
            setRequests(unwrapList(response.data).map(normalizePaymentRequest).filter((row): row is PaymentRequest => row !== null));
        } catch (error) {
            setLoadError(true);
            message.error('Không thể tải đề nghị chi tiền từ máy chủ.');
            console.error(error);
        } finally { setLoading(false); }
    }, []);

    useEffect(() => { void loadRequests(); }, [loadRequests]);

    const closeModal = () => {
        setModalOpen(false);
        setEditingId(null);
        form.resetFields();
    };

    const openCreate = () => {
        setEditingId(null);
        form.resetFields();
        setModalOpen(true);
    };

    const openEdit = (row: PaymentRequest) => {
        setEditingId(row.id);
        form.setFieldsValue({
            request_number: row.request_number,
            request_date: row.request_date ? dayjs(row.request_date) : undefined,
            requester_name: row.requester_name,
            department: row.department ?? undefined,
            reason: row.reason,
            amount: row.amount,
            deadline: row.deadline ? dayjs(row.deadline) : undefined,
        });
        setModalOpen(true);
    };

    const handleSave = async (values: any) => {
        setSaving(true);
        try {
            const payload = {
                request_number: values.request_number,
                request_date: dateValue(values.request_date),
                requester_name: values.requester_name,
                department: values.department || null,
                reason: values.reason,
                amount: values.amount,
                deadline: dateValue(values.deadline),
            };
            const response = editingId === null
                ? await api.post('/cash/payment-requests', payload)
                : await api.put(`/cash/payment-requests/${editingId}`, payload);
            const entity = unwrapEntity(response.data);
            if (!entity) throw new Error('Server did not return a persisted payment request id');
            message.success(editingId === null ? 'Đã lưu đề nghị chi nháp.' : 'Đã cập nhật đề nghị chi nháp.');
            closeModal();
            await loadRequests();
        } catch (error) {
            message.error('Không thể lưu đề nghị chi tiền; máy chủ chưa xác nhận dữ liệu.');
            console.error(error);
        } finally { setSaving(false); }
    };

    const handleSubmit = async (id: number) => {
        try {
            const response = await api.post(`/cash/payment-requests/${id}/submit`);
            const entity = unwrapEntity(response.data);
            if (!entity || entity.status !== 'submitted') throw new Error('Server did not confirm submitted payment request');
            message.success('Đã gửi đề nghị chi để phê duyệt.');
            await loadRequests();
        } catch (error) { message.error('Không thể gửi đề nghị chi; máy chủ chưa xác nhận.'); console.error(error); }
    };

    const handleDelete = async (id: number) => {
        try {
            const response = await api.delete(`/cash/payment-requests/${id}`);
            if (response.status !== 204 && typeof response.data?.message !== 'string') throw new Error('Delete not confirmed');
            message.success('Đã xóa đề nghị chi nháp.');
            await loadRequests();
        } catch (error) { message.error('Không thể xóa đề nghị chi; máy chủ chưa xác nhận.'); console.error(error); }
    };

    const filtered = useMemo(() => {
        const query = searchText.trim().toLowerCase();
        return requests.filter((row) => !query || [row.request_number, row.requester_name, row.department, row.reason].some(v => String(v ?? '').toLowerCase().includes(query)));
    }, [requests, searchText]);

    const columns = [
        { title: 'Số đề nghị', dataIndex: 'request_number', key: 'request_number' },
        { title: 'Ngày đề nghị', dataIndex: 'request_date', key: 'request_date', render: (value: string) => formatDate(value) },
        { title: 'Người đề nghị', dataIndex: 'requester_name', key: 'requester_name' },
        { title: 'Bộ phận / Phòng ban', dataIndex: 'department', key: 'department', render: (value: string | null) => value || '—' },
        { title: 'Nội dung đề nghị chi', dataIndex: 'reason', key: 'reason' },
        { title: 'Số tiền đề nghị', dataIndex: 'amount', key: 'amount', align: 'right' as const, render: (value: number | null) => value === null ? '—' : `${new Intl.NumberFormat('vi-VN').format(value)} ₫` },
        { title: 'Hạn chi', dataIndex: 'deadline', key: 'deadline', render: (value: string | null) => value ? formatDate(value) : '—' },
        { title: 'Trạng thái', dataIndex: 'status', key: 'status', render: (value: string) => <span className="misa-apple-pill misa-apple-pill-orange"><span className="misa-apple-pill-dot" />{statusLabel(value)}</span> },
        { title: 'Chức năng', key: 'action', render: (_: unknown, row: PaymentRequest) => <Space>
            {row.status === 'draft' && <button type="button" className="misa-btn-link-action" onClick={() => openEdit(row)}>Sửa</button>}
            {row.status === 'draft' && <button type="button" className="misa-btn-link-success" onClick={() => void handleSubmit(row.id)}>Gửi duyệt</button>}
            {row.status === 'draft' && <button type="button" className="misa-btn-link-action" onClick={() => void handleDelete(row.id)}>Xóa</button>}
            {row.status === 'submitted' && <span className="misa-color-muted">Lập phiếu chi (chưa khả dụng)</span>}
        </Space> },
    ];

    return <PageShell title={<PageHeader eyebrow="Tiền mặt" title="Đề nghị chi tiền — quy trình nháp nội bộ" description="Lập và theo dõi các đề nghị chi tiền trong kỳ." />}>
        <PageToolbar
            filters={<Input className="misa-input misa-w-280" placeholder="Tìm kiếm theo số đề nghị, người lập..." prefix={<SearchOutlined />} allowClear value={searchText} onChange={e => setSearchText(e.target.value)} />}
            actions={<><button type="button" className="misa-btn-tool" title="Làm mới" onClick={() => void loadRequests()} disabled={loading}><ReloadOutlined /></button><Button type="primary" icon={<PlusOutlined />} className="misa-btn-primary-green misa-btn-action-h32-b600" onClick={openCreate}>Lập đề nghị chi tiền</Button></>}
        />
        {loadError && <Alert
            className="mb-4"
            type="error"
            showIcon
            message="Không thể tải đề nghị chi tiền"
            description="Các dòng đang hiển thị được giữ nguyên; hãy thử lại khi máy chủ sẵn sàng."
            action={<Button size="small" onClick={() => void loadRequests()}>Thử lại</Button>}
        />}
        <DataTableSurface><Table className="misa-voucher-table" loading={loading} columns={columns} dataSource={filtered} rowKey="id" size="small" scroll={{ x: 'max-content' }} pagination={{ pageSize: 20 }} locale={{ emptyText: loadError ? 'Không có dữ liệu mới do lỗi tải.' : <div className="misa-table-empty-box"><InboxOutlined className="misa-table-empty-icon" />Chưa có dữ liệu đề nghị chi do máy chủ cung cấp.</div> }} /></DataTableSurface>
        <Modal title={editingId === null ? 'Lập Đề nghị chi tiền' : 'Sửa Đề nghị chi tiền'} open={modalOpen} onCancel={closeModal} destroyOnHidden footer={null} width={700}>
            <ModalFrame>
              <Form form={form} layout="vertical" onFinish={handleSave} className="misa-mt-16">
                <div className="misa-grid-2col-gap-16">
                    <Form.Item name="request_number" label="Số đề nghị chi" rules={[{ required: true, message: 'Nhập số đề nghị' }]}><Input className="misa-input" /></Form.Item>
                    <Form.Item name="request_date" label="Ngày đề nghị" rules={[{ required: true, message: 'Chọn ngày đề nghị' }]}><DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" /></Form.Item>
                    <Form.Item name="requester_name" label="Người đề nghị" rules={[{ required: true, message: 'Nhập người đề nghị' }]}><Input className="misa-input" /></Form.Item>
                    <Form.Item name="department" label="Phòng ban"><Input className="misa-input" placeholder="Nhập theo hồ sơ thực tế (nếu có)" /></Form.Item>
                    <Form.Item name="amount" label="Số tiền đề nghị (VND)" rules={[{ required: true, message: 'Nhập số tiền' }, { type: 'number', min: 0.01, message: 'Số tiền phải lớn hơn 0' }]}><InputNumber className="misa-input misa-w-full" min={0.01} /></Form.Item>
                    <Form.Item name="deadline" label="Hạn chi tiền"><DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" /></Form.Item>
                </div>
                <Form.Item name="reason" label="Nội dung / Lý do đề nghị chi" rules={[{ required: true, message: 'Nhập nội dung' }]}><Input.TextArea rows={3} /></Form.Item>
                <div className="misa-modal-btn-footer"><Button onClick={closeModal}>Hủy</Button><Button type="primary" htmlType="submit" loading={saving}>{editingId === null ? 'Lưu đề nghị nháp' : 'Cập nhật đề nghị nháp'}</Button></div>
              </Form>
            </ModalFrame>
        </Modal>
    </PageShell>;
});

export default CashPaymentRequests;
