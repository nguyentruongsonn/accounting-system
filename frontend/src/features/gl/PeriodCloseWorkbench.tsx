import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Alert, Button, Card, Descriptions, Empty, Space, Spin, Table, Tag } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { AuditOutlined, SafetyCertificateOutlined, StopOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { getApiErrorMessage } from '../../utils/apiErrorMessage';
import { nextCloseAction, readinessBlockers, readinessCheckSummary, reconciliationBlockers, signoffLabel, workbenchStatus, type CloseReadiness, type ReconciliationResult, type SignoffPackage } from './periodCloseWorkbenchHelpers';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import DataTableSurface from '../../components/layout/DataTableSurface';
import { AdaptiveSelect } from '../../components/layout/AdaptiveSelect';

type PeriodOption = { id: number; name?: string; start_date?: string; end_date?: string; is_closed?: boolean };

function parseCollection<T>(value: unknown, resource: string): T[] {
  if (Array.isArray(value)) return value as T[];
  if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
    return (value as { data: T[] }).data;
  }
  throw new Error(`Máy chủ không trả về danh sách ${resource} hợp lệ.`);
}

function parseRecord<T>(value: unknown, resource: string, requiredKeys: readonly string[] = []): T {
  const candidate = value && typeof value === 'object' && 'data' in value
    ? (value as { data?: unknown }).data
    : value;
  if (!candidate || typeof candidate !== 'object' || Array.isArray(candidate)) {
    throw new Error(`Máy chủ không trả về ${resource} hợp lệ.`);
  }
  if (requiredKeys.some((key) => !(key in candidate))) {
    throw new Error(`Máy chủ trả về ${resource} thiếu trường bắt buộc.`);
  }
  return candidate as T;
}

function statusTag(status: ReturnType<typeof workbenchStatus>) {
  const value = status === 'closed' ? ['red', 'Đã đóng theo máy chủ'] : status === 'review' ? ['gold', 'Cần backend xác nhận cuối cùng'] : status === 'blocked' ? ['orange', 'Chưa đủ điều kiện'] : ['default', 'Chưa xác minh'];
  return <Tag color={value[0]}>{value[1]}</Tag>;
}

/**
 * Read-only control surface.  A browser may review server evidence here but
 * cannot manufacture readiness, approve itself, or optimistically close a
 * period. The server remains the only close authority.
 */
type PeriodCloseWorkbenchProps = { embedded?: boolean; selectedPeriodId?: number };

export default function PeriodCloseWorkbench({ embedded = false, selectedPeriodId }: PeriodCloseWorkbenchProps = {}) {
  const periodsQuery = useQuery({
    queryKey: ['periods'],
    queryFn: async (): Promise<PeriodOption[]> => {
      const response = await api.get('/gl/periods');
      return parseCollection<PeriodOption>(response.data, 'kỳ kế toán');
    },
  });
  const [periodId, setPeriodId] = useState<number | undefined>(selectedPeriodId);
  const queryClient = useQueryClient();

  useEffect(() => {
    if (selectedPeriodId !== undefined) {
      setPeriodId(selectedPeriodId);
    }
  }, [selectedPeriodId]);

  useEffect(() => {
    if (periodId === undefined && periodsQuery.data?.length) {
      const open = periodsQuery.data.find((item) => !item.is_closed);
      setPeriodId((open ?? periodsQuery.data[0]).id);
    }
  }, [periodId, periodsQuery.data]);

  const readinessQuery = useQuery({
    queryKey: ['period-close-readiness', periodId],
    enabled: periodId !== undefined,
    queryFn: async (): Promise<CloseReadiness> => parseRecord<CloseReadiness>(
      (await api.get(`/gl/periods/${periodId}/close-readiness`)).data,
      'bằng chứng readiness',
    ),
  });
  const runUuid = readinessQuery.data?.reconciliation_shadow?.latest_run?.uuid;
  const resultsQuery = useQuery({
    queryKey: ['reconciliation-results', runUuid],
    enabled: Boolean(runUuid),
    queryFn: async (): Promise<ReconciliationResult[]> => parseCollection<ReconciliationResult>(
      (await api.get(`/gl/reconciliations/${runUuid}/results`)).data,
      'kết quả đối chiếu',
    ),
  });
  const packagesQuery = useQuery({
    queryKey: ['period-close-signoff-packages', periodId],
    enabled: periodId !== undefined,
    queryFn: async (): Promise<SignoffPackage[]> => parseCollection<SignoffPackage>(
      (await api.get(`/gl/periods/${periodId}/close-signoff-packages`)).data,
      'hồ sơ sign-off',
    ),
  });

  const evaluateReadiness = useMutation({
    mutationFn: async (selectedPeriodId: number) => parseRecord<Record<string, unknown>>(
      (await api.post(`/gl/periods/${selectedPeriodId}/close-readiness/evaluate`)).data,
      'bằng chứng readiness mới',
      ['id', 'period_id', 'status', 'snapshot_hash'],
    ),
    onSuccess: async (_data, selectedPeriodId) => {
      await queryClient.invalidateQueries({ queryKey: ['period-close-readiness', selectedPeriodId] });
      message.success('Đã cập nhật kiểm tra readiness từ máy chủ.');
    },
    onError: (error: unknown) => message.error(getApiErrorMessage(error, 'Không thể chạy kiểm tra readiness. Dữ liệu và trạng thái kỳ vẫn được giữ nguyên.')),
  });

  const runReconciliation = useMutation({
    mutationFn: async (selectedPeriodId: number) => {
      const idempotencyKey = `ui-close-reconciliation-${selectedPeriodId}-${Date.now()}`;
      const response = await api.post('/gl/reconciliations', { period_id: selectedPeriodId }, { headers: { 'Idempotency-Key': idempotencyKey } });
      return parseRecord<Record<string, unknown>>(response.data, 'kết quả đối chiếu mới', ['uuid', 'period_id', 'status', 'snapshot_hash']);
    },
    onSuccess: async (_data, selectedPeriodId) => {
      await queryClient.invalidateQueries({ queryKey: ['period-close-readiness', selectedPeriodId] });
      message.success('Đã chạy đối chiếu nguồn từ máy chủ. Tiếp theo hãy đánh giá readiness của kỳ.');
    },
    onError: (error: unknown) => message.error(getApiErrorMessage(error, 'Không thể chạy đối chiếu. Dữ liệu kỳ vẫn được giữ nguyên.')),
  });

  const state = workbenchStatus(readinessQuery.data, readinessQuery.isLoading, readinessQuery.isError);
  const blockers = useMemo(() => reconciliationBlockers(resultsQuery.data), [resultsQuery.data]);
  const readinessChecks = readinessQuery.data?.latest_evaluation?.snapshot?.checks ?? [];
  const readinessIssues = readinessBlockers(readinessChecks);
  const action = nextCloseAction(readinessQuery.data, packagesQuery.data);

  const pageTitle = <PageHeader eyebrow="Tổng hợp" title="Kiểm soát sẵn sàng đóng kỳ" description="Chỉ đọc bằng chứng readiness, reconciliation và sign-off do máy chủ cung cấp." />;
  const shell = (content: ReactNode) => embedded ? <>{content}</> : <PageShell title={pageTitle}>{content}</PageShell>;

  if (periodsQuery.isLoading) return shell(<div className="misa-text-center misa-py-12"><Spin tip="Đang tải danh sách kỳ kế toán..." /></div>);
  if (periodsQuery.isError || !periodsQuery.data) return shell(<Alert type="error" showIcon title="Không tải được kỳ kế toán" description="Không hiển thị trạng thái đóng kỳ khi danh sách kỳ từ máy chủ không khả dụng." action={<Button size="small" onClick={() => void periodsQuery.refetch()}>Thử lại kỳ kế toán</Button>} />);

  return shell(
    <>
      <Card title={<Space><SafetyCertificateOutlined /> Kiểm soát sẵn sàng đóng kỳ</Space>} extra={<Space wrap>{statusTag(state)}<Button size="small" loading={runReconciliation.isPending} disabled={periodId === undefined} onClick={() => periodId !== undefined && runReconciliation.mutate(periodId)}>Chạy đối chiếu</Button><Button size="small" type="primary" loading={evaluateReadiness.isPending} disabled={periodId === undefined} onClick={() => periodId !== undefined && evaluateReadiness.mutate(periodId)}>Đánh giá readiness</Button></Space>}>
        <AdaptiveSelect
          className="misa-mb-12"
          aria-label="Chọn kỳ kế toán để kiểm tra"
          value={periodId}
          onChange={setPeriodId}
          style={{ minWidth: 280 }}
          options={periodsQuery.data.map((item) => ({ value: item.id, label: `${item.name ?? `Kỳ #${item.id}`} (${item.start_date ?? '?'} – ${item.end_date ?? '?'})` }))}
        />
        {readinessQuery.isLoading && <Spin tip="Đang lấy bằng chứng readiness..." />}
        {readinessQuery.isError && <Alert type="error" showIcon title="Không xác minh được readiness" description="Không tạo kết luận hoặc cho phép thao tác đóng kỳ khi máy chủ không trả bằng chứng." action={<Button size="small" onClick={() => void readinessQuery.refetch()}>Thử lại readiness</Button>} />}
        {!readinessQuery.isLoading && !readinessQuery.isError && readinessQuery.data && (
          <>
            <Descriptions bordered size="small" column={1} className="misa-mb-12">
              {readinessQuery.data.latest_evaluation && <Descriptions.Item label="Đánh giá máy chủ">{readinessQuery.data.latest_evaluation.status === 'ready' ? 'Đủ điều kiện theo snapshot hiện tại' : 'Chưa đủ điều kiện theo snapshot hiện tại'} · {readinessQuery.data.latest_evaluation.evaluated_at ?? 'Chưa rõ thời điểm'}</Descriptions.Item>}
              <Descriptions.Item label="Chế độ kiểm tra">{readinessQuery.data.mode ?? 'Không xác định'}</Descriptions.Item>
              <Descriptions.Item label="Close gate">{readinessQuery.data.close_gate?.enforcement ?? 'Không xác định'} / {readinessQuery.data.close_gate?.status ?? 'Không xác định'}</Descriptions.Item>
              <Descriptions.Item label="Kết luận từ endpoint">{readinessQuery.data.close_gate?.close_permitted_by_this_endpoint === true ? 'Endpoint đã cấp kết luận — vẫn cần backend kiểm tra cuối cùng.' : 'Endpoint chưa cấp quyền đóng kỳ.'}</Descriptions.Item>
            </Descriptions>
            {state === 'blocked' && (
              <div className="p-3 bg-amber-50 border border-amber-200 rounded text-amber-800 text-sm flex items-start gap-2 misa-mb-12">
                <StopOutlined className="mt-0.5 text-amber-600" />
                <div>
                  <span className="font-semibold">Việc cần làm tiếp theo: </span>
                  <span>{action}</span>
                </div>
              </div>
            )}
            {readinessIssues.length > 0 && <DataTableSurface className="period-close-readiness-table-surface misa-mt-12">
              <Table
                size="small"
                pagination={false}
                rowKey={(item) => `${item.code ?? item.status ?? 'check'}:${JSON.stringify(item.details ?? {})}`}
                dataSource={readinessIssues}
                columns={[
                  { title: 'Kiểm tra', dataIndex: 'code', render: (value?: string) => value ?? 'Chưa nhận diện' },
                  { title: 'Trạng thái', dataIndex: 'status', render: (value?: string) => <Tag color="orange">{value ?? 'unknown'}</Tag> },
                  { title: 'Chi tiết máy chủ', render: (_: unknown, item) => readinessCheckSummary(item) },
                ]}
              />
            </DataTableSurface>}
          </>
        )}
      </Card>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4 misa-mt-16">
        <Card title={<Space><AuditOutlined /> Vướng mắc đối chiếu theo domain</Space>}>
          {!runUuid && <Empty description="Chưa có reconciliation run được máy chủ công bố cho kỳ này." image={Empty.PRESENTED_IMAGE_SIMPLE} />}
          {runUuid && resultsQuery.isLoading && <Spin tip="Đang tải kết quả đối chiếu..." />}
          {runUuid && resultsQuery.isError && <Alert type="error" showIcon title="Không tải được chi tiết đối chiếu" description="Không coi reconciliation run là đạt khi không đọc được kết quả domain." action={<Button size="small" onClick={() => void resultsQuery.refetch()}>Thử lại kết quả đối chiếu</Button>} />}
          {blockers.length > 0 && <DataTableSurface className="period-close-blockers-table-surface"><Table size="small" pagination={false} rowKey={(item) => `${item.domain}:${item.check_code}`} dataSource={blockers} columns={[
            { title: 'Domain', dataIndex: 'domain' },
            { title: 'Kiểm tra', dataIndex: 'check_code' },
            { title: 'Trạng thái', dataIndex: 'status', render: (value: string) => <Tag color="orange">{value}</Tag> },
            { title: 'Chênh lệch', dataIndex: 'difference', render: (value?: string | null) => value ?? 'Không có số liệu được công bố' },
          ]} /></DataTableSurface>}
        </Card>

        <Card title="Bằng chứng sign-off">
          {packagesQuery.isLoading && <Spin tip="Đang tải hồ sơ sign-off..." />}
          {packagesQuery.isError && <Alert type="error" showIcon title="Không tải được hồ sơ sign-off" description="Không suy ra trạng thái phê duyệt khi bằng chứng không khả dụng." action={<Button size="small" onClick={() => void packagesQuery.refetch()}>Thử lại hồ sơ sign-off</Button>} />}
          {!packagesQuery.isLoading && !packagesQuery.isError && (packagesQuery.data?.length ?? 0) === 0 && <Empty description="Chưa có hồ sơ sign-off được máy chủ ghi nhận cho kỳ này." image={Empty.PRESENTED_IMAGE_SIMPLE} />}
          {(packagesQuery.data?.length ?? 0) > 0 && <DataTableSurface className="period-close-signoff-table-surface"><Table size="small" pagination={false} rowKey="uuid" dataSource={packagesQuery.data} columns={[
            { title: 'Trạng thái', dataIndex: 'state', render: (value: string) => signoffLabel(value) },
            { title: 'Snapshot readiness', dataIndex: 'readiness_snapshot_id' },
            { title: 'Cutoff bằng chứng', dataIndex: 'evidence_cutoff_at', render: (value?: string | null) => value ?? 'Chưa công bố' },
            { title: 'Sự kiện', dataIndex: 'events', render: (events?: SignoffPackage['events']) => events?.length ?? 0 },
            { title: 'Giới hạn', dataIndex: 'limitation', render: (value?: string) => value ?? 'Không có mô tả' },
          ]} /></DataTableSurface>}
        </Card>
      </div>
    </>,
  );
}
