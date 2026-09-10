import { toast as message } from '../../components/feedback/toast';
import api from '../../api/axios';

type ReportDownloadFormat = 'excel' | 'pdf';

const CONTROLLED_REPORT_ENDPOINTS = new Set([
  '/reports/trial-balance',
  '/reports/balance-sheet',
  '/reports/income-statement',
  '/reports/general-journal',
  '/reports/general-ledger',
]);

export const isControlledReportEndpoint = (endpoint: string): boolean => CONTROLLED_REPORT_ENDPOINTS.has(endpoint);

/**
 * Download a server-issued report through the authenticated API client.
 *
 * A `window.open()` URL cannot carry the application's Bearer token and used
 * to hard-code localhost, which meant the visible export button was not bound
 * to the same authenticated request / report-run audit path as the view.
 */
export async function downloadControlledReport(
  endpoint: string,
  format: ReportDownloadFormat,
  filename: string,
  params: Record<string, string> = {},
): Promise<void> {
  if (!isControlledReportEndpoint(endpoint)) {
    message.error('Điểm xuất báo cáo không được công bố. Tệp không được xuất.');
    return;
  }

  try {
    const response = await api.get(endpoint, {
      params: { ...params, export: format },
      responseType: 'blob',
    });
    // A 2xx response is not sufficient evidence that a report artifact exists.
    // Require the authenticated export route to return a non-empty Blob before
    // creating a download or reporting success to the user.
    if (!(response.data instanceof Blob) || response.data.size === 0) {
      throw new Error('Report export returned no non-empty artifact');
    }
    const url = URL.createObjectURL(response.data);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `${filename}.${format === 'excel' ? 'xlsx' : 'pdf'}`;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
    message.success(`Đã tạo ${format === 'excel' ? 'tệp Excel' : 'tệp PDF'} báo cáo.`);
  } catch (error) {
    console.error('Failed to download controlled report:', error);
    message.error('Không thể tạo tệp báo cáo vận hành. Tệp không được xuất.');
  }
}
