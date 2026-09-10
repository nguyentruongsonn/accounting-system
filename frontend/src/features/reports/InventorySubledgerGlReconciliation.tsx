import React from 'react';
import { Alert, Button, Card, Descriptions, Empty, Form, Input, List, Space, Spin, Table, Tag, Typography } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { EyeOutlined, ReloadOutlined, SafetyCertificateOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { useAuthStore } from '../../store/useAuthStore';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

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

  if (!canView) return <PageShell title={<PageHeader eyebrow="Báo cáo" title="Đối chiếu tồn kho – sổ cái" description="Server-controlled / fail-closed." />}><Alert type="warning" showIcon message="Bạn không có quyền xem đối chiếu tồn kho" description={`Cần quyền ${VIEW}.`} /></PageShell>;
  const columns = [
    { title: 'Ngày cutoff', dataIndex: 'as_of_date', width: 150 },
    { title: 'Trạng thái', dataIndex: 'status', width: 190, render: (value: string) => <Tag color={value === 'approved' || value === 'reconciled' ? 'green' : value === 'blocked' ? 'red' : 'orange'}>{value === 'approved' || value === 'reconciled' ? 'Đã đối chiếu' : value === 'blocked' ? 'Bị chặn – cần xử lý' : 'Chưa khả dụng'}</Tag> },
    { title: 'Chênh lệch', key: 'difference', render: (_: unknown, row: Run) => row.amounts_calculated ? String(row.amounts?.difference ?? '—') : '—' },
    { title: 'Thao tác', key: 'view', width: 100, render: (_: unknown, row: Run) => <Button type="text" icon={<EyeOutlined />} aria-label={`Xem đối chiếu ${row.uuid}`} onClick={() => setSelected(row)} /> },
  ];
  return <PageShell title={<PageHeader eyebrow="Báo cáo" title="Đối chiếu tồn kho – sổ cái" description="Giá trị tồn kho được đối chiếu từ nguồn nhập, xuất, đầu kỳ và điều chuyển đã tính giá." />} toolbar={<PageToolbar actions={<Button icon={<ReloadOutlined />} onClick={() => void runs.refetch()} loading={runs.isFetching}>Tải lại</Button>} />}>
    <Card title={<Space><SafetyCertificateOutlined /> Đối chiếu tồn kho – sổ cái</Space>} extra={<Tag color="blue">Server-controlled</Tag>}>
      <Alert type="info" showIcon message="Chỉ chạy khi policy tồn kho đã được admin duyệt" description="Kết quả kiểm tra tenant, nguồn biến động, giá bình quân, tài khoản kiểm soát và số dư đầu kỳ. Chênh lệch hoặc giá chưa chốt sẽ chặn khóa kỳ." />
      {canCapture && <Form form={form} layout="inline" className="misa-mt-16" onFinish={(values) => capture.mutate(values)}><Form.Item name="as_of_date" label="Ngày cutoff" rules={[{ required: true, message: 'Chọn ngày cutoff.' }]}><Input type="date" aria-label="Ngày cutoff đối chiếu tồn kho" /></Form.Item><Button type="primary" htmlType="submit" loading={capture.isPending}>Chạy đối chiếu</Button></Form>}
      {!canCapture && <Typography.Text type="secondary">Bạn chỉ có quyền xem; cần {CAPTURE} để chạy đối chiếu.</Typography.Text>}
      {runs.isLoading && <Spin tip="Đang tải kết quả đối chiếu..." />}
      {runs.isError && <Alert type="error" showIcon message="Không thể tải đối chiếu tồn kho" description="Kiểm tra tenant, quyền và phản hồi máy chủ." />}
      {!runs.isLoading && !runs.isError && runs.data?.length === 0 && <Empty description="Chưa có lần đối chiếu nào." image={Empty.PRESENTED_IMAGE_SIMPLE} />}
      {!!runs.data?.length && <DataTableSurface><Table rowKey="uuid" columns={columns} dataSource={runs.data} loading={runs.isFetching} pagination={false} /></DataTableSurface>}
    </Card>
    {selected && <Card className="misa-mt-16" title="Chi tiết lần đối chiếu" extra={<Button onClick={() => setSelected(null)}>Đóng</Button>}><Descriptions bordered size="small" column={1}><Descriptions.Item label="Ngày cutoff">{selected.as_of_date ?? '—'}</Descriptions.Item><Descriptions.Item label="Watermark dữ liệu UTC">{selected.input_cutoff_at ?? 'Không khả dụng'}</Descriptions.Item><Descriptions.Item label="Trạng thái">{selected.status}</Descriptions.Item><Descriptions.Item label="Snapshot hash">{selected.snapshot_hash ?? '—'}</Descriptions.Item><Descriptions.Item label="Giá trị đối chiếu">{selected.amounts_calculated ? <pre className="misa-audit-safe-json">{JSON.stringify(selected.amounts, null, 2)}</pre> : 'Chưa khả dụng'}</Descriptions.Item><Descriptions.Item label="Đủ điều kiện khóa kỳ">{selected.close_authority ? 'Có' : 'Không'}</Descriptions.Item></Descriptions><Alert className="misa-mt-12" type={selected.close_authority ? 'success' : 'warning'} showIcon message={selected.close_authority ? 'Đã khớp' : 'Cần xử lý exception'} description={selected.limitation} />{selected.exceptions?.length ? <List className="misa-mt-12" dataSource={selected.exceptions} renderItem={(item) => <List.Item><List.Item.Meta title={<Typography.Text code>{item.code}</Typography.Text>} description={item.reason} /></List.Item>} /> : null}</Card>}
  </PageShell>;
};

export default InventorySubledgerGlReconciliation;
