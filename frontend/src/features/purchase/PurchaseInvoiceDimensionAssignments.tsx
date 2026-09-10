import { Alert, Button, Descriptions, Drawer, Form, Select, Spin, Tag } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import api from '../../api/axios';
import {
  canEditPurchaseDimensions,
  shortPolicyHash,
  type PurchaseDimensionSelectionContext,
} from './purchaseDimensionAssignment';

type Invoice = { id: number; invoice_number?: string; voucher_number?: string; is_posted?: boolean };
type Assignment = { dimension_code: string; dimension_value_id: number; value_code?: string; value_name?: string };
type AssignmentResponse = { assignment_revision?: number; assignments?: Assignment[] };

function hasPersistedDimensionEvidence(response: any): boolean {
  const payload = response?.data?.data;
  return Number.isInteger(payload?.assignment_revision)
    && payload.assignment_revision > 0
    && Array.isArray(payload.assignments)
    && payload.assignments.length > 0;
}

type Props = {
  invoice: Invoice | null;
  open: boolean;
  onClose: () => void;
};

function errorText(error: any): string {
  const data = error?.response?.data;
  const items = [data?.error, data?.message, ...Object.values(data?.errors ?? {})]
    .flatMap((item) => Array.isArray(item) ? item : [item])
    .filter((item): item is string => typeof item === 'string');
  return items.join(' ').trim() || 'Máy chủ từ chối lưu snapshot chiều hạch toán.';
}

/**
 * Captures an intentional, draft-only accounting-dimension snapshot. Values
 * are offered only from the server's effective-date selection context.
 */
export default function PurchaseInvoiceDimensionAssignments({ invoice, open, onClose }: Props) {
  const [form] = Form.useForm<Record<string, number>>();
  const [saveForbidden, setSaveForbidden] = useState(false);
  const queryClient = useQueryClient();
  const invoiceId = invoice?.id;

  const contextQuery = useQuery({
    queryKey: ['purchase-invoice-dimension-selection-context', invoiceId],
    enabled: open && invoiceId != null,
    retry: false,
    queryFn: async (): Promise<PurchaseDimensionSelectionContext> => {
      const response = await api.get(`/purchase/invoices/${invoiceId}/dimension-selection-context`);
      return response.data?.data ?? response.data;
    },
  });
  const assignmentsQuery = useQuery({
    queryKey: ['purchase-invoice-dimensions', invoiceId],
    enabled: open && invoiceId != null,
    retry: false,
    queryFn: async (): Promise<AssignmentResponse> => {
      const response = await api.get(`/purchase/invoices/${invoiceId}/dimensions`);
      return response.data?.data ?? response.data;
    },
  });

  const context = contextQuery.data;
  const initialValues = useMemo(() => Object.fromEntries(
    (assignmentsQuery.data?.assignments ?? []).map((assignment) => [assignment.dimension_code, assignment.dimension_value_id]),
  ), [assignmentsQuery.data]);

  useEffect(() => {
    setSaveForbidden(false);
  }, [invoiceId, open]);
  useEffect(() => {
    if (!open || context?.status !== 'available') return;
    form.setFieldsValue(initialValues);
  }, [context?.status, form, initialValues, open]);

  const saveMutation = useMutation({
    mutationFn: async (dimensionValues: Record<string, number>) => api.post(`/purchase/invoices/${invoiceId}/dimensions`, { dimension_values: dimensionValues }),
    onSuccess: async (response) => {
      if (!hasPersistedDimensionEvidence(response)) {
        message.error('Máy chủ chưa trả về snapshot chiều hạch toán đã lưu. Không thể xác nhận thành công.');
        return;
      }
      message.success('Đã lưu snapshot chiều hạch toán tường minh. Điều này không tự động ghi sổ chứng từ.');
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['purchase-invoice-dimensions', invoiceId] }),
        queryClient.invalidateQueries({ queryKey: ['purchase-invoice-dimension-readiness', invoiceId] }),
      ]);
    },
    onError: (error: any) => {
      if (error?.response?.status === 401 || error?.response?.status === 403) setSaveForbidden(true);
      message.error(errorText(error));
    },
  });

  const editable = canEditPurchaseDimensions({
    isPosted: Boolean(invoice?.is_posted),
    context,
    contextLoading: contextQuery.isLoading,
    contextError: contextQuery.isError,
    saveForbidden,
  });
  const required = context?.required_dimensions ?? [];
  const contextUnavailable = context?.status === 'unavailable';

  return (
    <Drawer
      title="Chiều hạch toán"
      size={560}
      open={open}
      onClose={onClose}
      destroyOnHidden
      extra={<Tag color={invoice?.is_posted ? 'default' : editable ? 'processing' : 'warning'}>{invoice?.is_posted ? 'Bất biến sau ghi sổ' : editable ? 'Có thể lưu bản nháp' : 'Chỉ xem'}</Tag>}
    >
      <p className="apple-muted-text">
        Chứng từ: <strong>{invoice?.invoice_number || invoice?.voucher_number || `#${invoiceId ?? ''}`}</strong>. Chỉ các lựa chọn tường minh do người dùng chọn mới được lưu; hệ thống không lấy chiều từ nhà cung cấp, hàng hóa, kho hoặc tệp đính kèm.
      </p>
      {(contextQuery.isLoading || assignmentsQuery.isLoading) && <div className="misa-text-center misa-py-12"><Spin tip="Đang tải context chiều hạch toán..." /></div>}
      {(contextQuery.isError || assignmentsQuery.isError) && <Alert className="mb-4" type="error" showIcon message="Không thể xác minh context hoặc evidence" description="Chứng từ ở chế độ chỉ xem. Không lưu hay gửi ghi sổ khi máy chủ chưa trả đầy đủ context." />}
      {!contextQuery.isLoading && !contextQuery.isError && (
        <>
          <Descriptions bordered size="small" column={1} className="mb-4">
            <Descriptions.Item label="Ngày hạch toán">{context?.posting_date ?? 'Chưa xác định'}</Descriptions.Item>
            <Descriptions.Item label="Policy">{context?.policy_id ? `#${context.policy_id} · hash ${shortPolicyHash(context.policy_contract_hash)}` : 'Chưa xác định'}</Descriptions.Item>
            <Descriptions.Item label="Revision evidence">{assignmentsQuery.data?.assignment_revision ? `Revision ${assignmentsQuery.data.assignment_revision}` : 'Chưa có snapshot'}</Descriptions.Item>
          </Descriptions>
          {context?.status === 'not_required' && <Alert className="mb-4" type="success" showIcon message="Policy hiệu lực không yêu cầu chiều hạch toán" description="Không có lựa chọn nào cần lưu cho ngày hạch toán này." />}
          {contextUnavailable && <Alert className="mb-4" type="warning" showIcon message="Chưa thể chọn chiều hạch toán" description={context?.reason || 'Máy chủ chưa có policy hoặc dimension/value hiệu lực phù hợp.'} />}
          {saveForbidden && <Alert className="mb-4" type="warning" showIcon message="Bạn chỉ có quyền xem" description="Máy chủ không cấp quyền cập nhật chứng từ mua hàng; giao diện không cố bypass quyền này." />}
          {context?.status === 'available' && (
            <Form form={form} layout="vertical" onFinish={(values) => saveMutation.mutate(values)} disabled={!editable}>
              {required.map((dimension) => (
                <Form.Item key={dimension.code} name={dimension.code} label={`${dimension.name || dimension.code} (${dimension.code})`} rules={[{ required: true, message: 'Chọn một giá trị tường minh.' }]}>
                  <Select
                    placeholder="Chọn giá trị do policy cho phép"
                    options={(dimension.values ?? []).map((value) => ({ value: value.id, label: `${value.code} — ${value.name}` }))}
                    showSearch
                    optionFilterProp="label"
                  />
                </Form.Item>
              ))}
              <Button type="primary" htmlType="submit" loading={saveMutation.isPending} disabled={!editable} block>Lưu chiều hạch toán</Button>
            </Form>
          )}
        </>
      )}
    </Drawer>
  );
}
