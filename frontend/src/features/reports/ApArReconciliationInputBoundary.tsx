import React, { useState } from 'react';
import { Alert, Button, Card, Descriptions, Drawer, Empty, Form, Input, List, Select, Space, Spin, Table, Tag, Typography } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { EyeOutlined, LockOutlined, ReloadOutlined, SafetyCertificateOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { useAuthStore } from '../../store/useAuthStore';
import { canRequestCapture, inputBoundaryStatusLabel, ledgerLabel, safeJson, type ApArInputBoundaryRun, type ApArLedger, type ApArPartyRollforward } from './apArInputBoundaryHelpers';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

const VIEW_PERMISSION = 'apar.reconciliations.view';
const CAPTURE_PERMISSION = 'apar.reconciliations.capture';

interface PageResponse { data: ApArInputBoundaryRun[]; meta?: { current_page?: number; per_page?: number; total?: number }; }
interface CaptureInput { ledger: ApArLedger; as_of_date: string; }

function parseRun(value: unknown): ApArInputBoundaryRun {
  if (!value || typeof value !== 'object') throw new Error('Invalid AP/AR input-boundary response.');
  const run = value as Record<string, unknown>;
  const amounts = parseAmounts(run.amounts);
  if (typeof run.uuid !== 'string' || run.uuid.trim() === '' || !['ap', 'ar'].includes(String(run.ledger))
    || typeof run.status !== 'string' || typeof run.algorithm_version !== 'string'
    || (typeof run.as_of_date !== 'string' && run.as_of_date !== null)
    || (typeof run.input_cutoff_at !== 'string' && run.input_cutoff_at !== null)
    || (typeof run.snapshot_hash !== 'string' && run.snapshot_hash !== null)
    || typeof run.amounts_calculated !== 'boolean' || typeof run.tie_out_calculated !== 'boolean'
    || typeof run.close_authority !== 'boolean' || typeof run.limitation !== 'string') {
    throw new Error('AP/AR input-boundary evidence is incomplete or malformed.');
  }
  return { ...run, amounts } as unknown as ApArInputBoundaryRun;
}

function parseAmounts(value: unknown): ApArInputBoundaryRun['amounts'] {
  if (value === null) return null;
  if (typeof value !== 'object' || Array.isArray(value)) throw new Error('AP/AR calculated amounts are malformed.');
  const amounts = value as Record<string, unknown>;
  if (amounts.party_rollforward === undefined) return amounts as ApArInputBoundaryRun['amounts'];
  if (!Array.isArray(amounts.party_rollforward)) throw new Error('AP/AR party roll-forward is malformed.');
  const partyRollforward = amounts.party_rollforward.map((row) => {
    if (!row || typeof row !== 'object' || Array.isArray(row)) throw new Error('AP/AR party roll-forward row is malformed.');
    const item = row as Record<string, unknown>;
    const moneyFields = ['opening_balance', 'invoice_balance', 'settlement_reduction', 'settlement_reversal', 'ending_balance'];
    if (typeof item.party_type !== 'string' || item.party_type.trim() === '' || typeof item.party_id !== 'number' || !Number.isInteger(item.party_id) || item.party_id < 1
      || moneyFields.some((field) => typeof item[field] !== 'string')) {
      throw new Error('AP/AR party roll-forward row is incomplete.');
    }
    return item as unknown as ApArPartyRollforward;
  });
  return { ...amounts, party_rollforward: partyRollforward } as ApArInputBoundaryRun['amounts'];
}

function parsePage(value: unknown): PageResponse {
  if (!value || typeof value !== 'object' || !Array.isArray((value as { data?: unknown }).data)) {
    throw new Error('Invalid AP/AR input-boundary list response.');
  }
  const response = value as { data: unknown[]; meta?: PageResponse['meta'] };
  return { data: response.data.map(parseRun), meta: response.meta };
}

/**
 * Operational surface for immutable AP/AR reconciliation evidence. The
 * server may publish a controlled tie-out only after an approved contract;
 * the client never computes or authorizes the result.
 */
const ApArReconciliationInputBoundary: React.FC = () => {
  const permissions = useAuthStore((state) => state.user?.permissions);
  const canView = permissions?.includes(VIEW_PERMISSION) ?? false;
  const canCapture = canRequestCapture(permissions);
  const client = useQueryClient();
  const [form] = Form.useForm<CaptureInput>();
  const [selectedUuid, setSelectedUuid] = useState<string | null>(null);

  const runs = useQuery({
    queryKey: ['ap-ar-reconciliation-input-boundaries'],
    enabled: canView,
    queryFn: async (): Promise<PageResponse> => parsePage((await api.get('/ap-ar/reconciliation-input-boundaries', { params: { per_page: 25 } })).data),
  });
  const evidence = useQuery({
    queryKey: ['ap-ar-reconciliation-input-boundary', selectedUuid],
    enabled: canView && selectedUuid !== null,
    queryFn: async (): Promise<ApArInputBoundaryRun> => parseRun((await api.get(`/ap-ar/reconciliation-input-boundaries/${encodeURIComponent(selectedUuid!)}`)).data.data),
  });
  const capture = useMutation({
    mutationFn: async (input: CaptureInput): Promise<ApArInputBoundaryRun> => parseRun((await api.post('/ap-ar/reconciliation-input-boundaries', input)).data.data),
    onSuccess: async (run) => {
      message.success(run.amounts_calculated ? 'Đã hoàn tất đối chiếu AP/AR theo cấu hình đã duyệt.' : 'Đã yêu cầu máy chủ capture bằng chứng cutoff bất biến. Chưa có số dư hoặc kết luận đối chiếu.');
      form.resetFields();
      setSelectedUuid(run.uuid);
      await client.invalidateQueries({ queryKey: ['ap-ar-reconciliation-input-boundaries'] });
    },
    onError: () => message.error('Máy chủ từ chối capture. Kiểm tra quyền capture, ledger, ngày cutoff và điều kiện snapshot.'),
  });

  if (!canView) return <PageShell title={<PageHeader eyebrow="Báo cáo" title="Bằng chứng cutoff AP/AR" description="Capture-only / fail-closed." />}><Alert type="warning" showIcon message="Bạn không có quyền xem bằng chứng AP/AR" description={`Cần quyền ${VIEW_PERMISSION}. Giao diện không tải, suy đoán số dư hoặc suy đoán quyền từ tenant hiện tại.`} /></PageShell>;

  const columns = [
    { title: 'Ledger', dataIndex: 'ledger', width: 150, render: (value: ApArLedger) => ledgerLabel(value) },
    { title: 'Ngày cutoff yêu cầu', dataIndex: 'as_of_date', width: 155, render: (value: string | null) => value ?? '—' },
    { title: 'Input watermark UTC', dataIndex: 'input_cutoff_at', width: 220, render: (value: string | null) => value ?? 'Chưa capture' },
    { title: 'Trạng thái', dataIndex: 'status', width: 180, render: (value: string) => <Tag color={value === 'approved' || value === 'reconciled' ? 'green' : value === 'blocked' ? 'red' : 'orange'}>{inputBoundaryStatusLabel(value)}</Tag> },
    { title: 'Evidence', key: 'view', width: 100, render: (_: unknown, row: ApArInputBoundaryRun) => <Button aria-label={`Xem evidence ${row.uuid}`} type="text" icon={<EyeOutlined />} onClick={() => setSelectedUuid(row.uuid)} /> },
  ];

  return <PageShell title={<PageHeader eyebrow="Báo cáo" title="Đối chiếu công nợ AP/AR" description="Kết quả do máy chủ tính từ chứng từ đã ghi sổ và cấu hình được admin duyệt." />} toolbar={<PageToolbar actions={<Button icon={<ReloadOutlined />} onClick={() => void runs.refetch()} loading={runs.isFetching}>Tải lại danh sách</Button>} />}>
    <Card title={<Space><SafetyCertificateOutlined /> Đối chiếu công nợ AP/AR</Space>} extra={<Tag color="blue">Server-controlled</Tag>}>
      <Alert type="info" showIcon icon={<LockOutlined />} message="Kết quả chỉ có giá trị khi cấu hình đối chiếu đã được admin duyệt" description="Kế toán có thể yêu cầu chạy theo ngày cutoff. Máy chủ kiểm tra tenant, chứng từ, phân bổ, số dư đầu kỳ và tài khoản kiểm soát; lệch số liệu sẽ bị chặn khóa kỳ." />
      <Typography.Paragraph className="apple-muted-text misa-mt-12">Client chỉ hiển thị bằng chứng và kết quả đã ký hash; không tự tính số dư, không tự bỏ qua lỗi và không cấp quyền khóa kỳ.</Typography.Paragraph>
      {canCapture ? <Form form={form} layout="vertical" onFinish={(values) => capture.mutate(values)}>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
          <Form.Item name="ledger" label="Ledger" rules={[{ required: true, message: 'Chọn AP hoặc AR.' }]}><Select aria-label="Ledger AP AR" options={[{ value: 'ap', label: ledgerLabel('ap') }, { value: 'ar', label: ledgerLabel('ar') }]} /></Form.Item>
          <Form.Item name="as_of_date" label="Ngày cutoff" rules={[{ required: true, message: 'Chọn ngày cutoff.' }]}><Input aria-label="Ngày cutoff AP AR" type="date" /></Form.Item>
        </div>
        <Space className="misa-mb-12"><Button type="primary" htmlType="submit" loading={capture.isPending} icon={<SafetyCertificateOutlined />}>Capture bằng chứng cutoff</Button><Button onClick={() => form.resetFields()} disabled={capture.isPending}>Xóa nhập</Button></Space>
      </Form> : <Typography.Text className="misa-mb-12 block" type="secondary"><span>Chỉ có quyền xem evidence</span><span>. Cần quyền {CAPTURE_PERMISSION} để yêu cầu server capture.</span></Typography.Text>}
      {runs.isLoading && <Spin tip="Đang tải bằng chứng AP/AR từ máy chủ..." />}
      {runs.isError && <Alert type="error" showIcon message="Không thể tải bằng chứng cutoff AP/AR" description="Không có dữ liệu dự phòng. Kiểm tra quyền xem, tenant và phản hồi máy chủ." />}
      {!runs.isLoading && !runs.isError && (runs.data?.data.length ?? 0) === 0 && <Empty description="Chưa có bằng chứng cutoff AP/AR trong tenant hiện tại." image={Empty.PRESENTED_IMAGE_SIMPLE} />}
      {!runs.isError && (runs.data?.data.length ?? 0) > 0 && <DataTableSurface className="ap-ar-evidence-table-surface"><Table rowKey="uuid" columns={columns} dataSource={runs.data?.data ?? []} loading={runs.isFetching} pagination={false} /></DataTableSurface>}
    </Card>
    <Drawer title="Chi tiết bằng chứng cutoff AP/AR" open={selectedUuid !== null} onClose={() => setSelectedUuid(null)} size={760} destroyOnHidden>
      {evidence.isLoading && <Spin tip="Đang tải evidence bất biến..." />}
      {evidence.isError && <Alert type="error" showIcon message="Không thể tải bằng chứng" description="Evidence không tồn tại, không thuộc tenant hiện tại hoặc quyền truy cập đã bị máy chủ từ chối." />}
      {evidence.data && <>
        <Alert className="misa-mb-12" type={evidence.data.amounts_calculated ? (evidence.data.close_authority ? 'success' : 'error') : 'warning'} showIcon message={evidence.data.amounts_calculated ? (evidence.data.close_authority ? 'Đã đối chiếu khớp' : 'Đối chiếu bị chặn') : 'Chưa có kết quả tính số dư'} description={evidence.data.limitation} />
        <Descriptions bordered size="small" column={1}>
          <Descriptions.Item label="Ledger">{ledgerLabel(evidence.data.ledger)}</Descriptions.Item><Descriptions.Item label="Ngày cutoff">{evidence.data.as_of_date ?? '—'}</Descriptions.Item><Descriptions.Item label="Input watermark UTC">{evidence.data.input_cutoff_at ?? 'Không khả dụng'}</Descriptions.Item><Descriptions.Item label="Trạng thái">{inputBoundaryStatusLabel(evidence.data.status)}</Descriptions.Item><Descriptions.Item label="Snapshot hash"><Typography.Text copyable={{ text: evidence.data.snapshot_hash ?? '' }}>{evidence.data.snapshot_hash ?? '—'}</Typography.Text></Descriptions.Item><Descriptions.Item label="Số tiền">{evidence.data.amounts_calculated ? <pre className="misa-audit-safe-json">{safeJson(evidence.data.amounts)}</pre> : 'Chưa khả dụng'}</Descriptions.Item><Descriptions.Item label="Tie-out">{evidence.data.tie_out_calculated ? (evidence.data.amounts?.difference === '0.00' ? 'Khớp' : 'Có chênh lệch') : 'Chưa tính'}</Descriptions.Item><Descriptions.Item label="Close authority">{evidence.data.close_authority ? 'Đủ điều kiện theo kết quả này' : 'Không có'}</Descriptions.Item>
        </Descriptions>
        {evidence.data.amounts_calculated && (evidence.data.amounts?.party_rollforward?.length ?? 0) > 0 && <>
          <Typography.Title level={5} className="misa-mt-16">Chi tiết theo đối tượng</Typography.Title>
          <DataTableSurface className="ap-ar-party-rollforward-surface"><Table<ApArPartyRollforward>
            rowKey={(row) => `${row.party_type}:${row.party_id}`}
            size="small"
            pagination={false}
            dataSource={evidence.data.amounts?.party_rollforward ?? []}
            columns={[
              { title: 'Loại đối tượng', dataIndex: 'party_type', width: 150, render: (value: string) => value === 'supplier' ? 'Nhà cung cấp' : value === 'customer' ? 'Khách hàng' : value },
              { title: 'Mã đối tượng', dataIndex: 'party_id', width: 120 },
              { title: 'Số dư đầu kỳ', dataIndex: 'opening_balance', align: 'right' as const },
              { title: 'Phát sinh hóa đơn', dataIndex: 'invoice_balance', align: 'right' as const },
              { title: 'Đã phân bổ', dataIndex: 'settlement_reduction', align: 'right' as const },
              { title: 'Đảo phân bổ', dataIndex: 'settlement_reversal', align: 'right' as const },
              { title: 'Còn phải thu/trả', dataIndex: 'ending_balance', align: 'right' as const },
            ]}
          /></DataTableSurface>
        </>}
        <Typography.Title level={5} className="misa-mt-16">Input-boundary do máy chủ công bố</Typography.Title><pre className="misa-audit-safe-json">{safeJson(evidence.data.input_boundary)}</pre>
        <Typography.Title level={5}>Điều kiện / exceptions</Typography.Title>{!evidence.data.exceptions?.length ? <Empty description="Máy chủ không công bố exception chi tiết cho evidence này." image={Empty.PRESENTED_IMAGE_SIMPLE} /> : <List dataSource={evidence.data.exceptions} renderItem={(item) => <List.Item><List.Item.Meta title={<Space><Tag color={item.severity === 'blocking' ? 'red' : 'orange'}>{item.severity}</Tag><Typography.Text code>{item.code}</Typography.Text></Space>} description={item.reason} /></List.Item>} />}
      </>}
    </Drawer>
  </PageShell>;
};

export default ApArReconciliationInputBoundary;
