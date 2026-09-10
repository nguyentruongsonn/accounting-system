import { Alert, Button, Descriptions, Drawer, Form, Input, Spin, Steps, Tag, Typography } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { SendOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo } from 'react';
import api from '../../api/axios';
import { useAuthStore } from '../../store/useAuthStore';
import { approvalRequestPresentation, canSubmitPurchaseApproval, type PurchaseApprovalRequest } from './purchaseInvoiceApproval';

type Invoice = { id: number; invoice_number?: string; voucher_number?: string; is_posted?: boolean };
type ApiData = { invoice_id: number; is_posted: boolean; snapshot_hash: string; requests: PurchaseApprovalRequest[]; limitation: string };
type Props = { invoice: Invoice | null; open: boolean; onClose: () => void };
const EMPTY_REQUESTS: PurchaseApprovalRequest[] = [];

const VIEW_PERMISSION = 'purchase.invoices.view';
const REQUEST_PERMISSION = 'purchase.invoices.update';

function unwrap(payload: unknown): ApiData {
  const data = (payload as { data?: unknown })?.data ?? payload;
  if (!data || typeof data !== 'object' || !Array.isArray((data as ApiData).requests)) throw new Error('Máy chủ trả về dữ liệu approval không hợp lệ.');
  return data as ApiData;
}

function time(value: string | null | undefined): string { return value ? new Date(value).toLocaleString('vi-VN') : '—'; }
function shortHash(value: string | undefined): string { return value && value.length > 13 ? `${value.slice(0, 12)}…` : value || '—'; }

/**
 * Operational request/status surface only. It intentionally has no approve,
 * reject or post control: those authorities stay on guarded backend routes.
 */
export default function PurchaseInvoiceApprovalWorkflow({ invoice, open, onClose }: Props) {
  const [form] = Form.useForm<{ reference_note?: string }>();
  const queryClient = useQueryClient();
  const user = useAuthStore((state) => state.user);
  const permissions = user?.permissions ?? [];
  const canView = permissions.includes(VIEW_PERMISSION);
  const canRequest = permissions.includes(REQUEST_PERMISSION);
  const invoiceId = invoice?.id;

  const requestQuery = useQuery({
    queryKey: ['purchase-invoice-approval-requests', invoiceId],
    enabled: open && invoiceId != null && canView,
    retry: false,
    queryFn: async (): Promise<ApiData> => unwrap((await api.get(`/purchase/invoices/${invoiceId}/approval-requests`)).data),
  });
  const requests = requestQuery.data?.requests ?? EMPTY_REQUESTS;
  const hasCurrentPending = requests.some((item) => item.status === 'pending' && item.evidence.is_current);
  const submitAllowed = canSubmitPurchaseApproval({
    isPosted: invoice?.is_posted ?? requestQuery.data?.is_posted,
    canRequest,
    requestLoading: requestQuery.isLoading,
    requestError: requestQuery.isError,
    hasCurrentPending,
  });
  const submitMutation = useMutation({
    mutationFn: async (values: { reference_note?: string }) => api.post(`/purchase/invoices/${invoiceId}/approval-requests`, values),
    onSuccess: async (response) => {
      const persisted = (response as any)?.data?.data;
      if (!persisted || (typeof persisted.id !== 'number' && typeof persisted.id !== 'string') || String(persisted.id).trim() === '') {
        message.error('Máy chủ chưa trả về yêu cầu phê duyệt đã lưu. Không thể xác nhận thành công.');
        return;
      }
      form.resetFields();
      message.success('Đã gửi yêu cầu phê duyệt. Chứng từ chưa được ghi sổ.');
      await queryClient.invalidateQueries({ queryKey: ['purchase-invoice-approval-requests', invoiceId] });
    },
    onError: (error: any) => message.error(error?.response?.data?.message ?? error?.response?.data?.error ?? 'Máy chủ chưa nhận yêu cầu phê duyệt.'),
  });
  const ordered = useMemo(() => [...requests].sort((a, b) => b.id - a.id), [requests]);

  return <Drawer title="Phê duyệt chứng từ mua hàng" size={650} open={open} onClose={onClose} destroyOnHidden>
    <p className="apple-muted-text">Chứng từ: <strong>{invoice?.invoice_number || invoice?.voucher_number || `#${invoiceId ?? ''}`}</strong>. Màn hình này chỉ gửi và đọc chứng cứ phê duyệt; không phê duyệt hoặc ghi sổ.</p>
    {!canView && <Alert type="warning" showIcon message="Bạn chỉ có quyền hạn chế" description={`Cần quyền ${VIEW_PERMISSION} để xem evidence. Giao diện không tải hay suy đoán trạng thái từ dữ liệu cũ.`} />}
    {canView && requestQuery.isLoading && <div className="misa-text-center misa-py-12"><Spin tip="Đang tải chứng cứ phê duyệt..." /></div>}
    {canView && requestQuery.isError && <Alert type="error" showIcon message="Không tải được trạng thái phê duyệt" description="Không gửi yêu cầu hoặc giả định trạng thái khi máy chủ chưa xác nhận evidence." />}
    {canView && !requestQuery.isLoading && !requestQuery.isError && <>
      <Descriptions bordered size="small" column={1} className="mb-4">
        <Descriptions.Item label="Snapshot chứng từ hiện tại"><Typography.Text copyable>{shortHash(requestQuery.data?.snapshot_hash)}</Typography.Text></Descriptions.Item>
        <Descriptions.Item label="Quyền gửi yêu cầu">{canRequest ? <Tag color="processing">Có thể gửi bản nháp</Tag> : <Tag color="default">Chỉ xem</Tag>}</Descriptions.Item>
        <Descriptions.Item label="Phê duyệt / ghi sổ"><Tag color="default">Không có trên màn hình này</Tag></Descriptions.Item>
      </Descriptions>
      {Boolean(invoice?.is_posted ?? requestQuery.data?.is_posted) && <Alert className="mb-4" type="warning" showIcon message="Chứng từ đã ghi sổ" description="Không thể tạo yêu cầu mới cho chứng từ đã ghi sổ. Dùng quy trình điều chỉnh/đảo được kiểm soát nếu cần." />}
      {!canRequest && <Alert className="mb-4" type="warning" showIcon message="Chỉ xem approval evidence" description={`Cần quyền ${REQUEST_PERMISSION} để gửi yêu cầu. Giao diện không cố bypass quyền.`} />}
      <Form form={form} layout="vertical" onFinish={(values) => submitMutation.mutate(values)} disabled={!submitAllowed}>
        <Form.Item name="reference_note" label="Ghi chú đối chiếu chứng từ nguồn (tùy chọn)" extra="Chỉ ghi thông tin nghiệp vụ cần thiết. Không nhập token, mật khẩu hoặc dữ liệu nhạy cảm không cần thiết.">
          <Input.TextArea rows={3} maxLength={1000} showCount placeholder="Ví dụ: Đã đối chiếu hóa đơn nhà cung cấp và biên bản nhận hàng." />
        </Form.Item>
        <Button type="primary" htmlType="submit" icon={<SendOutlined />} loading={submitMutation.isPending} disabled={!submitAllowed} block>Gửi yêu cầu phê duyệt</Button>
      </Form>
      <Typography.Title level={5} className="mt-5">Lịch sử yêu cầu</Typography.Title>
      {!ordered.length && <Typography.Text className="apple-muted-text">Chưa có yêu cầu phê duyệt</Typography.Text>}
      {ordered.map((item) => {
        const state = approvalRequestPresentation(item, user?.id);
        return <div key={item.id} className="misa-table-card mb-3 p-3">
          <div className="flex justify-between gap-3"><div><Typography.Text strong>Yêu cầu #{item.id}</Typography.Text><div className="apple-muted-text">Gửi bởi #{item.requested_by ?? '—'} · {time((item as any).requested_at)}</div></div><Tag color={state.color}>{state.label}</Tag></div>
          <p className="apple-muted-text mt-2 mb-2">{state.detail}</p>
          <Descriptions size="small" column={1} bordered>
            <Descriptions.Item label="Snapshot evidence"><Typography.Text copyable>{shortHash(item.evidence.snapshot_hash)}</Typography.Text> · {item.evidence.is_current ? <Tag color="success">Hiện tại</Tag> : <Tag color="warning">Đã cũ</Tag>}</Descriptions.Item>
            <Descriptions.Item label="Ghi chú">{item.evidence.reference_note || '—'}</Descriptions.Item>
            <Descriptions.Item label="Quy tắc SoD">{item.separation_of_duties_required ? 'Bắt buộc phân tách người gửi / phê duyệt / ghi sổ theo kiểm tra máy chủ.' : 'Theo policy snapshot máy chủ.'}</Descriptions.Item>
          </Descriptions>
          <Steps className="mt-3" size="small" current={item.steps.filter((step) => step.status === 'approved').length} items={item.steps.map((step) => ({ title: `Bước ${step.step_order}`, description: `${step.approved_count}/${step.required_approvals} phê duyệt`, status: step.status === 'rejected' ? 'error' : step.status === 'approved' ? 'finish' : 'process' }))} />
        </div>;
      })}
    </>}
  </Drawer>;
}
