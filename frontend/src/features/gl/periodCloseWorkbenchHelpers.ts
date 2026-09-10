export type ReconciliationResult = {
  check_code: string;
  domain: string;
  status: string;
  difference?: string | null;
  evidence?: Record<string, unknown> | null;
};

export type CloseReadiness = {
  mode?: string;
  period?: { id: number; is_closed?: boolean; status?: string; start_date?: string | null; end_date?: string | null };
  close_gate?: { enforcement?: string; status?: string; close_permitted_by_this_endpoint?: boolean; reason?: string };
  reconciliation_shadow?: {
    run_available?: boolean;
    latest_run?: { uuid?: string; status?: string; completed_at?: string | null; failed_result_count?: number; warning_result_count?: number; not_available_result_count?: number } | null;
    limitations?: string[];
  };
  latest_evaluation?: ReadinessEvaluation | null;
};

export type ReadinessEvaluation = {
  id: number;
  uuid: string;
  period_id: number;
  status: string;
  eligible_to_close: boolean;
  schema_version?: string;
  snapshot_hash?: string;
  evaluated_at?: string | null;
  snapshot?: {
    period?: CloseReadiness['period'];
    eligible_to_close?: boolean;
    status?: string;
    checks?: readonly ReadinessCheck[];
    reconciliation_run?: Record<string, unknown> | null;
  };
};

export type ReadinessCheck = {
  code?: string;
  status?: string;
  details?: Record<string, unknown>;
};

export type SignoffPackage = {
  uuid: string;
  state: string;
  readiness_snapshot_id: number;
  evidence_cutoff_at?: string | null;
  close_permitted_by_this_package?: boolean;
  limitation?: string;
  events?: Array<{ event_type: string; recorded_at?: string | null }>;
};

export type WorkbenchStatus = 'closed' | 'blocked' | 'review' | 'unknown';

const readinessReasonLabels: Record<string, string> = {
  inventory_valuation_unverified_cost: 'Còn mặt hàng có giá xuất kho chưa chốt; cần bổ sung tồn/lô giá và tính lại.',
  inventory_valuation_run_missing: 'Chưa có lần tính giá xuất kho đầy đủ cho toàn bộ kỳ.',
  inventory_valuation_recalculation_required: 'Kết quả tính giá xuất kho đã hết hiệu lực; cần tính lại trước khi đóng kỳ.',
};

export function readinessReasonLabel(reason: unknown): string | null {
  if (typeof reason !== 'string' || reason.trim() === '') return null;
  return readinessReasonLabels[reason] ?? reason;
}

export function workbenchStatus(readiness?: CloseReadiness, loading = false, hasError = false): WorkbenchStatus {
  if (loading || hasError || !readiness) return 'unknown';
  const evaluation = readiness.latest_evaluation;
  if (readiness.period?.is_closed || evaluation?.snapshot?.period?.is_closed) return 'closed';
  if (evaluation) return evaluation.eligible_to_close ? 'review' : 'blocked';
  return readiness.close_gate?.close_permitted_by_this_endpoint === true ? 'review' : 'blocked';
}

export function reconciliationBlockers(results: readonly ReconciliationResult[] = []): ReconciliationResult[] {
  return results.filter((item) => !['passed', 'pass', 'ok', 'success'].includes(item.status.toLowerCase()));
}

export function readinessBlockers(checks: readonly ReadinessCheck[] = []): ReadinessCheck[] {
  return checks.filter((item) => !['passed', 'pass', 'ok', 'success'].includes((item.status ?? '').toLowerCase()));
}

/** Keep the close workbench readable while preserving the server's exact evidence. */
export function readinessCheckSummary(check: ReadinessCheck): string {
  const details = check.details;
  if (!details) return 'Máy chủ chưa công bố chi tiết.';
  const reason = details.reason ?? details.reason_code;
  const reasonLabel = readinessReasonLabel(reason);
  if (reasonLabel) return reasonLabel;
  const nested = Array.isArray(details.checks)
    ? details.checks
      .filter((item): item is Record<string, unknown> => Boolean(item) && typeof item === 'object')
      .filter((item) => !['passed', 'pass', 'ok', 'success'].includes(String(item.status ?? '').toLowerCase()))
      .map((item) => String(item.code ?? item.domain ?? 'Kiểm tra chưa đạt'))
    : [];
  if (nested.length > 0) return `Cần xử lý: ${nested.join(', ')}`;
  return 'Máy chủ đã trả chi tiết; mở bằng chứng domain để xem bản ghi liên quan.';
}

export function nextCloseAction(readiness?: CloseReadiness, packages: readonly SignoffPackage[] = []): string {
  if (!readiness) return 'Tải lại trạng thái từ máy chủ trước khi thực hiện bất kỳ thao tác đóng kỳ nào.';
  const evaluation = readiness.latest_evaluation;
  if (readiness.period?.is_closed || evaluation?.snapshot?.period?.is_closed) return 'Kỳ đã đóng theo máy chủ. Không tạo hoặc sửa hồ sơ sign-off mới cho kỳ này.';
  if (evaluation && !evaluation.eligible_to_close) {
    const failed = evaluation.snapshot?.checks?.filter((check) => !['pass', 'passed', 'ok', 'success'].includes((check.status ?? '').toLowerCase())).map((check) => check.code).filter(Boolean);
    return failed?.length
      ? `Chưa đủ điều kiện đóng kỳ. Cần xử lý: ${failed.join(', ')}.`
      : 'Chưa đủ điều kiện đóng kỳ theo snapshot readiness mới nhất của máy chủ.';
  }
  if (readiness.close_gate?.close_permitted_by_this_endpoint !== true) {
    return readiness.close_gate?.reason
      ? `Chưa được phép đóng kỳ: ${readiness.close_gate.reason}`
      : 'Chưa có bằng chứng backend cho phép đóng kỳ. Hoàn tất đối chiếu và phê duyệt theo quy trình được cấu hình.';
  }
  if (packages.length === 0) return 'Chuẩn bị hồ sơ sign-off từ đúng snapshot readiness đã được máy chủ xác nhận.';
  if (!packages.some((item) => ['approved', 'approved_evidence_only'].includes(item.state))) return 'Hoàn tất các bước sign-off trên hồ sơ bất biến; người lập không được tự phê duyệt.';
  return 'Máy chủ đã công bố readiness. Gửi yêu cầu đóng kỳ để backend kiểm tra lại snapshot và sign-off trong cùng giao dịch.';
}

export function signoffLabel(state: string): string {
  const labels: Record<string, string> = {
    prepared: 'Đã chuẩn bị, chưa trình',
    submitted: 'Đã trình phê duyệt',
    approved: 'Đã phê duyệt',
    approved_evidence_only: 'Đã phê duyệt bằng chứng (chưa tự động khóa kỳ)',
    rejected: 'Bị từ chối',
  };
  return labels[state] ?? `Trạng thái chưa nhận diện: ${state}`;
}
