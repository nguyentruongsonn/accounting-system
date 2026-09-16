import { Button, Descriptions, Drawer, Spin, Tag } from 'antd';
import { useQuery } from '@tanstack/react-query';
import api from '../../api/axios';
import {
  canRequestPosting,
  dimensionControlPresentation,
  postingControlErrorPresentation,
  type PurchaseDimensionReadiness,
} from './purchasePostingControls';

type Invoice = { id: number; invoice_number?: string; voucher_number?: string; accounting_date?: string };

type Props = {
  invoice: Invoice | null;
  open: boolean;
  onClose: () => void;
  onPost: (invoice: Invoice) => Promise<void>;
  isPosting: boolean;
  postError?: unknown;
};

function statusTag(ok: boolean, readyLabel: string, waitingLabel: string) {
  return <Tag color={ok ? 'success' : 'warning'}>{ok ? readyLabel : waitingLabel}</Tag>;
}

/** Informational preflight; the posting transaction remains the final server authority. */
export default function PurchaseInvoicePostingControls({ invoice, open, onClose, onPost, isPosting, postError }: Props) {
  const readinessQuery = useQuery({
    queryKey: ['purchase-invoice-dimension-readiness', invoice?.id],
    enabled: open && invoice !== null,
    queryFn: async (): Promise<PurchaseDimensionReadiness> => {
      const response = await api.get(`/purchase/invoices/${invoice!.id}/dimension-readiness`);
      return response.data?.data ?? response.data;
    },
    retry: false,
  });

  const readiness = readinessQuery.data;
  const dimension = dimensionControlPresentation(readiness);
  const canPost = canRequestPosting(readiness, readinessQuery.isLoading);
  const serverDenial = postError ? postingControlErrorPresentation(postError) : undefined;
  const policyResolved = readiness?.status === 'ready' || readiness?.status === 'not_required';

  return (
    <Drawer title="Kiểm soát trước khi ghi sổ" size={520} open={open} onClose={onClose} destroyOnHidden>
      <p className="apple-muted-text">
        Chứng từ: <strong>{invoice?.invoice_number || invoice?.voucher_number || `#${invoice?.id ?? ''}`}</strong>. Trạng thái này hỗ trợ vận hành; không phải xác nhận tuân thủ pháp lý hoặc thuế.
      </p>
      {readinessQuery.isLoading && <div className="misa-text-center misa-py-12"><Spin tip="Đang kiểm tra chiều hạch toán..." /></div>}
      {readinessQuery.isError && (
        <div
          role="alert"
          className="mb-4 misa-p-12"
          style={{
            background: '#FEF2F2',
            border: '1px solid #FECACA',
            borderRadius: 8,
          }}
        >
          <div style={{ fontWeight: 600, color: '#991B1B', fontSize: 13 }}>Không tải được trạng thái kiểm soát</div>
          <div style={{ color: '#B91C1C', fontSize: 12, marginTop: 2 }}>Không gửi yêu cầu ghi sổ khi chưa đọc được trạng thái chiều hạch toán. Hãy tải lại hoặc liên hệ quản trị hệ thống.</div>
        </div>
      )}
      {!readinessQuery.isLoading && !readinessQuery.isError && (
        <Descriptions bordered column={1} size="small" className="mb-4">
          <Descriptions.Item label="Policy ghi sổ">
            {statusTag(policyResolved, 'Đã xác định theo ngày hạch toán', 'Chưa xác định')}
            <div className="apple-muted-text mt-1">{policyResolved ? 'Policy được máy chủ giải quyết trong bước kiểm tra này.' : dimension.detail}</div>
          </Descriptions.Item>
          <Descriptions.Item label="Chiều hạch toán">
            {statusTag(readiness?.status === 'ready' || readiness?.status === 'not_required', dimension.title, 'Cần hoàn tất')}
            <div className="apple-muted-text mt-1">{dimension.action}</div>
          </Descriptions.Item>
          <Descriptions.Item label="Phê duyệt">
            <Tag color="processing">Sẽ xác thực khi ghi sổ</Tag>
            <div className="apple-muted-text mt-1">Không có endpoint preflight riêng. Máy chủ sẽ kiểm tra chứng cứ phê duyệt, trạng thái và phân tách nhiệm vụ trong giao dịch ghi sổ.</div>
          </Descriptions.Item>
        </Descriptions>
      )}
      {serverDenial && (
        <div
          role="alert"
          className="mb-4 misa-p-12"
          style={{
            background: '#FEF2F2',
            border: '1px solid #FECACA',
            borderRadius: 8,
          }}
        >
          <div style={{ fontWeight: 600, color: '#991B1B', fontSize: 13 }}>{serverDenial.title}</div>
          <div style={{ color: '#B91C1C', fontSize: 12, marginTop: 2 }}>
            <div>{serverDenial.action}</div>
            <div className="apple-muted-text mt-1">Chi tiết máy chủ: {serverDenial.detail}</div>
          </div>
        </div>
      )}
      <Button type="primary" block loading={isPosting} disabled={!invoice || !canPost || readinessQuery.isError} onClick={() => invoice && void onPost(invoice)}>
        Xác thực và ghi sổ
      </Button>
      {!canPost && !readinessQuery.isLoading && !readinessQuery.isError && <p className="apple-muted-text mt-2">Hoàn tất chiều hạch toán hoặc policy trước khi gửi yêu cầu ghi sổ.</p>}
    </Drawer>
  );
}
