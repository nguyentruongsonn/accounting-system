export type PurchaseDimensionReadiness = {
  status?: 'ready' | 'not_required' | 'unavailable';
  can_enforce?: boolean;
  posting_date?: string;
  policy_id?: number;
  required_dimensions?: unknown[];
  reason_code?: string;
  reason?: string;
};

export type PostingControlPresentation = {
  kind: 'policy' | 'approval' | 'dimension' | 'general';
  title: string;
  action: string;
  detail: string;
};

type ApiError = {
  response?: {
    data?: {
      error?: unknown;
      message?: unknown;
      errors?: Record<string, unknown>;
    };
  };
};

function errorText(error: unknown): string {
  const data = (error as ApiError | undefined)?.response?.data;
  const values = [data?.error, data?.message, ...Object.values(data?.errors ?? {})]
    .flatMap((value) => Array.isArray(value) ? value : [value])
    .filter((value): value is string => typeof value === 'string');

  return values.join(' ').trim();
}

/**
 * Maps server control denials to a business action without treating the
 * browser as the source of accounting truth. New server reason codes can
 * still be displayed through the safe, generic fallback below.
 */
export function postingControlErrorPresentation(error: unknown): PostingControlPresentation {
  const detail = errorText(error);
  const normalized = detail.toLocaleLowerCase('vi-VN');

  if (normalized.includes('dimension') || normalized.includes('chiều hạch toán')) {
    return {
      kind: 'dimension',
      title: 'Thiếu hoặc không còn hợp lệ chiều hạch toán',
      action: 'Lưu lại các chiều hạch toán tường minh theo policy đang hiệu lực, sau đó gửi lại phê duyệt nếu chứng từ đã thay đổi.',
      detail: detail || 'Máy chủ chưa xác thực được chứng cứ chiều hạch toán cho chứng từ này.',
    };
  }

  if (normalized.includes('approval') || normalized.includes('phê duyệt') || normalized.includes('maker-checker') || normalized.includes('requester')) {
    return {
      kind: 'approval',
      title: 'Điều kiện phê duyệt chưa đạt',
      action: 'Tạo hoặc hoàn tất yêu cầu phê duyệt hợp lệ. Người lập yêu cầu không được tự thực hiện bước bị tách biệt nhiệm vụ.',
      detail: detail || 'Máy chủ chưa xác thực được chứng cứ phê duyệt hiện hành.',
    };
  }

  if (normalized.includes('policy') || normalized.includes('accounting policy') || normalized.includes('chính sách kế toán')) {
    return {
      kind: 'policy',
      title: 'Chưa xác định được policy ghi sổ',
      action: 'Nhờ quản trị kế toán công bố policy được phê duyệt, đúng đơn vị và ngày hạch toán; không tự thay thế bằng cấu hình trên giao diện.',
      detail: detail || 'Máy chủ chưa giải quyết được policy ghi sổ cho chứng từ này.',
    };
  }

  return {
    kind: 'general',
    title: 'Máy chủ chưa cho phép ghi sổ',
    action: 'Kiểm tra chứng từ, kỳ kế toán và các điều kiện kiểm soát; sau đó thử lại. Giao diện không tự ghi nhận trạng thái đã ghi sổ.',
    detail: detail || 'Không nhận được thông tin chi tiết từ máy chủ.',
  };
}

export function dimensionControlPresentation(readiness?: PurchaseDimensionReadiness): PostingControlPresentation {
  if (!readiness) {
    return {
      kind: 'dimension',
      title: 'Đang kiểm tra chiều hạch toán',
      action: 'Chờ máy chủ trả trạng thái trước khi gửi yêu cầu ghi sổ.',
      detail: 'Trạng thái chiều hạch toán chưa được tải.',
    };
  }

  if (readiness.status === 'ready' || readiness.status === 'not_required') {
    return {
      kind: 'dimension',
      title: readiness.status === 'ready' ? 'Chiều hạch toán đã sẵn sàng' : 'Policy hiện tại không yêu cầu chiều hạch toán',
      action: 'Máy chủ vẫn sẽ kiểm tra lại toàn bộ điều kiện trong giao dịch ghi sổ.',
      detail: readiness.posting_date ? `Ngày hạch toán đang kiểm tra: ${readiness.posting_date}.` : 'Đã nhận trạng thái từ máy chủ.',
    };
  }

  return {
    kind: 'dimension',
    title: 'Chiều hạch toán chưa sẵn sàng',
    action: 'Lưu một snapshot chiều hạch toán tường minh theo policy đang hiệu lực; không suy diễn từ nhà cung cấp, hàng hóa, kho hoặc tệp đính kèm.',
    detail: readiness.reason || 'Máy chủ chưa xác thực được chứng cứ chiều hạch toán.',
  };
}

export function canRequestPosting(readiness?: PurchaseDimensionReadiness, isLoading = false): boolean {
  // A missing or malformed preflight response is not evidence that posting is
  // allowed. Only an explicit server-published ready/not_required state may
  // open the request button; the posting transaction remains authoritative.
  return !isLoading && (readiness?.status === 'ready' || readiness?.status === 'not_required');
}
