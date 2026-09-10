import React, { useEffect, useMemo, useState } from 'react';
import { Alert, Button, Descriptions, Drawer, Form, Input, Select, Space, Table, Tag, Typography } from 'antd';
import { EyeOutlined, ReloadOutlined, SearchOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import api from '../../api/axios';
import { useAuthStore } from '../../store/useAuthStore';
import PageShell from '../../components/layout/PageShell';
import PageToolbar from '../../components/layout/PageToolbar';
import PageHeader from '../../components/layout/PageHeader';
import DataTableSurface from '../../components/layout/DataTableSurface';
import { toast } from '../../components/feedback/toast';

const VIEW_PERMISSION = 'accounting.audit-trail.view';
const ENTITY_OPTIONS = [
  ['purchase_invoice', 'Hóa đơn mua'], ['sales_invoice', 'Hóa đơn bán'],
  ['cash_receipt', 'Phiếu thu'], ['cash_payment', 'Phiếu chi'],
  ['bank_receipt', 'Thu tiền gửi'], ['bank_payment', 'Chi tiền gửi'],
  ['journal_entry', 'Chứng từ ghi sổ'], ['reconciliation_run', 'Lần đối chiếu'],
  ['period_close_signoff_package', 'Hồ sơ phê duyệt đóng kỳ'],
] as const;
type EntityKey = typeof ENTITY_OPTIONS[number][0];

export interface AuditEvent {
  id: number;
  occurred_at: string | null;
  action: string;
  entity_type: string;
  entity_id: string;
  actor_id: number | null;
  correlation_id: string | null;
  metadata: Record<string, unknown>;
}
interface AuditResponse { data: AuditEvent[]; meta: { current_page: number; per_page: number; total: number; last_page: number } }
interface Trace { read_only: true; tenant_scope: string; entity: Record<string, unknown>; journal_entries: Array<Record<string, unknown>>; approval_requests: Array<Record<string, unknown>>; events: AuditEvent[]; linkage_note: string }
interface Filters { entity_type?: EntityKey; entity_id?: string; action?: string; correlation_id?: string; from_date?: string; to_date?: string }

function entityKeyFromMorph(morph: string): EntityKey | undefined {
  const tail = morph.split('\\').pop()?.replace(/([a-z])([A-Z])/g, '$1_$2').toLowerCase();
  return ENTITY_OPTIONS.some(([key]) => key === tail) ? tail as EntityKey : undefined;
}
function entityLabel(value: string): string {
  const key = entityKeyFromMorph(value) ?? value;
  return ENTITY_OPTIONS.find(([id]) => id === key)?.[1] ?? 'Thực thể không thuộc phạm vi truy vết';
}
function safeTime(value: string | null): string { return value ? new Date(value).toLocaleString('vi-VN') : '—'; }
function serializeSafe(value: unknown): string { return JSON.stringify(value ?? {}, null, 2); }
function cleanFilters(filters: Filters): Record<string, string> {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== undefined && value !== '')) as Record<string, string>;
}
function parseAuditResponse(value: unknown): AuditResponse {
  if (!value || typeof value !== 'object') throw new Error('Invalid audit-trail response');
  const candidate = value as Partial<AuditResponse>;
  if (!Array.isArray(candidate.data) || !candidate.meta || typeof candidate.meta !== 'object') {
    throw new Error('Invalid audit-trail response');
  }
  return candidate as AuditResponse;
}

const AccountingAuditTrailExplorer: React.FC = () => {
  const user = useAuthStore((state) => state.user);
  const isAdmin = user?.roles?.includes('admin') ?? false;
  const canView = isAdmin && (user?.permissions?.includes(VIEW_PERMISSION) ?? false);
  const [form] = Form.useForm<Filters>();
  const [filters, setFilters] = useState<Filters>({});
  const [page, setPage] = useState(1);
  const [traceTarget, setTraceTarget] = useState<{ type: EntityKey; id: string } | null>(null);

  const audits = useQuery({
    queryKey: ['accounting-audit-trail', filters, page], enabled: canView,
    queryFn: async (): Promise<AuditResponse> => parseAuditResponse((await api.get('/accounting-audit-trail', { params: { ...cleanFilters(filters), page, per_page: 25 } })).data),
  });
  const trace = useQuery({
    queryKey: ['accounting-audit-trail-trace', traceTarget?.type, traceTarget?.id], enabled: canView && traceTarget !== null,
    queryFn: async (): Promise<Trace> => (await api.get(`/accounting-audit-trail/${traceTarget!.type}/${encodeURIComponent(traceTarget!.id)}`)).data,
  });
  useEffect(() => {
    if (audits.isError) toast.error('Không thể tải audit trail. Kiểm tra quyền và kết nối máy chủ rồi thử lại.');
  }, [audits.isError]);
  useEffect(() => {
    if (trace.isError) toast.error('Không thể tải trace chứng cứ. Dữ liệu vẫn giữ read-only.');
  }, [trace.isError]);
  const columns = useMemo(() => [
    { title: 'Thời điểm', dataIndex: 'occurred_at', width: 180, render: safeTime },
    { title: 'Hành động', dataIndex: 'action', width: 210, render: (value: string) => <Tag>{value}</Tag> },
    { title: 'Chứng từ / thực thể', key: 'entity', render: (_: unknown, item: AuditEvent) => <><div>{entityLabel(item.entity_type)}</div><Typography.Text type="secondary">#{item.entity_id}</Typography.Text></> },
    { title: 'Người thực hiện', dataIndex: 'actor_id', width: 125, render: (value: number | null) => value ? `#${value}` : 'Hệ thống' },
    { title: 'Correlation ID', dataIndex: 'correlation_id', width: 230, render: (value: string | null) => value ? <Typography.Text copyable={{ text: value }} ellipsis={{ tooltip: value }}>{value}</Typography.Text> : '—' },
    { title: 'Trace', width: 78, render: (_: unknown, item: AuditEvent) => {
      const type = entityKeyFromMorph(item.entity_type);
      return type ? <Button aria-label={`Xem trace ${item.id}`} type="text" icon={<EyeOutlined />} onClick={() => setTraceTarget({ type, id: item.entity_id })} /> : <Typography.Text type="secondary">—</Typography.Text>;
    } },
  ], []);

  if (!canView) return <PageShell title={<PageHeader eyebrow="Thiết lập" title="Truy vết kiểm toán kế toán" description="Tra cứu chứng cứ nghiệp vụ theo tenant; chỉ đọc." />}><div className="misa-empty-state" role="note"><strong>Chỉ admin được xem log kiểm toán</strong><div>Bạn không có quyền xem audit trail</div><Typography.Text type="secondary">Cần vai trò admin và quyền {VIEW_PERMISSION}. Hệ thống không tải hoặc suy đoán dữ liệu khi chưa có quyền.</Typography.Text></div></PageShell>;

  const apply = (values: Filters) => { setFilters(values); setPage(1); };
  return <PageShell title={<PageHeader eyebrow="Thiết lập" title="Truy vết kiểm toán kế toán" description="Tra cứu chứng cứ nghiệp vụ theo tenant; chỉ đọc." />} toolbar={<PageToolbar actions={<Button icon={<ReloadOutlined />} onClick={() => void audits.refetch()}>Tải lại</Button>} />}>
    <Alert className="mb-4" type="warning" showIcon title="Dữ liệu truy vết là chứng cứ tham chiếu, không phải kết luận kiểm toán" description="Màn hình chỉ hiển thị liên kết do máy chủ xác nhận. Payload gốc, thông tin xác thực, chữ ký, IP và nội dung nhạy cảm không được hiển thị. Quyền truy cập và kết quả đối chiếu cuối cùng vẫn do máy chủ và hồ sơ kiểm toán quyết định." />
    <Form form={form} layout="vertical" onFinish={apply} initialValues={filters}>
      <div className="grid grid-cols-1 md:grid-cols-3 gap-3"><Form.Item name="entity_type" label="Loại chứng từ / thực thể"><Select allowClear placeholder="Tất cả trong phạm vi hỗ trợ" options={ENTITY_OPTIONS.map(([value, label]) => ({ value, label }))} /></Form.Item><Form.Item name="entity_id" label="ID chứng từ"><Input placeholder="ID chính xác" /></Form.Item><Form.Item name="action" label="Hành động"><Input placeholder="Ví dụ: purchase_invoice.posted" /></Form.Item><Form.Item name="correlation_id" label="Correlation ID"><Input placeholder="ID liên kết giao dịch" /></Form.Item><Form.Item name="from_date" label="Từ ngày"><Input type="date" /></Form.Item><Form.Item name="to_date" label="Đến ngày"><Input type="date" /></Form.Item></div>
      <Space className="mb-4"><Button type="primary" htmlType="submit" icon={<SearchOutlined />}>Tra cứu</Button><Button onClick={() => { form.resetFields(); setFilters({}); setPage(1); }}>Xóa bộ lọc</Button></Space>
    </Form>
    {audits.isError ? <div className="misa-inline-status misa-inline-status-error" role="status"><span>Không có dữ liệu thay thế. Hãy kiểm tra quyền, bộ lọc và kết nối máy chủ.</span><Button size="small" onClick={() => void audits.refetch()}>Thử lại audit trail</Button></div> : <DataTableSurface><Table rowKey="id" columns={columns} dataSource={audits.data?.data ?? []} loading={audits.isLoading} locale={{ emptyText: 'Không có dữ liệu trong phạm vi đã chọn.' }} pagination={{ current: page, pageSize: audits.data?.meta.per_page ?? 25, total: audits.data?.meta.total ?? 0, showSizeChanger: false, onChange: setPage }} /></DataTableSurface>}
    <Drawer title="Trace chứng cứ kế toán" open={traceTarget !== null} onClose={() => setTraceTarget(null)} size={760} destroyOnHidden>
      {trace.isLoading && <Typography.Text>Đang tải trace…</Typography.Text>}
      {trace.isError && <div className="misa-inline-status misa-inline-status-error" role="status"><span>Trace không tồn tại, không thuộc tenant hiện tại hoặc máy chủ từ chối yêu cầu.</span><Button size="small" onClick={() => void trace.refetch()}>Thử lại trace</Button></div>}
      {trace.data && <>
        <Descriptions bordered size="small" column={1} className="mb-4">{Object.entries(trace.data.entity).map(([key, value]) => <Descriptions.Item key={key} label={key}>{String(value)}</Descriptions.Item>)}</Descriptions>
        <Typography.Title level={5}>Phê duyệt</Typography.Title><Table size="small" rowKey="id" pagination={false} dataSource={trace.data.approval_requests} columns={[{ title: 'ID', dataIndex: 'id' }, { title: 'Luồng', dataIndex: 'approval_key' }, { title: 'Trạng thái', dataIndex: 'status' }, { title: 'Yêu cầu', dataIndex: 'requested_at', render: safeTime }, { title: 'Quyết định', dataIndex: 'resolved_at', render: safeTime }]} />
        <Typography.Title level={5} className="mt-5">Bút toán liên kết</Typography.Title><pre className="misa-audit-safe-json">{serializeSafe(trace.data.journal_entries)}</pre>
        <Typography.Title level={5}>Sự kiện đã lọc nhạy cảm</Typography.Title><Table size="small" rowKey="id" pagination={false} dataSource={trace.data.events} columns={[{ title: 'Thời điểm', dataIndex: 'occurred_at', render: safeTime }, { title: 'Hành động', dataIndex: 'action' }, { title: 'Actor', dataIndex: 'actor_id', render: (value: number | null) => value ? `#${value}` : 'Hệ thống' }, { title: 'Metadata đã lọc', dataIndex: 'metadata', render: (value: Record<string, unknown>) => <Typography.Text ellipsis={{ tooltip: serializeSafe(value) }}>{serializeSafe(value)}</Typography.Text> }]} />
      </>}
    </Drawer>
 </PageShell>;
};

export default AccountingAuditTrailExplorer;
