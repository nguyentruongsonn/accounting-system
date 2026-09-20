import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Button, Card, Empty, Input, Modal, Spin, Table } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { getApiErrorMessage } from '../../utils/apiErrorMessage';
import {
  hasApprovedSignoff,
  nextCloseAction,
  readinessBlockers,
  readinessCheckSummary,
  reconciliationBlockers,
  signoffLabel,
  workbenchStatus,
  type CloseReadiness,
  type ReconciliationResult,
  type SignoffPackage,
} from './periodCloseWorkbenchHelpers';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import DataTableSurface from '../../components/layout/DataTableSurface';
import { AdaptiveSelect } from '../../components/layout/AdaptiveSelect';
import { useAuthStore } from '../../store/useAuthStore';
import { formatDate, formatDateTime } from '../../utils/dateUtils';

type PeriodOption = { id: number; name?: string; start_date?: string; end_date?: string; is_closed?: boolean };
type ReviewDecision = 'approved' | 'rejected';

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

function formatCheckValue(value: unknown): ReactNode {
  if (value === undefined || value === null) return '—';
  if (typeof value === 'boolean') return value ? 'Đạt' : 'Chưa đạt';
  if (typeof value === 'number') return String(value);
  if (typeof value === 'string') return value;
  if (typeof value === 'object') {
    try {
      const entries = Object.entries(value as Record<string, unknown>);
      if (entries.length === 0) return '—';
      return (
        <span className="text-[11px] font-mono text-slate-700 bg-slate-100 px-1.5 py-0.5 rounded inline-block">
          {entries.map(([k, v]) => `${k}: ${v === null || v === undefined ? 'null' : typeof v === 'object' ? JSON.stringify(v) : String(v)}`).join(', ')}
        </span>
      );
    } catch {
      return JSON.stringify(value);
    }
  }
  return String(value);
}

function statusBadge(status: ReturnType<typeof workbenchStatus>) {
  if (status === 'closed') {
    return (
      <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600 border border-slate-200">
        <span className="w-1.5 h-1.5 rounded-full bg-slate-400" />
        Đã đóng theo máy chủ
      </span>
    );
  }
  if (status === 'review') {
    return (
      <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700 border border-blue-200/80">
        <span className="w-1.5 h-1.5 rounded-full bg-blue-500" />
        Cần backend xác nhận cuối cùng
      </span>
    );
  }
  if (status === 'blocked') {
    return (
      <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-800 border border-amber-200/80">
        <span className="w-1.5 h-1.5 rounded-full bg-amber-500" />
        Chưa đủ điều kiện
      </span>
    );
  }
  return (
    <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-500 border border-slate-200">
      <span className="w-1.5 h-1.5 rounded-full bg-slate-300" />
      Chưa xác minh
    </span>
  );
}

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
  const [closeOpen, setCloseOpen] = useState(false);
  const [closeReason, setCloseReason] = useState('');
  const [reviewOpen, setReviewOpen] = useState(false);
  const [reviewDecision, setReviewDecision] = useState<ReviewDecision>('approved');
  const [reviewNote, setReviewNote] = useState('');
  const queryClient = useQueryClient();
  const currentUser = useAuthStore((state) => state.user);
  const roles = currentUser?.roles;
  const canClose = roles?.includes('admin') ?? false;

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

  const prepareSignoff = useMutation({
    mutationFn: async ({ selectedPeriodId, readinessSnapshotId }: { selectedPeriodId: number; readinessSnapshotId: number }) => parseRecord<SignoffPackage>(
      (await api.post(`/gl/periods/${selectedPeriodId}/close-signoff-packages`, { readiness_snapshot_id: readinessSnapshotId })).data,
      'hồ sơ sign-off mới',
      ['uuid', 'period_id', 'state', 'readiness_snapshot_id'],
    ),
    onSuccess: async (_data, { selectedPeriodId }) => {
      await queryClient.invalidateQueries({ queryKey: ['period-close-signoff-packages', selectedPeriodId] });
      message.success('Đã chuẩn bị hồ sơ sign-off từ snapshot readiness hiện tại.');
    },
    onError: (error: unknown) => message.error(getApiErrorMessage(error, 'Không thể chuẩn bị hồ sơ sign-off. Không có dữ liệu kỳ nào được thay đổi.')),
  });

  const submitSignoff = useMutation({
    mutationFn: async (packageItem: SignoffPackage) => parseRecord<SignoffPackage>(
      (await api.post(`/gl/close-signoff-packages/${packageItem.uuid}/submit`, { evidence: { source: 'period-close-workbench' } })).data,
      'hồ sơ sign-off đã trình',
      ['uuid', 'period_id', 'state', 'readiness_snapshot_id'],
    ),
    onSuccess: async (_data, packageItem) => {
      await queryClient.invalidateQueries({ queryKey: ['period-close-signoff-packages', packageItem.period_id] });
      message.success('Đã trình hồ sơ sign-off. Chờ admin độc lập phê duyệt.');
    },
    onError: (error: unknown) => message.error(getApiErrorMessage(error, 'Không thể trình hồ sơ sign-off. Hồ sơ vẫn giữ nguyên trạng thái.')),
  });

  const decideSignoff = useMutation({
    mutationFn: async ({ packageItem, decision, note }: { packageItem: SignoffPackage; decision: ReviewDecision; note: string }) => parseRecord<SignoffPackage>(
      (await api.post(`/gl/close-signoff-packages/${packageItem.uuid}/decisions`, {
        step_order: 1,
        decision,
        evidence: { review_note: note.trim() || 'Đã rà soát trên hệ thống.' },
      })).data,
      'kết quả phê duyệt sign-off',
      ['uuid', 'period_id', 'state', 'readiness_snapshot_id'],
    ),
    onSuccess: async (_data, { packageItem, decision }) => {
      await queryClient.invalidateQueries({ queryKey: ['period-close-signoff-packages', packageItem.period_id] });
      setReviewOpen(false);
      setReviewNote('');
      message.success(decision === 'approved' ? 'Đã phê duyệt hồ sơ sign-off.' : 'Đã từ chối hồ sơ sign-off.');
    },
    onError: (error: unknown) => message.error(getApiErrorMessage(error, 'Không thể cập nhật quyết định sign-off. Hồ sơ vẫn giữ nguyên trạng thái.')),
  });

  const closeMutation = useMutation({
    mutationFn: async ({ period, reason }: { period: PeriodOption; reason: string }) => {
      const endDate = period.end_date;
      if (!period.start_date || !endDate) throw new Error('Kỳ kế toán chưa có đủ ngày bắt đầu và ngày kết thúc.');
      const response = await api.post('/gl/closing-entries/execute', {
        period_id: period.id,
        period_close_readiness_snapshot_id: readinessSnapshotId,
        from_date: period.start_date,
        to_date: endDate,
        voucher_date: endDate,
        posting_date: endDate,
        voucher_number: `KC-${period.id}-${endDate.replaceAll('-', '')}`,
        description: `Kết chuyển và khóa ${period.name ?? `kỳ #${period.id}`}`,
        close_reason: reason.trim(),
      });
      return parseRecord<Record<string, unknown>>(response.data, 'chứng từ khóa kỳ', ['id']);
    },
    onSuccess: async (_data, { period }) => {
      message.success(`Đã kết chuyển và khóa ${period.name ?? `kỳ #${period.id}`} theo máy chủ.`);
      setCloseOpen(false);
      setCloseReason('');
      await queryClient.invalidateQueries({ queryKey: ['periods'] });
      await queryClient.invalidateQueries({ queryKey: ['period-close-readiness', period.id] });
    },
    onError: (error: unknown) => message.error(getApiErrorMessage(error, 'Không thể khóa kỳ. Không có trạng thái nào được thay đổi.')),
  });

  const state = workbenchStatus(readinessQuery.data, readinessQuery.isLoading, readinessQuery.isError);
  const blockers = useMemo(() => reconciliationBlockers(resultsQuery.data), [resultsQuery.data]);
  const readinessChecks = readinessQuery.data?.latest_evaluation?.snapshot?.checks ?? [];
  const readinessIssues = readinessBlockers(readinessChecks);
  const action = nextCloseAction(readinessQuery.data, packagesQuery.data);
  const selectedPeriod = periodsQuery.data?.find((item) => item.id === periodId);
  const latestPackage = packagesQuery.data?.[0];
  const readinessSnapshotId = readinessQuery.data?.latest_evaluation?.id;
  const readinessEligible = readinessQuery.data?.latest_evaluation?.eligible_to_close === true;
  const signoffRequired = readinessQuery.data?.close_gate?.signoff_required ?? true;
  const signoffApproved = hasApprovedSignoff(packagesQuery.data);
  const canPrepareSignoff = signoffRequired && (roles?.includes('accountant') || canClose) && selectedPeriod !== undefined && readinessSnapshotId !== undefined && readinessEligible && (!latestPackage || latestPackage.state === 'rejected');
  const canSubmitSignoff = signoffRequired && latestPackage?.state === 'prepared' && latestPackage.prepared_by === currentUser?.id;
  const canReviewSignoff = signoffRequired && latestPackage?.state === 'submitted' && canClose && latestPackage.prepared_by !== currentUser?.id;
  const closeAvailable = canClose && selectedPeriod !== undefined && !selectedPeriod.is_closed && readinessEligible && (!signoffRequired || signoffApproved) && !closeMutation.isPending;

  const pageTitle = <PageHeader eyebrow="Tổng hợp" title="Kiểm soát sẵn sàng đóng kỳ" description="Chỉ đọc bằng chứng readiness, reconciliation và sign-off nếu profile máy chủ yêu cầu." />;
  const shell = (content: ReactNode) => embedded ? <>{content}</> : <PageShell title={pageTitle}>{content}</PageShell>;

  useEffect(() => {
    if (periodsQuery.isError) {
      message.error('Không tải được danh sách kỳ kế toán từ máy chủ.');
    }
  }, [periodsQuery.isError]);

  useEffect(() => {
    if (readinessQuery.isError) {
      message.error('Không xác minh được readiness đóng kỳ.');
    }
  }, [readinessQuery.isError]);

  useEffect(() => {
    if (resultsQuery.isError) {
      message.error('Không tải được chi tiết đối chiếu.');
    }
  }, [resultsQuery.isError]);

  useEffect(() => {
    if (packagesQuery.isError) {
      message.error('Không tải được hồ sơ sign-off từ máy chủ.');
    }
  }, [packagesQuery.isError]);

  if (periodsQuery.isLoading) return shell(<div className="misa-text-center misa-py-12"><Spin tip="Đang tải danh sách kỳ kế toán..." /></div>);
  if (periodsQuery.isError || !periodsQuery.data) return shell(
    <div className="misa-text-center misa-py-12">
      <div className="misa-text-secondary misa-mb-8">Không hiển thị trạng thái đóng kỳ khi danh sách kỳ từ máy chủ không khả dụng.</div>
      <Button size="small" onClick={() => void periodsQuery.refetch()}>Thử lại kỳ kế toán</Button>
    </div>
  );

  return shell(
    <div className="space-y-4">
      {/* Control Bar & Status Card */}
      <div className="bg-white border border-slate-200/90 rounded-lg p-4 shadow-xs">
        <div className="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-100">
          <div className="flex items-center gap-3">
            <span className="text-xs font-medium text-slate-500">Kỳ kế toán:</span>
            <AdaptiveSelect
              aria-label="Chọn kỳ kế toán để kiểm tra"
              value={periodId}
              onChange={setPeriodId}
              className="text-sm"
              style={{ minWidth: 260 }}
              options={periodsQuery.data.map((item) => ({
                value: item.id,
                label: `${item.name ?? `Kỳ #${item.id}`} (${item.start_date ? formatDate(item.start_date) : '?'} – ${item.end_date ? formatDate(item.end_date) : '?'})`,
              }))}
            />
            {statusBadge(state)}
          </div>

          {/* Workflow Action Buttons */}
          <div className="flex items-center gap-2">
            <Button
              size="small"
              loading={runReconciliation.isPending}
              disabled={periodId === undefined}
              onClick={() => periodId !== undefined && runReconciliation.mutate(periodId)}
              className="text-xs"
            >
              1. Chạy đối chiếu
            </Button>
            <Button
              size="small"
              loading={evaluateReadiness.isPending}
              disabled={periodId === undefined}
              onClick={() => periodId !== undefined && evaluateReadiness.mutate(periodId)}
              className="text-xs"
            >
              2. Đánh giá readiness
            </Button>
            {canClose && (
              <Button
                size="small"
                type="primary"
                loading={closeMutation.isPending}
                disabled={!closeAvailable}
                onClick={() => {
                  setCloseReason(`Khóa ${selectedPeriod?.name ?? 'kỳ kế toán'} sau khi hoàn tất đối chiếu.`);
                  setCloseOpen(true);
                }}
                className={`text-xs font-medium ${
                  closeAvailable
                    ? 'bg-blue-600 hover:bg-blue-700 text-white'
                    : 'bg-slate-100 text-slate-400 border-slate-200'
                }`}
              >
                3. Kết chuyển và khóa sổ
              </Button>
            )}
          </div>
        </div>

        {readinessQuery.isLoading && (
          <div className="py-6 text-center">
            <Spin tip="Đang lấy bằng chứng readiness..." />
          </div>
        )}

        {readinessQuery.isError && (
          <div className="p-3 my-3 bg-rose-50/80 border border-rose-200 rounded-lg flex items-center justify-between text-xs">
            <div className="text-rose-700">Không tạo kết luận hoặc cho phép thao tác đóng kỳ khi máy chủ không trả bằng chứng.</div>
            <Button size="small" onClick={() => void readinessQuery.refetch()}>Thử lại readiness</Button>
          </div>
        )}

        {!readinessQuery.isLoading && !readinessQuery.isError && readinessQuery.data && (
          <div className="pt-3">
            {/* 4-Stat Minimal Grid */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs mb-3">
              <div className="p-2.5 bg-slate-50 border border-slate-200/80 rounded-md">
                <div className="text-slate-400 text-[11px] mb-0.5">Đánh giá máy chủ</div>
                <div className="font-medium text-slate-800">
                  {readinessQuery.data.latest_evaluation?.status === 'ready' ? 'Đủ điều kiện đóng kỳ' : 'Chưa đủ điều kiện'}
                </div>
                <div className="text-[10px] text-slate-400 mt-0.5 truncate">
                  {readinessQuery.data.latest_evaluation?.evaluated_at ? formatDateTime(readinessQuery.data.latest_evaluation.evaluated_at) : 'Chưa rõ thời điểm'}
                </div>
              </div>

              <div className="p-2.5 bg-slate-50 border border-slate-200/80 rounded-md">
                <div className="text-slate-400 text-[11px] mb-0.5">Chế độ kiểm tra</div>
                <div className="font-medium text-slate-800">{readinessQuery.data.mode ?? 'Không xác định'}</div>
              </div>

              <div className="p-2.5 bg-slate-50 border border-slate-200/80 rounded-md">
                <div className="text-slate-400 text-[11px] mb-0.5">Close gate</div>
                <div className="font-medium text-slate-800">
                  {readinessQuery.data.close_gate?.enforcement ?? 'Không xác định'} · {readinessQuery.data.close_gate?.status ?? 'Không xác định'}
                </div>
              </div>

              <div className="p-2.5 bg-slate-50 border border-slate-200/80 rounded-md">
                <div className="text-slate-400 text-[11px] mb-0.5">Kết luận endpoint</div>
                <div className="font-medium text-slate-800">
                  {readinessQuery.data.close_gate?.close_permitted_by_this_endpoint === true
                    ? 'Đã cấp kết luận'
                    : 'Chưa cấp quyền đóng'}
                </div>
              </div>
            </div>

            {/* Next Action Guide */}
            {state === 'blocked' && (
              <div className="p-3 bg-slate-50 border border-slate-200/90 rounded-md text-xs text-slate-700 flex items-start gap-2 mb-3">
                <span className="w-2 h-2 rounded-full bg-amber-500 mt-1 shrink-0" />
                <div>
                  <span className="font-semibold text-slate-900">Việc cần làm tiếp theo: </span>
                  <span>{action}</span>
                </div>
              </div>
            )}

            {/* Readiness Issues Checklist */}
            {readinessIssues.length > 0 && (
              <div className="mt-3">
                <div className="text-xs font-semibold text-slate-700 mb-1.5">Checklist điều kiện cần giải quyết:</div>
                <DataTableSurface className="period-close-readiness-table-surface">
                  <Table
                    size="small"
                    pagination={false}
                    rowKey={(item) => `${item.code ?? item.status ?? 'check'}:${JSON.stringify(item.details ?? {})}`}
                    dataSource={readinessIssues}
                    columns={[
                      { title: 'Điều kiện', render: (_: unknown, item) => <span className="font-medium text-slate-800">{item.label ?? item.code ?? 'Chưa nhận diện'}</span> },
                      { title: 'Mã', dataIndex: 'code', render: (value?: string) => <code className="text-xs text-slate-600 bg-slate-100 px-1 py-0.5 rounded">{value ?? 'Chưa nhận diện'}</code> },
                      {
                        title: 'Mức độ',
                        dataIndex: 'severity',
                        render: (value?: string) => (
                          <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium ${
                            value === 'error' ? 'bg-rose-50 text-rose-700 border border-rose-200' : 'bg-amber-50 text-amber-700 border border-amber-200'
                          }`}>
                            {value ?? 'warning'}
                          </span>
                        ),
                      },
                      {
                        title: 'Trạng thái',
                        dataIndex: 'status',
                        render: (value?: string) => (
                          <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium bg-slate-100 text-slate-600">
                            {value ?? 'unknown'}
                          </span>
                        ),
                      },
                      { title: 'Thực tế', dataIndex: 'actual_value', render: (value: unknown) => formatCheckValue(value) },
                      { title: 'Kỳ vọng', dataIndex: 'expected_value', render: (value: unknown) => formatCheckValue(value) },
                      { title: 'Chi tiết máy chủ', render: (_: unknown, item) => <span className="text-xs text-slate-600">{readinessCheckSummary(item)}</span> },
                    ]}
                  />
                </DataTableSurface>
              </div>
            )}
          </div>
        )}
      </div>

      {/* Two Column Section: Reconciliation & Sign-off */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {/* Left Column: Reconciliation Blockers */}
        <Card
          title={<span className="text-sm font-semibold text-slate-800">Vướng mắc đối chiếu theo domain</span>}
          className="border-slate-200/90 shadow-xs"
          size="small"
        >
          {!runUuid && <Empty description="Chưa có reconciliation run được máy chủ công bố cho kỳ này." image={Empty.PRESENTED_IMAGE_SIMPLE} />}
          {runUuid && resultsQuery.isLoading && <Spin tip="Đang tải kết quả đối chiếu..." />}
          {runUuid && resultsQuery.isError && (
            <div className="text-center py-4">
              <div className="text-xs text-slate-500 mb-2">Không tải được chi tiết đối chiếu. Không coi reconciliation run là đạt khi không đọc được kết quả domain.</div>
              <Button size="small" onClick={() => void resultsQuery.refetch()}>Thử lại kết quả đối chiếu</Button>
            </div>
          )}
          {blockers.length > 0 && (
            <DataTableSurface className="period-close-blockers-table-surface">
              <Table
                size="small"
                pagination={false}
                rowKey={(item) => `${item.domain}:${item.check_code}`}
                dataSource={blockers}
                columns={[
                  { title: 'Phân hệ (Domain)', dataIndex: 'domain', render: (v: string) => <span className="font-medium text-slate-800">{v}</span> },
                  { title: 'Kiểm tra', dataIndex: 'check_code', render: (v: string) => <code className="text-xs text-slate-600 bg-slate-100 px-1 py-0.5 rounded">{v}</code> },
                  {
                    title: 'Trạng thái',
                    dataIndex: 'status',
                    render: (value: string) => (
                      <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium bg-amber-50 text-amber-800 border border-amber-200">
                        {value}
                      </span>
                    ),
                  },
                  { title: 'Chênh lệch', dataIndex: 'difference', render: (value?: string | null) => value ?? 'Không có số liệu' },
                ]}
              />
            </DataTableSurface>
          )}
          {runUuid && !resultsQuery.isLoading && !resultsQuery.isError && blockers.length === 0 && (
            <div className="py-6 text-center text-xs text-emerald-700 bg-emerald-50/50 rounded-md border border-emerald-100">
              Không có chênh lệch đối chiếu nào giữa Sổ cái và các phân hệ chi tiết.
            </div>
          )}
        </Card>

        {/* Right Column: Sign-off Evidence */}
        <Card
          title={<span className="text-sm font-semibold text-slate-800">Bằng chứng sign-off</span>}
          className="border-slate-200/90 shadow-xs"
          size="small"
        >
          {packagesQuery.isLoading && <Spin tip="Đang tải hồ sơ sign-off..." />}
          {packagesQuery.isError && (
            <div className="text-center py-4">
              <div className="text-xs text-slate-500 mb-2">Không tải được hồ sơ sign-off. Không suy ra trạng thái phê duyệt khi bằng chứng không khả dụng.</div>
              <Button size="small" onClick={() => void packagesQuery.refetch()}>Thử lại hồ sơ sign-off</Button>
            </div>
          )}
          {!packagesQuery.isLoading && !packagesQuery.isError && (packagesQuery.data?.length ?? 0) === 0 && (
            <Empty description="Chưa có hồ sơ sign-off được máy chủ ghi nhận cho kỳ này." image={Empty.PRESENTED_IMAGE_SIMPLE} />
          )}

          <div className="flex flex-wrap items-center gap-2 mb-3">
            {canPrepareSignoff && (
              <Button
                size="small"
                loading={prepareSignoff.isPending}
                onClick={() => periodId !== undefined && readinessSnapshotId !== undefined && prepareSignoff.mutate({ selectedPeriodId: periodId, readinessSnapshotId })}
              >
                Chuẩn bị sign-off
              </Button>
            )}
            {canSubmitSignoff && latestPackage && (
              <Button
                size="small"
                type="primary"
                loading={submitSignoff.isPending}
                onClick={() => submitSignoff.mutate(latestPackage)}
              >
                Trình sign-off
              </Button>
            )}
            {canReviewSignoff && latestPackage && (
              <Button
                size="small"
                type="primary"
                loading={decideSignoff.isPending}
                onClick={() => { setReviewDecision('approved'); setReviewOpen(true); }}
              >
                Duyệt sign-off
              </Button>
            )}
            {canReviewSignoff && latestPackage && (
              <Button
                size="small"
                danger
                loading={decideSignoff.isPending}
                onClick={() => { setReviewDecision('rejected'); setReviewOpen(true); }}
              >
                Từ chối sign-off
              </Button>
            )}
          </div>

          {(packagesQuery.data?.length ?? 0) > 0 && (
            <DataTableSurface className="period-close-signoff-table-surface">
              <Table
                size="small"
                pagination={false}
                rowKey="uuid"
                dataSource={packagesQuery.data}
                columns={[
                  { title: 'Trạng thái', dataIndex: 'state', render: (value: string) => <span className="font-medium text-slate-800">{signoffLabel(value)}</span> },
                  { title: 'Snapshot', dataIndex: 'readiness_snapshot_id' },
                  { title: 'Cutoff', dataIndex: 'evidence_cutoff_at', render: (value?: string | null) => value ? formatDateTime(value) : 'Chưa công bố' },
                  { title: 'Sự kiện', dataIndex: 'events', render: (events?: SignoffPackage['events']) => events?.length ?? 0 },
                  { title: 'Giới hạn', dataIndex: 'limitation', render: (value?: string) => value ?? '—' },
                ]}
              />
            </DataTableSurface>
          )}

          {!packagesQuery.isLoading && !packagesQuery.isError && latestPackage?.state === 'approved_evidence_only' && (
            <div className="text-xs text-slate-500 mt-2 p-2 bg-slate-50 border border-slate-200/80 rounded">
              Hồ sơ đã được admin phê duyệt. Nút kết chuyển sẽ mở khi readiness vẫn khớp snapshot và backend cho phép khóa kỳ.
            </div>
          )}
        </Card>
      </div>

      {/* Modal: Kết chuyển và khóa sổ */}
      <Modal
        title={`Kết chuyển và khóa sổ ${selectedPeriod?.name ?? 'kỳ kế toán'}`}
        open={closeOpen}
        onCancel={() => { if (!closeMutation.isPending) setCloseOpen(false); }}
        onOk={() => {
          if (!selectedPeriod) return;
          if (!closeReason.trim()) {
            message.error('Phải nêu lý do khóa kỳ kế toán.');
            return;
          }
          closeMutation.mutate({ period: selectedPeriod, reason: closeReason });
        }}
        okText="Kết chuyển và khóa sổ"
        cancelText="Hủy"
        confirmLoading={closeMutation.isPending}
        destroyOnHidden
      >
        <div className="p-3 bg-slate-50 border border-slate-200/90 rounded-md text-slate-700 text-xs mb-3 leading-relaxed">
          <span className="font-semibold text-slate-900">Lưu ý nghiệp vụ: </span>
          Hệ thống sẽ ghi nhận bút toán kết chuyển do máy chủ sinh, chuyển kỳ sang trạng thái đã đóng và chặn mọi thay đổi trong khoảng thời gian này. Chỉ admin được thực hiện; hồ sơ sign-off chỉ là điều kiện nếu profile máy chủ yêu cầu.
        </div>
        <div className="text-xs font-medium text-slate-700 mb-1">Lý do khóa kỳ: <span className="text-rose-500">*</span></div>
        <Input.TextArea
          aria-label="Lý do khóa kỳ"
          rows={4}
          value={closeReason}
          onChange={(event) => setCloseReason(event.target.value)}
          placeholder="Nêu căn cứ khóa sổ hoặc ghi chú bàn giao..."
        />
      </Modal>

      {/* Modal: Duyệt / Từ chối sign-off */}
      <Modal
        title={reviewDecision === 'approved' ? 'Duyệt hồ sơ sign-off' : 'Từ chối hồ sơ sign-off'}
        open={reviewOpen}
        onCancel={() => { if (!decideSignoff.isPending) setReviewOpen(false); }}
        onOk={() => latestPackage && decideSignoff.mutate({ packageItem: latestPackage, decision: reviewDecision, note: reviewNote })}
        okText={reviewDecision === 'approved' ? 'Xác nhận duyệt' : 'Xác nhận từ chối'}
        cancelText="Hủy"
        confirmLoading={decideSignoff.isPending}
        destroyOnHidden
      >
        <div className="text-xs font-medium text-slate-700 mb-1">Ghi chú rà soát:</div>
        <Input.TextArea
          aria-label="Ghi chú rà soát sign-off"
          rows={4}
          value={reviewNote}
          onChange={(event) => setReviewNote(event.target.value)}
          placeholder="Nêu căn cứ rà soát hoặc lý do từ chối..."
        />
      </Modal>
    </div>,
  );
}
