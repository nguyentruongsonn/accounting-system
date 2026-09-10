import { Alert, Button } from 'antd';

type ManagementReportDataErrorProps = {
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

function errorPresentation(error: unknown): { title: string; description: string; correlationId?: string } {
  const response = (error as SafeApiError | undefined)?.response;
  const status = response?.status;
  const code = response?.data?.error_code ?? response?.data?.code;
  const requestId = response?.data?.request_id ?? response?.data?.correlation_id;
  const correlationId = typeof requestId === 'string'
    && /^[A-Za-z0-9_-]{1,128}$/.test(requestId)
    ? requestId
    : undefined;

  if (status === 401 || status === 403) {
    return {
      title: 'Máy chủ từ chối quyền tải dữ liệu báo cáo',
      description: 'Quyền giao diện không thay thế kiểm tra quyền của máy chủ. Không hiển thị dữ liệu rỗng hoặc dữ liệu cũ thay cho yêu cầu bị từ chối.',
      correlationId,
    };
  }

  if (status === 409 && code === 'DEFINITION_UNAVAILABLE') {
    return {
      title: 'Definition version báo cáo chưa thể thực thi',
      description: 'Máy chủ chưa có definition/version được phê duyệt để chạy báo cáo này. Giao diện không thay thế bằng phép tính legacy hoặc số liệu tự suy diễn.',
      correlationId,
    };
  }

  if (status === 404) {
    return {
      title: 'Không có endpoint cho phiên bản báo cáo này',
      description: 'Máy chủ không cung cấp endpoint hoặc capability tương ứng. Giao diện không chuyển sang báo cáo khác.',
      correlationId,
    };
  }

  if (status === 422) {
    return {
      title: 'Ngữ cảnh chạy báo cáo không hợp lệ',
      description: 'Kiểm tra lại ngày chốt hoặc bộ lọc đã gửi. Giao diện không tự thay đổi tham số hay suy ra kỳ kế toán.',
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
 * The capability gate establishes that the actor may attempt the report. A
 * failed data request is still an error, not evidence of an empty report.
 */
export default function ManagementReportDataError({ reportName, onRetry, error }: ManagementReportDataErrorProps) {
  const presentation = errorPresentation(error);

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
