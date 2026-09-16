import React from 'react';
import { Button, Card, Descriptions, Empty, Form, Input, List, Space, Spin, Table, Tag, Typography } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { EyeOutlined, ReloadOutlined, SafetyCertificateOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { useAuthStore } from '../../store/useAuthStore';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import { runManualDataLoad } from '../../components/feedback/runManualDataLoad';

type Run = { uuid: string; as_of_date: string | null; status: string; algorithm_version: string; snapshot_hash: string | null; input_cutoff_at?: string | null; amounts: Record<string, string | number> | null; amounts_calculated: boolean; tie_out_calculated: boolean; close_authority: boolean; limitation: string; exceptions?: Array<{ code: string; severity: string; reason: string }> | null };
const VIEW = 'inventory.reconciliations.view';
const CAPTURE = 'inventory.reconciliations.capture';

function parseRun(value: unknown): Run {
  if (!value || typeof value !== 'object') throw new Error('Phản hồi đối chiếu kho không hợp lệ.');
  const run = value as Record<string, unknown>;
  if (typeof run.uuid !== 'string' || typeof run.status !== 'string' || (run.amounts !== null && (typeof run.amounts !== 'object' || Array.isArray(run.amounts))) || typeof run.amounts_calculated !== 'boolean' || typeof run.tie_out_calculated !== 'boolean' || typeof run.close_authority !== 'boolean' || typeof run.limitation !== 'string') throw new Error('Phản hồi đối chiếu kho thiếu bằng chứng bắt buộc.');
  return run as unknown as Run;
}

const InventorySubledgerGlReconciliation: React.FC = () => {
  const permissions = useAuthStore((state) => state.user?.permissions);
  const canView = permissions?.includes(VIEW) ?? false;
  const canCapture = permissions?.includes(CAPTURE) ?? false;
  const [form] = Form.useForm<{ as_of_date: string }>();
  const [selected, setSelected] = React.useState<Run | null>(null);
  const client = useQueryClient();
  const runs = useQuery({ queryKey: ['inventory-subledger-gl-reconciliations'], enabled: canView, queryFn: async (): Promise<Run[]> => { const response = (await api.get('/inventory/subledger-gl-reconciliations', { params: { per_page: 25 } })).data; if (!response || !Array.isArray(response.data)) throw new Error('Danh sách đối chiếu kho không hợp lệ.'); return response.data.map(parseRun); } });
  const capture = useMutation({ mutationFn: async (input: { as_of_date: string }) => parseRun((await api.post('/inventory/subledger-gl-reconciliations', input)).data.data), onSuccess: async (run) => { message.success(run.amounts_calculated ? 'Đã hoàn tất đối chiếu tồn kho với sổ cái.' : 'Đã lưu bằng chứng cutoff; chưa thể tính đối chiếu.'); setSelected(run); form.resetFields(); await client.invalidateQueries({ queryKey: ['inventory-subledger-gl-reconciliations'] }); }, onError: () => message.error('Máy chủ từ chối đối chiếu kho. Kiểm tra quyền và cấu hình đã duyệt.') });

  if (!canView) return <PageShell title={<PageHeader eyebrow="Báo cáo" title="Đối chiếu tồn kho – sổ cái" description="Server-controlled / fail-closed." />}><div role="alert" className="misa-p-12 mb-4" style={{ background: '#FFFBEB', border: '1px solid #FDE68A', borderRadius: 8 }}><div style={{ fontWeight: 600, color: '#92400E', fontSize: 13 }}>Bạn không có quyền xem đối chiếu tồn kho</div><div style={{ color: '#B45309', fontSize: 12, marginTop: 4 }}>{`Cần quyền ${VIEW}.`}</div></div></PageShell>;
  const columns = [
    { title: 'Ngày cutoff', dataIndex: 'as_of_date', width: 150 },
    { title: 'Trạng thái', dataIndex: 'status', width: 190, render: (value: string) => (
      <span className={`misa-apple-pill ${value === 'approved' || value === 'reconciled' ? 'misa-apple-pill-green' : value === 'blocked' ? 'misa-apple-pill-red' : 'misa-apple-pill-orange'}`}>
        <span className="misa-apple-pill-dot" />
        {value === 'approved' || value === 'reconciled' ? 'Đã đối chiếu' : value === 'blocked' ? 'Bị chặn – cần xử lý' : 'Chưa khả dụng'}
      </span>
    ) },
    { title: 'Chênh lệch', key: 'difference', render: (_: unknown, row: Run) => row.amounts_calculated ? String(row.amounts?.difference ?? '—') : '—' },
    { title: 'Chức năng', key: 'view', width: 100, align: 'center' as const, render: (_: unknown, row: Run) => <Button type="text" icon={<EyeOutlined />} aria-label={`Xem đối chiếu ${row.uuid}`} onClick={() => setSelected(row)} /> },
  ];
  return <PageShell title={<PageHeader eyebrow="Báo cáo" title="Đối chiếu tồn kho – sổ cái" description="Giá trị tồn kho được đối chiếu từ nguồn nhập, xuất, đầu kỳ và điều chuyển đã tính giá." />} toolbar={<PageToolbar actions={<Button icon={<ReloadOutlined />} onClick={() => void runManualDataLoad(() => runs.refetch(), { success: 'Tải lại đối chiếu tồn kho thành công.', failure: 'Không thể tải lại đối chiếu tồn kho.' })} loading={runs.isFetching}>Tải lại</Button>} />}>
    <Card title={<Space><SafetyCertificateOutlined /> Đối chiếu tồn kho – sổ cái</Space>} extra={<Tag color="blue">Server-controlled</Tag>}>
      <div className="misa-p-12 misa-mb-12" style={{ background: '#EBF5FF', border: '1px solid #BFDBFE', borderRadius: 8 }}><div style={{ fontWeight: 600, color: 'var(--ui-primary, #0064E0)', fontSize: 13 }}>Chỉ chạy khi policy tồn kho đã được admin duyệt</div><div style={{ color: '#1C1E21', fontSize: 12, marginTop: 2 }}>Kết quả kiểm tra tenant, nguồn biến động, giá bình quân, tài khoản kiểm soát và số dư đầu kỳ. Chênh lệch hoặc giá chưa chốt sẽ chặn khóa kỳ.</div></div>
      {canCapture && <Form form={form} layout="inline" className="misa-mt-16" onFinish={(values) => capture.mutate(values)}><Form.Item name="as_of_date" label="Ngày cutoff" rules={[{ required: true, message: 'Chọn ngày cutoff.' }]}><Input type="date" aria-label="Ngày cutoff đối chiếu tồn kho" /></Form.Item><Button type="primary" htmlType="submit" loading={capture.isPending}>Chạy đối chiếu</Button></Form>}
      {!canCapture && <Typography.Text type="secondary">Bạn chỉ có quyền xem; cần {CAPTURE} để chạy đối chiếu.</Typography.Text>}
      {runs.isLoading && <Spin tip="Đang tải kết quả đối chiếu..." />}
      {runs.isError && <div role="alert" className="misa-p-12 mb-4" style={{ background: '#FEF2F2', border: '1px solid #FECACA', borderRadius: 8 }}><div style={{ fontWeight: 600, color: '#991B1B', fontSize: 13 }}>Không thể tải đối chiếu tồn kho</div><div style={{ color: '#B91C1C', fontSize: 12, marginTop: 2 }}>Kiểm tra tenant, quyền và phản hồi máy chủ.</div></div>}
      {!runs.isLoading && !runs.isError && runs.data?.length === 0 && <Empty description="Chưa có lần đối chiếu nào." image={Empty.PRESENTED_IMAGE_SIMPLE} />}
      {!!runs.data?.length && <DataTableSurface><Table rowKey="uuid" columns={columns} dataSource={runs.data} loading={runs.isFetching} pagination={false} /></DataTableSurface>}
    </Card>
    {selected && <Card className="misa-mt-16" title="Chi tiết lần đối chiếu" extra={<Button onClick={() => setSelected(null)}>Đóng</Button>}><Descriptions bordered size="small" column={1}><Descriptions.Item label="Ngày cutoff">{selected.as_of_date ?? '—'}</Descriptions.Item><Descriptions.Item label="Watermark dữ liệu UTC">{selected.input_cutoff_at ?? 'Không khả dụng'}</Descriptions.Item><Descriptions.Item label="Trạng thái">{selected.status}</Descriptions.Item><Descriptions.Item label="Snapshot hash">{selected.snapshot_hash ?? '—'}</Descriptions.Item><Descriptions.Item label="Giá trị đối chiếu">{selected.amounts_calculated ? <pre className="misa-audit-safe-json">{JSON.stringify(selected.amounts, null, 2)}</pre> : 'Chưa khả dụng'}</Descriptions.Item><Descriptions.Item label="Đủ điều kiện khóa kỳ">{selected.close_authority ? 'Có' : 'Không'}</Descriptions.Item></Descriptions><div role="alert" className="misa-mt-12 misa-p-12" style={{ background: selected.close_authority ? '#EBF5FF' : '#FFFBEB', border: `1px solid ${selected.close_authority ? '#BFDBFE' : '#FDE68A'}`, borderRadius: 8 }}><div style={{ fontWeight: 600, fontSize: 13, color: selected.close_authority ? 'var(--ui-primary, #0064E0)' : '#92400E' }}>{selected.close_authority ? 'Đã khớp' : 'Cần xử lý exception'}</div>{selected.limitation && <div style={{ fontSize: 12, marginTop: 2, color: selected.close_authority ? '#1C1E21' : '#B45309' }}>{selected.limitation}</div>}</div>{selected.exceptions?.length ? <List className="misa-mt-12" dataSource={selected.exceptions} renderItem={(item) => <List.Item><List.Item.Meta title={<Typography.Text code>{item.code}</Typography.Text>} description={item.reason} /></List.Item>} /> : null}</Card>}
  </PageShell>;
};

export default InventorySubledgerGlReconciliation;
