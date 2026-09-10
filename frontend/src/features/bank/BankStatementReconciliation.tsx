import React, { useMemo, useState } from 'react';
import { Alert, Button, Drawer, Form, Input, Select, Space, Table, Tag, Typography } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import { CheckOutlined, EyeOutlined, ReloadOutlined, SafetyCertificateOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { useAuthStore } from '../../store/useAuthStore';
import { formatExactAmount, reconciliationStatusLabel, statusColor, type ReconciliationStatus } from './bankReconciliationHelpers';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

const VIEW = 'bank.reconciliations.view';
const PROPOSE = 'bank.reconciliations.propose';
const DECIDE = 'bank.reconciliations.decide';
const EXCEPTION = 'bank.reconciliations.exceptions.record';
const EMPTY_PERMISSIONS: string[] = [];

interface BankAccount { id: number; account_number: string; bank_name: string; currency: string }
interface StatementImport { uuid: string; statement_reference: string; source_format: string; status: string; line_count: number; imported_at: string | null; imported_by: number | null; bank_account: BankAccount | null }
interface StatementLine { uuid: string; line_number: number; line_reference: string; booked_on: string | null; value_on: string | null; direction: 'debit' | 'credit'; amount_raw: string; amount_scale: number; currency_code: string; running_balance_raw: string | null; running_balance_scale: number | null; bank_reference: string | null; counterparty_name: string | null; counterparty_account: string | null; description: string | null; reconciliation_status: ReconciliationStatus; latest_match_event_id: number | null; latest_exception_key: string | null; raw_payload_available: false }
interface MatchEvent { id: number; uuid: string; supersedes_event_id: number | null; candidate_type: 'bank_receipt' | 'bank_payment'; candidate_id: number; decision: 'proposed' | 'confirmed' | 'rejected' | 'reversed'; amount_raw: string; amount_scale: number; reason: string | null; recorded_by: number | null; recorded_at: string | null }
interface ExceptionEvent { uuid: string; exception_key: string; exception_code: string; event_type: 'opened' | 'resolved' | 'waived' | 'reopened'; severity: 'info' | 'warning' | 'blocking'; reason: string | null; evidence_present: boolean; recorded_by: number | null; recorded_at: string | null }
interface Timeline { line: StatementLine; match_events: MatchEvent[]; exception_events: ExceptionEvent[]; read_only_boundary: string }
interface ImportsResponse { data: StatementImport[] }
interface LinesResponse { data: StatementLine[]; meta: { import: StatementImport } }

function parseImportsResponse(value: unknown): ImportsResponse {
  if (!value || typeof value !== 'object' || !Array.isArray((value as { data?: unknown }).data)) {
    throw new Error('Invalid bank-reconciliation imports response');
  }
  return value as ImportsResponse;
}

function parseLinesResponse(value: unknown): LinesResponse {
  if (!value || typeof value !== 'object' || !Array.isArray((value as { data?: unknown }).data)) {
    throw new Error('Invalid bank-reconciliation lines response');
  }
  return value as LinesResponse;
}

function time(value: string | null): string { return value ? new Date(value).toLocaleString('vi-VN') : '—'; }

function hasPersistedEvidence(response: any): boolean {
  const evidence = response?.data?.data ?? response?.data;
  return Boolean(evidence && (evidence.id !== undefined && evidence.id !== null || evidence.uuid));
}

const BankStatementReconciliation: React.FC = () => {
  const permissions = useAuthStore((state) => state.user?.permissions ?? EMPTY_PERMISSIONS);
  const currentUserId = useAuthStore((state) => state.user?.id);
  const canView = permissions.includes(VIEW);
  const canPropose = permissions.includes(PROPOSE);
  const canDecide = permissions.includes(DECIDE);
  const canRecordException = permissions.includes(EXCEPTION);
  const queryClient = useQueryClient();
  const [selectedImport, setSelectedImport] = useState<string | null>(null);
  const [status, setStatus] = useState<ReconciliationStatus | undefined>();
  const [line, setLine] = useState<StatementLine | null>(null);
  const [decision, setDecision] = useState<MatchEvent | null>(null);
  const [proposalForm] = Form.useForm();
  const [exceptionForm] = Form.useForm();
  const [decisionForm] = Form.useForm();

  const imports = useQuery({ queryKey: ['bank-reconciliation-imports'], enabled: canView, queryFn: async (): Promise<ImportsResponse> => parseImportsResponse((await api.get('/bank/reconciliation/imports')).data) });
  const lines = useQuery({ queryKey: ['bank-reconciliation-lines', selectedImport, status], enabled: canView && selectedImport !== null, queryFn: async (): Promise<LinesResponse> => parseLinesResponse((await api.get(`/bank/reconciliation/imports/${selectedImport}/lines`, { params: status ? { status } : undefined })).data) });
  const timeline = useQuery({ queryKey: ['bank-reconciliation-timeline', line?.uuid], enabled: canView && line !== null, queryFn: async (): Promise<{ data: Timeline }> => (await api.get(`/bank/reconciliation/lines/${line!.uuid}/timeline`)).data });
  const refreshEvidence = async () => { await queryClient.invalidateQueries({ queryKey: ['bank-reconciliation-lines'] }); await queryClient.invalidateQueries({ queryKey: ['bank-reconciliation-timeline'] }); };
  const propose = useMutation({ mutationFn: async (values: { candidate_type: string; candidate_id: number; reason?: string }) => api.post(`/bank/reconciliation/lines/${line!.uuid}/matches`, values), onSuccess: async (response) => { if (!hasPersistedEvidence(response)) { message.error('Máy chủ không trả về sự kiện đề xuất đã lưu; không thể báo thành công.'); return; } message.success('Đã ghi nhận đề xuất ghép để người khác kiểm tra.'); proposalForm.resetFields(); await refreshEvidence(); }, onError: () => message.error('Máy chủ từ chối đề xuất. Hãy kiểm tra quyền, chứng từ và điều kiện đối chiếu.') });
  const decide = useMutation({ mutationFn: async (values: { decision: string; reason: string }) => api.post(`/bank/reconciliation/matches/${decision!.id}/decisions`, values), onSuccess: async (response) => { if (!hasPersistedEvidence(response)) { message.error('Máy chủ không trả về quyết định đối chiếu đã lưu; không thể báo thành công.'); return; } message.success('Đã ghi nhận quyết định đối chiếu.'); setDecision(null); decisionForm.resetFields(); await refreshEvidence(); }, onError: () => message.error('Máy chủ từ chối quyết định; maker-checker được kiểm tra lại phía máy chủ.') });
  const exception = useMutation({ mutationFn: async (values: { exception_key: string; event_type: string; exception_code: string; severity: string; reason?: string }) => api.post(`/bank/reconciliation/exceptions/${encodeURIComponent(values.exception_key)}/events`, { ...values, line_uuid: line?.uuid }), onSuccess: async (response) => { if (!hasPersistedEvidence(response)) { message.error('Máy chủ không trả về sự kiện ngoại lệ đã lưu; không thể báo thành công.'); return; } message.success('Đã ghi nhận sự kiện ngoại lệ; dữ liệu chứng cứ không bị sửa.'); exceptionForm.resetFields(); await refreshEvidence(); }, onError: () => message.error('Máy chủ từ chối sự kiện ngoại lệ. Kiểm tra quyền và lifecycle hiện tại.') });

  const columns = useMemo(() => [
    { title: 'Dòng', dataIndex: 'line_number', width: 62 },
    { title: 'Ngày hạch toán', dataIndex: 'booked_on', width: 120, render: (v: string | null) => v ?? '—' },
    { title: 'Tham chiếu ngân hàng', dataIndex: 'bank_reference', width: 170, render: (v: string | null, record: StatementLine) => v ?? record.line_reference },
    { title: 'Chiều', dataIndex: 'direction', width: 90, render: (v: string) => <Tag color={v === 'credit' ? 'green' : 'volcano'}>{v === 'credit' ? 'Báo Có' : 'Báo Nợ'}</Tag> },
    { title: 'Số tiền', key: 'amount', align: 'right' as const, width: 165, render: (_: unknown, record: StatementLine) => <Typography.Text strong>{formatExactAmount(record.amount_raw, record.amount_scale, record.currency_code)}</Typography.Text> },
    { title: 'Đối tượng / nội dung', key: 'description', render: (_: unknown, record: StatementLine) => <><div>{record.counterparty_name ?? '—'}</div><Typography.Text type="secondary" ellipsis={{ tooltip: record.description ?? undefined }}>{record.description ?? '—'}</Typography.Text></> },
    { title: 'Trạng thái', dataIndex: 'reconciliation_status', width: 145, render: (v: ReconciliationStatus) => <Tag color={statusColor(v)}>{reconciliationStatusLabel(v)}</Tag> },
    { title: 'Chi tiết', width: 82, render: (_: unknown, record: StatementLine) => <Button aria-label={`Xem dòng ${record.line_number}`} type="text" icon={<EyeOutlined />} onClick={() => setLine(record)} /> },
  ], []);

  if (!canView) return <PageShell title={<PageHeader eyebrow="Ngân hàng" title="Đối chiếu sao kê ngân hàng" description="Kiểm tra chứng cứ đối chiếu theo tenant và maker-checker." />}><Alert type="warning" showIcon message="Bạn không có quyền xem đối chiếu sao kê" description={`Cần quyền ${VIEW}. Màn hình không tải hoặc suy đoán dữ liệu nếu chưa được máy chủ cấp quyền.`} /></PageShell>;
  const importRows = imports.data?.data ?? [];
  const selected = importRows.find((item) => item.uuid === selectedImport);
  return <PageShell title={<PageHeader eyebrow="Ngân hàng" title="Đối chiếu sao kê ngân hàng" description="Chứng cứ đối chiếu theo tenant và maker-checker." />}>
    <PageToolbar actions={<Button icon={<ReloadOutlined />} onClick={() => void imports.refetch()}>Tải lại</Button>} />
    <Alert className="mb-4" type="warning" showIcon message="Boundary nghiệp vụ: chỉ là chứng cứ đối chiếu" description="Màn hình không tự ghi sổ, không tạo bút toán, không cập nhật số dư ngân hàng và không cấp quyền đóng kỳ. Mọi đề xuất/quyết định đều được máy chủ kiểm tra lại quyền, tenant, số tiền chính xác và maker-checker." />
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-4"><div className="lg:col-span-1"><Typography.Title level={5}>Lần nhận sao kê</Typography.Title>{imports.isError && <Alert type="error" showIcon message="Không tải được sao kê" />}{importRows.map((item) => <Button key={item.uuid} block className="mb-2" type={selectedImport === item.uuid ? 'primary' : 'default'} onClick={() => { setSelectedImport(item.uuid); setStatus(undefined); }}><div className="text-left"><div>{item.statement_reference}</div><Typography.Text type="secondary">{item.bank_account ? `${item.bank_account.bank_name} · ${item.bank_account.account_number}` : 'Tài khoản ngân hàng'} · {item.line_count} dòng</Typography.Text></div></Button>)}</div><div className="lg:col-span-2"><Space className="mb-3" wrap><Typography.Title level={5} className="!mb-0">Dòng sao kê{selected ? ` · ${selected.statement_reference}` : ''}</Typography.Title><Select aria-label="Lọc trạng thái đối chiếu" allowClear value={status} placeholder="Tất cả trạng thái" style={{ minWidth: 170 }} onChange={setStatus} options={[['unmatched', 'Chưa ghép'], ['proposed', 'Chờ kiểm tra'], ['confirmed', 'Đã xác nhận'], ['exception_open', 'Ngoại lệ đang mở']].map(([value, label]) => ({ value, label }))} /></Space>{!selectedImport ? <Typography.Text type="secondary">Chưa chọn lần nhận sao kê.</Typography.Text> : lines.isError ? <Alert type="error" showIcon message="Không thể tải dòng sao kê" description="Không có dữ liệu thay thế hoặc số dư ước tính." /> : <DataTableSurface className="bank-reconciliation-table-surface"><Table rowKey="uuid" className="misa-voucher-table" size="small" columns={columns} dataSource={lines.data?.data ?? []} loading={lines.isLoading} pagination={{ pageSize: 15, showSizeChanger: false }} /></DataTableSurface>}</div></div>
    <Drawer title="Timeline chứng cứ đối chiếu" open={line !== null} onClose={() => setLine(null)} size={820} destroyOnHidden>{timeline.isLoading && <Typography.Text>Đang tải chứng cứ…</Typography.Text>}{timeline.isError && <Alert type="error" showIcon message="Không thể tải timeline" description="Dòng không thuộc tenant hiện tại hoặc máy chủ từ chối yêu cầu." />}{timeline.data?.data && <><Typography.Text className="misa-inline-note" type="secondary">{timeline.data.data.read_only_boundary}</Typography.Text><Typography.Title level={5}>Dòng sao kê</Typography.Title><Typography.Paragraph><b>{timeline.data.data.line.booked_on}</b> · {timeline.data.data.line.bank_reference ?? timeline.data.data.line.line_reference} · <b>{formatExactAmount(timeline.data.data.line.amount_raw, timeline.data.data.line.amount_scale, timeline.data.data.line.currency_code)}</b></Typography.Paragraph><Typography.Text type="secondary">Payload gốc: không công bố trên giao diện vận hành.</Typography.Text><Typography.Title level={5} className="mt-5">Sự kiện ghép chứng từ</Typography.Title><Table size="small" pagination={false} rowKey="id" dataSource={timeline.data.data.match_events} columns={[{ title: 'Quyết định', dataIndex: 'decision', render: (value: string) => <Tag>{value}</Tag> }, { title: 'Ứng viên', key: 'candidate', render: (_: unknown, event: MatchEvent) => `${event.candidate_type} #${event.candidate_id}` }, { title: 'Người ghi', dataIndex: 'recorded_by', render: (value: number | null) => value ? `#${value}` : '—' }, { title: 'Thời điểm', dataIndex: 'recorded_at', render: time }, { title: '', key: 'action', render: (_: unknown, event: MatchEvent) => canDecide && event.decision === 'proposed' && event.recorded_by !== currentUserId ? <Button size="small" icon={<CheckOutlined />} onClick={() => setDecision(event)}>Kiểm tra</Button> : null }]} /><Typography.Title level={5} className="mt-5">Sự kiện ngoại lệ</Typography.Title><Table size="small" pagination={false} rowKey="uuid" dataSource={timeline.data.data.exception_events} columns={[{ title: 'Mã', dataIndex: 'exception_code' }, { title: 'Sự kiện', dataIndex: 'event_type' }, { title: 'Mức độ', dataIndex: 'severity', render: (value: string) => <Tag color={value === 'blocking' ? 'error' : value === 'warning' ? 'warning' : 'default'}>{value}</Tag> }, { title: 'Người ghi', dataIndex: 'recorded_by', render: (value: number | null) => value ? `#${value}` : '—' }, { title: 'Thời điểm', dataIndex: 'recorded_at', render: time }]} />
      {(canPropose || canRecordException) && <div className="mt-5 grid grid-cols-1 lg:grid-cols-2 gap-4">{canPropose && <div><Typography.Title level={5}>Đề xuất ghép chứng từ</Typography.Title><Form form={proposalForm} layout="vertical" onFinish={(values) => propose.mutate(values)}><Form.Item name="candidate_type" label="Loại chứng từ" rules={[{ required: true }]}><Select options={[{ value: 'bank_receipt', label: 'Thu tiền gửi' }, { value: 'bank_payment', label: 'Chi tiền gửi' }]} /></Form.Item><Form.Item name="candidate_id" label="ID chứng từ đã ghi sổ" rules={[{ required: true }]}><Input type="number" min={1} /></Form.Item><Form.Item name="reason" label="Lý do"><Input.TextArea maxLength={2000} /></Form.Item><Button type="primary" htmlType="submit" loading={propose.isPending}>Ghi đề xuất</Button></Form></div>}{canRecordException && <div><Typography.Title level={5}>Ghi nhận ngoại lệ</Typography.Title><Form form={exceptionForm} layout="vertical" onFinish={(values) => exception.mutate(values)}><Form.Item name="exception_key" label="Khóa ngoại lệ" initialValue={timeline.data.data.line.latest_exception_key ?? `bank-line-${timeline.data.data.line.uuid}`} rules={[{ required: true }]}><Input /></Form.Item><Form.Item name="event_type" label="Sự kiện" initialValue="opened" rules={[{ required: true }]}><Select options={[['opened', 'Mở'], ['resolved', 'Đã xử lý'], ['waived', 'Miễn trừ'], ['reopened', 'Mở lại']].map(([value, label]) => ({ value, label }))} /></Form.Item><Form.Item name="exception_code" label="Mã ngoại lệ" initialValue="UNMATCHED_BANK_LINE" rules={[{ required: true }]}><Input /></Form.Item><Form.Item name="severity" label="Mức độ" initialValue="warning" rules={[{ required: true }]}><Select options={['info', 'warning', 'blocking'].map((value) => ({ value, label: value }))} /></Form.Item><Form.Item name="reason" label="Lý do"><Input.TextArea maxLength={2000} /></Form.Item><Button icon={<SafetyCertificateOutlined />} htmlType="submit" loading={exception.isPending}>Ghi sự kiện</Button></Form></div>}</div>}</>}</Drawer>
    <Modal title="Quyết định đề xuất ghép" open={decision !== null} onCancel={() => setDecision(null)} footer={null} destroyOnHidden><Alert className="mb-4" type="warning" showIcon message="Maker-checker" description="Bạn không thể kiểm tra đề xuất do chính mình tạo. Máy chủ kiểm tra lại điều kiện này." /><Form form={decisionForm} layout="vertical" onFinish={(values) => decide.mutate(values)}><Form.Item name="decision" label="Quyết định" rules={[{ required: true }]}><Select options={[['confirmed', 'Xác nhận'], ['rejected', 'Từ chối'], ['reversed', 'Đảo quyết định']].map(([value, label]) => ({ value, label }))} /></Form.Item><Form.Item name="reason" label="Lý do" rules={[{ required: true }]}><Input.TextArea maxLength={2000} /></Form.Item><Button type="primary" htmlType="submit" loading={decide.isPending}>Lưu quyết định</Button></Form></Modal>
  </PageShell>;
};

export default BankStatementReconciliation;
