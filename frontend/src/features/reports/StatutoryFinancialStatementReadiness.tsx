import React, { useState } from 'react';
import { Alert, Button, Card, Descriptions, Empty, Form, Input, InputNumber, List, Space, Spin, Tag, Typography } from 'antd';
import { FileProtectOutlined, LockOutlined, ReloadOutlined, SafetyCertificateOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import api from '../../api/axios';
import { useAuthStore } from '../../store/useAuthStore';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';

const VIEW_PERMISSION = 'reports.view';

export interface StatementReadinessCondition {
  key: string;
  status: string;
  reason: string;
}

export interface StatementReadiness {
  meta: {
    capability_version: string;
    fiscal_year_id: number;
    accounting_regime?: string;
    accounting_regime_profile_id?: number;
    form_key: string;
    as_of_date: string;
    read_only: true;
    statutory_output_available: false;
    legal_compliance_certified: false;
    disclaimer: string;
  };
  definition: null | {
    id: number;
    definition_version: string;
    contract_hash: string;
    effective_from: string;
    effective_to: string;
  };
  definition_evidence_ready: boolean;
  execution_ready: false;
  missing_conditions: StatementReadinessCondition[];
}

interface QueryInput {
  fiscalYearId?: number;
  formKey?: string;
  cutoffDate?: string;
}

function requiredInput(input: QueryInput): input is Required<QueryInput> {
  return Boolean(input.fiscalYearId && input.formKey?.trim() && input.cutoffDate);
}

function parseReadiness(value: unknown): StatementReadiness {
  if (!value || typeof value !== 'object') throw new Error('Invalid statutory-statement readiness response.');
  const result = value as Record<string, unknown>;
  const meta = result.meta;
  if (!meta || typeof meta !== 'object') throw new Error('Statutory readiness metadata is missing.');
  const metadata = meta as Record<string, unknown>;
  if (typeof metadata.capability_version !== 'string' || typeof metadata.fiscal_year_id !== 'number'
    || typeof metadata.form_key !== 'string' || typeof metadata.as_of_date !== 'string'
    || metadata.read_only !== true || metadata.statutory_output_available !== false
    || metadata.legal_compliance_certified !== false || typeof metadata.disclaimer !== 'string'
    || result.execution_ready !== false || typeof result.definition_evidence_ready !== 'boolean'
    || !Array.isArray(result.missing_conditions)) {
    throw new Error('Statutory readiness response violates its evidence-only contract.');
  }
  result.missing_conditions.forEach((condition, index) => {
    if (!condition || typeof condition !== 'object' || typeof (condition as { key?: unknown }).key !== 'string'
      || typeof (condition as { status?: unknown }).status !== 'string'
      || typeof (condition as { reason?: unknown }).reason !== 'string') {
      throw new Error(`Invalid statutory readiness condition at index ${index}.`);
    }
  });
  if (result.definition !== null) {
    if (!result.definition || typeof result.definition !== 'object') {
      throw new Error('Invalid statutory statement definition evidence.');
    }
    const definition = result.definition as Record<string, unknown>;
    if (!Number.isInteger(definition.id) || Number(definition.id) < 1
      || typeof definition.definition_version !== 'string' || definition.definition_version.trim() === ''
      || typeof definition.contract_hash !== 'string' || definition.contract_hash.trim() === ''
      || typeof definition.effective_from !== 'string' || definition.effective_from.trim() === ''
      || typeof definition.effective_to !== 'string' || definition.effective_to.trim() === '') {
      throw new Error('Statutory statement definition evidence is incomplete.');
    }
  }
  return value as StatementReadiness;
}

/**
 * Evidence-only surface for the server's statutory-statement readiness API.
 * It deliberately has no report rows, calculation, export, signature, issue
 * or filing action. Even a complete definition is not an executable BCTC.
 */
const StatutoryFinancialStatementReadiness: React.FC = () => {
  const permissions = useAuthStore((state) => state.user?.permissions);
  const canView = permissions?.includes(VIEW_PERMISSION) ?? false;
  const [form] = Form.useForm<QueryInput>();
  const [input, setInput] = useState<QueryInput>({});

  const readiness = useQuery({
    queryKey: ['statutory-financial-statement-readiness', input],
    enabled: canView && requiredInput(input),
    queryFn: async (): Promise<StatementReadiness> => parseReadiness((await api.get('/reports/statutory-financial-statement-readiness', {
      params: {
        fiscal_year_id: input.fiscalYearId,
        form_key: input.formKey,
        to_date: input.cutoffDate,
      },
    })).data),
  });

  if (!canView) {
    return <PageShell title={<PageHeader eyebrow="Báo cáo" title="Đánh giá sẵn sàng lập BCTC" description="Chỉ đọc / fail-closed; không thực thi lập hoặc phát hành báo cáo." />}><Alert type="warning" showIcon message="Bạn không có quyền xem readiness BCTC" description={`Cần quyền ${VIEW_PERMISSION}. Giao diện không tải hoặc tự suy đoán catalogue/chứng cứ khi chưa được máy chủ cho phép.`} /></PageShell>;
  }

  const assess = (values: QueryInput) => setInput({
    fiscalYearId: values.fiscalYearId,
    formKey: values.formKey?.trim(),
    cutoffDate: values.cutoffDate,
  });
  const result = readiness.data;

  return <PageShell title={<PageHeader eyebrow="Báo cáo" title="Đánh giá sẵn sàng lập BCTC" description="Chỉ đọc catalogue và evidence do máy chủ công bố; không có thao tác thực thi." />} toolbar={<PageToolbar actions={<Button icon={<ReloadOutlined />} disabled={!requiredInput(input)} onClick={() => void readiness.refetch()}>Tải lại bằng chứng</Button>} />}>
    <Card title={<Space><SafetyCertificateOutlined /> Đánh giá sẵn sàng lập BCTC</Space>} extra={<Tag color="orange">Chỉ đọc / fail-closed</Tag>}>
      <Alert className="misa-mb-12" type="warning" showIcon icon={<LockOutlined />} message="Không phải màn hình lập hoặc phát hành BCTC" description="Màn hình chỉ đọc catalogue và chứng cứ do máy chủ công bố. Không có số liệu, lưu chuyển tiền tệ, thuyết minh, kết quả thuế, ký số, tải xuống hoặc nộp hồ sơ tại đây." />
      <Typography.Paragraph className="apple-muted-text">
        Nhập đúng ID năm tài chính và mã biểu mẫu từ catalogue đã được doanh nghiệp phê duyệt. Không có endpoint công bố danh sách biểu mẫu khả dụng, nên giao diện không hard-code hay đoán tên/mẫu biểu theo TT99.
      </Typography.Paragraph>
      <Form form={form} layout="vertical" onFinish={assess}>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
          <Form.Item name="fiscalYearId" label="ID năm tài chính" rules={[{ required: true, message: 'Cần chọn đúng ID năm tài chính của doanh nghiệp.' }]}>
            <InputNumber min={1} precision={0} className="misa-w-full" aria-label="ID năm tài chính" placeholder="Ví dụ: 12" />
          </Form.Item>
          <Form.Item name="formKey" label="Mã biểu mẫu từ catalogue" rules={[{ required: true, whitespace: true, message: 'Cần mã biểu mẫu đúng từ catalogue đã công bố.' }]}>
            <Input aria-label="Mã biểu mẫu từ catalogue" placeholder="Nhập form_key do kế toán trưởng quản trị" autoComplete="off" />
          </Form.Item>
          <Form.Item name="cutoffDate" label="Ngày cutoff đánh giá" rules={[{ required: true, message: 'Cần ngày cutoff trong năm tài chính đã chọn.' }]}>
            <Input aria-label="Ngày cutoff đánh giá" type="date" />
          </Form.Item>
        </div>
        <Space className="misa-mb-12">
          <Button type="primary" htmlType="submit" icon={<SafetyCertificateOutlined />}>Kiểm tra readiness</Button>
        </Space>
      </Form>
      {readiness.isLoading && <Spin tip="Đang lấy catalogue và evidence từ máy chủ..." />}
      {readiness.isError && <Alert type="error" showIcon message="Không thể xác minh readiness BCTC" description="Không có dữ liệu dự phòng. Kiểm tra ID năm tài chính, mã biểu mẫu, ngày cutoff, quyền reports.view và phản hồi máy chủ." />}
      {!readiness.isLoading && !readiness.isError && requiredInput(input) && !result && <Empty description="Chưa nhận được kết luận từ máy chủ." image={Empty.PRESENTED_IMAGE_SIMPLE} />}
    </Card>

    {result && <>
      <Card className="misa-mt-16" title={<Space><FileProtectOutlined /> Phạm vi đánh giá từ máy chủ</Space>} extra={<Tag color="red">Không cho thực thi</Tag>}>
        <Descriptions bordered size="small" column={1}>
          <Descriptions.Item label="Năm tài chính">#{result.meta.fiscal_year_id}</Descriptions.Item>
          <Descriptions.Item label="Mã biểu mẫu">{result.meta.form_key}</Descriptions.Item>
          <Descriptions.Item label="Ngày cutoff">{result.meta.as_of_date}</Descriptions.Item>
          <Descriptions.Item label="Chế độ kế toán">{result.meta.accounting_regime ?? 'Máy chủ chưa công bố'}</Descriptions.Item>
          <Descriptions.Item label="Catalogue evidence">{result.definition_evidence_ready ? <Tag color="green">Đủ evidence catalogue</Tag> : <Tag color="orange">Chưa đủ evidence catalogue</Tag>}</Descriptions.Item>
          <Descriptions.Item label="Execution readiness"><Tag color="red">{String(result.execution_ready)}</Tag></Descriptions.Item>
        </Descriptions>
        <Alert className="misa-mt-12" type="warning" showIcon message="Backend chưa cấp quyền chạy BCTC" description={result.meta.disclaimer} />
        <Space className="misa-mt-12" wrap>
          <Button disabled icon={<FileProtectOutlined />}>Lập / chạy báo cáo</Button>
          <Button disabled>Tải xuống</Button>
          <Button disabled>Ký / phát hành</Button>
          <Button disabled>Nộp hồ sơ</Button>
        </Space>
      </Card>

      <Card className="misa-mt-16" title="Catalogue và bằng chứng versioned">
        {result.definition === null ? <Empty description="Máy chủ không tìm thấy đúng một catalogue đã công bố và hiệu lực cho context này." image={Empty.PRESENTED_IMAGE_SIMPLE} /> : <Descriptions bordered size="small" column={1}>
          <Descriptions.Item label="Definition ID">#{result.definition.id}</Descriptions.Item>
          <Descriptions.Item label="Phiên bản">{result.definition.definition_version}</Descriptions.Item>
          <Descriptions.Item label="Hiệu lực">{result.definition.effective_from} – {result.definition.effective_to}</Descriptions.Item>
          <Descriptions.Item label="Contract hash"><Typography.Text copyable={{ text: result.definition.contract_hash }}>{result.definition.contract_hash}</Typography.Text></Descriptions.Item>
        </Descriptions>}
      </Card>

      <Card className="misa-mt-16" title="Điều kiện còn thiếu / blocker">
        {result.missing_conditions.length === 0 ? <Typography.Text type="secondary">Không có condition thiếu được trả về.</Typography.Text> : <List dataSource={result.missing_conditions} renderItem={(item) => <List.Item><List.Item.Meta title={<Space><Tag color={item.status === 'missing' ? 'orange' : 'default'}>{item.status}</Tag><Typography.Text code>{item.key}</Typography.Text></Space>} description={item.reason} /></List.Item>} />}
      </Card>
    </>}
  </PageShell>;
};

export default StatutoryFinancialStatementReadiness;
