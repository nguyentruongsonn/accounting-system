import { Alert, Button } from 'antd';

type ReportDataErrorProps = {
  reportName: string;
  onRetry: () => void | Promise<unknown>;
  error?: unknown;
};

type SafeApiError = {
  response?: {
    status?: unknown;
    data?: {
      code?: unknown;
      error_code?: unknown;
      correlation_id?: unknown;
      request_id?: unknown;
    };
  };
};

function getPresentation(error: unknown): { title: string; description: string; correlationId?: string } {
  const response = (error as SafeApiError | undefined)?.response;
  const status = response?.status;
  const code = response?.data?.error_code ?? response?.data?.code;
  const requestId = response?.data?.request_id ?? response?.data?.correlation_id;
  const correlationId = typeof requestId === 'string' && /^[A-Za-z0-9_-]{1,128}$/.test(requestId)
    ? requestId
    : undefined;

  if (status === 401 || status === 403) {
    return {
      title: 'Máy chủ từ chối quyền tải báo cáo',
      description: 'Không hiển thị dữ liệu rỗng hoặc dữ liệu cũ thay cho yêu cầu bị từ chối. Kiểm tra quyền truy cập với quản trị hệ thống.',
      correlationId,
    };
  }

  if (status === 409 && code === 'DEFINITION_UNAVAILABLE') {
    return {
      title: 'Phiên bản định nghĩa báo cáo chưa thể thực thi',
      description: 'Máy chủ chưa có definition/version được phê duyệt để chạy báo cáo này. Giao diện không thay thế bằng phép tính khác.',
      correlationId,
    };
  }

  return {
    title: 'Không thể tải dữ liệu báo cáo',
    description: 'Không hiển thị dữ liệu rỗng thay cho lỗi tải báo cáo. Hãy thử lại; nếu lỗi tiếp diễn, liên hệ quản trị hệ thống.',
    correlationId,
  };
}

/**
 * Keeps a failed financial-report request distinguishable from a valid empty
 * result. Only a server-provided, validated correlation ID is exposed.
 */
export default function ReportDataError({ reportName, onRetry, error }: ReportDataErrorProps) {
  const presentation = getPresentation(error);

  return (
    <Alert
      type="error"
      showIcon
      title={`${presentation.title}: ${reportName}`}
      description={
        <>
          <span>{presentation.description}</span>
          {presentation.correlationId && <span className="block mt-1">Mã tham chiếu: <code>{presentation.correlationId}</code></span>}
        </>
      }
      action={<Button size="small" onClick={() => void onRetry()}>Thử lại</Button>}
    />
  );
}
