export type SalesApprovalRequest = {
  id: number;
  status: 'pending' | 'approved' | 'rejected' | string;
  requested_by: number | null;
  separation_of_duties_required: boolean;
  evidence: { snapshot_hash?: string; is_current?: boolean; reference_note?: string | null };
  steps: ReadonlyArray<{ step_order: number; status: string; required_approvals: number; approved_count: number; rejected_count: number }>;
};

/** A maker screen must never turn into an approval-decision surface. */
export function isApprovalDecisionAvailableInSalesUi(): false { return false; }

export function salesApprovalPresentation(request: SalesApprovalRequest, currentUserId?: number): { label: string; color: 'processing' | 'success' | 'error' | 'warning'; detail: string } {
  if (!request.evidence.is_current) return { label: 'Chứng cứ đã cũ', color: 'warning', detail: 'Hóa đơn đã thay đổi sau khi gửi. Yêu cầu cũ không thể dùng để ghi sổ; cần một yêu cầu mới trên snapshot hiện tại.' };
  if (request.status === 'approved') return { label: 'Đã hoàn tất', color: 'success', detail: request.separation_of_duties_required && request.requested_by === currentUserId ? 'Bạn là người gửi nên không thể tự ghi sổ hóa đơn này khi maker–checker áp dụng.' : 'Ghi sổ vẫn được máy chủ xác thực lại cùng toàn bộ controls.' };
  if (request.status === 'rejected') return { label: 'Bị từ chối', color: 'error', detail: 'Không có thao tác tự phê duyệt hay ghi sổ tại đây. Điều chỉnh hóa đơn hoặc gửi yêu cầu mới khi phù hợp.' };
  return { label: 'Đang chờ duyệt', color: 'processing', detail: 'Một người có thẩm quyền khác phải quyết định theo workflow. Người gửi không thể tự phê duyệt.' };
}

export function canSubmitSalesApproval(input: { isPosted?: boolean; canRequest: boolean; requestLoading: boolean; requestError: boolean; hasCurrentPending: boolean }): boolean {
  return !input.isPosted && input.canRequest && !input.requestLoading && !input.requestError && !input.hasCurrentPending;
}
