import React from 'react';
import { Alert, Card, Descriptions, Space, Spin, Tag, Typography } from 'antd';
import { CheckCircleOutlined, LockOutlined, SafetyCertificateOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import api from '../../api/axios';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';

interface CompanyEvidence {
  id: number;
  name?: string | null;
  code?: string | null;
  tax_code?: string | null;
}

interface PeriodEvidence {
  id: number;
  name?: string | null;
  start_date?: string | null;
  end_date?: string | null;
  is_closed?: boolean | null;
  fiscal_year_id?: number | null;
}

interface RegimeEvidence {
  id: number;
  regime: string | null;
  regime_label: string | null;
  effective_from: string | null;
  effective_to: string | null;
}

function parseRegimeEvidence(value: unknown): RegimeEvidence[] {
  const rows = Array.isArray(value)
    ? value
    : value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)
      ? (value as { data: unknown[] }).data
      : null;
  if (!rows) throw new Error('Regime evidence is missing.');
  return rows.map((row, index) => {
    if (!row || typeof row !== 'object') throw new Error(`Invalid regime evidence at index ${index}.`);
    const candidate = row as Record<string, unknown>;
    const id = candidate.id;
    if (!(typeof id === 'number' || (typeof id === 'string' && /^\d+$/.test(id))) || Number(id) < 1) {
      throw new Error(`Invalid regime evidence id at index ${index}.`);
    }
    return {
      id: Number(id),
      regime: typeof candidate.regime === 'string' ? candidate.regime : null,
      regime_label: typeof candidate.regime_label === 'string' ? candidate.regime_label : null,
      effective_from: typeof candidate.effective_from === 'string' ? candidate.effective_from : null,
      effective_to: typeof candidate.effective_to === 'string' ? candidate.effective_to : null,
    };
  });
}

function parseApprovedMappingEvidence(value: unknown): number {
  if (Array.isArray(value)) return value.length;
  if (!value || typeof value !== 'object') throw new Error('Approved mapping evidence is missing.');
  const candidate = value as Record<string, unknown>;
  if (!Array.isArray(candidate.data)) throw new Error('Approved mapping evidence has no data rows.');
  const meta = candidate.meta;
  if (meta && typeof meta === 'object') {
    const total = (meta as Record<string, unknown>).total;
    if (typeof total === 'number' && Number.isInteger(total) && total >= candidate.data.length) return total;
    if (typeof total === 'string' && /^\d+$/.test(total) && Number(total) >= candidate.data.length) return Number(total);
  }
  return candidate.data.length;
}

function parseCompanyEvidence(value: unknown): CompanyEvidence {
  if (!value || typeof value !== 'object') throw new Error('Company evidence is missing.');
  const candidate = value as Record<string, unknown>;
  const id = candidate.id;
  if (!(typeof id === 'number' || (typeof id === 'string' && /^\d+$/.test(id))) || Number(id) < 1) {
    throw new Error('Company evidence has no persisted tenant id.');
  }
  return {
    id: Number(id),
    name: typeof candidate.name === 'string' ? candidate.name : null,
    code: typeof candidate.code === 'string' ? candidate.code : null,
    tax_code: typeof candidate.tax_code === 'string' ? candidate.tax_code : null,
  };
}

function parsePeriodsEvidence(value: unknown): PeriodEvidence[] {
  const rows = Array.isArray(value)
    ? value
    : value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)
      ? (value as { data: unknown[] }).data
      : null;
  if (!rows) throw new Error('Period evidence is missing.');
  return rows.map((row, index) => {
    if (!row || typeof row !== 'object') throw new Error(`Invalid period evidence at index ${index}.`);
    const candidate = row as Record<string, unknown>;
    if (!Number.isInteger(candidate.id) || Number(candidate.id) < 1) {
      throw new Error(`Invalid period evidence id at index ${index}.`);
    }
    return {
      id: Number(candidate.id),
      name: typeof candidate.name === 'string' ? candidate.name : null,
      start_date: typeof candidate.start_date === 'string' ? candidate.start_date : null,
      end_date: typeof candidate.end_date === 'string' ? candidate.end_date : null,
      is_closed: typeof candidate.is_closed === 'boolean' ? candidate.is_closed : null,
      fiscal_year_id: Number.isInteger(candidate.fiscal_year_id) ? Number(candidate.fiscal_year_id) : null,
    };
  });
}

/**
 * Onboarding overview backed by current tenant and period data.
 */
const OnboardingReadiness: React.FC = () => {
  const company = useQuery({
    queryKey: ['onboarding-company-evidence'],
    queryFn: async (): Promise<CompanyEvidence> => parseCompanyEvidence((await api.get('/master/company')).data),
  });
  const periods = useQuery({
    queryKey: ['onboarding-period-evidence'],
    queryFn: async (): Promise<PeriodEvidence[]> => parsePeriodsEvidence((await api.get('/gl/periods')).data),
  });
  const approvedMappings = useQuery({
    queryKey: ['onboarding-approved-mapping-evidence'],
    queryFn: async (): Promise<number> => parseApprovedMappingEvidence((await api.get('/approved-account-mappings?status=approved&per_page=1')).data),
  });
  const regimes = useQuery({
    queryKey: ['onboarding-regime-evidence'],
    queryFn: async (): Promise<RegimeEvidence[]> => parseRegimeEvidence((await api.get('/accounting-policies/profiles')).data),
  });

  return <PageShell title={<PageHeader eyebrow="Thiết lập" title="Thiết lập ban đầu" description="Hoàn thiện thông tin công ty, kỳ kế toán và số dư đầu kỳ trước khi nhập chứng từ." />}>
    <Card
      title={<Space><SafetyCertificateOutlined /> Trạng thái thiết lập ban đầu</Space>}
    >
      <Alert
        className="misa-mb-12"
        type="info"
        showIcon
        icon={<LockOutlined />}
         title="Hoàn thành dữ liệu nền trước khi ghi sổ"
        description="Kiểm tra công ty, kỳ kế toán và nhập số dư đầu kỳ. Tài khoản hạch toán được lấy từ cấu hình đã duyệt."
      />

      <Card size="small" title="Danh tính pháp nhân / tenant" className="misa-mb-12">
         {company.isLoading && <Spin description="Đang lấy evidence công ty từ máy chủ..." />}
         {company.isError && <Alert type="error" showIcon title="Không xác minh được công ty hiện tại" description="Không có dữ liệu thay thế. Kiểm tra quyền và phản hồi /master/company; không tiếp tục bằng dữ liệu mẫu." />}
        {company.data && <Descriptions bordered size="small" column={1}>
          <Descriptions.Item label="Company ID">#{company.data.id}</Descriptions.Item>
          <Descriptions.Item label="Tên công ty">{company.data.name || '—'}</Descriptions.Item>
          <Descriptions.Item label="Mã công ty">{company.data.code || '—'}</Descriptions.Item>
          <Descriptions.Item label="Mã số thuế">{company.data.tax_code || '—'}</Descriptions.Item>
        </Descriptions>}
        <Typography.Paragraph className="misa-mt-12 misa-mb-0">
          <Link to="/settings/company">Mở thông tin công ty để cập nhật dữ liệu tenant</Link>
        </Typography.Paragraph>
      </Card>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
        <Card size="small" title={<Space><CheckCircleOutlined /> Company evidence</Space>}>
          {company.data ? <Tag color="green">Đã nhận từ máy chủ</Tag> : <Tag color="orange">Chưa xác minh</Tag>}
          <Typography.Paragraph className="apple-muted-text misa-mt-8">Chỉ hiển thị tenant do endpoint máy chủ trả về.</Typography.Paragraph>
        </Card>
        <Card size="small" title="Kỳ kế toán do máy chủ cung cấp">
           {periods.isLoading && <Spin size="small" description="Đang lấy kỳ kế toán..." />}
           {periods.isError && <><Tag color="orange">Chưa xác minh</Tag><Typography.Paragraph className="apple-muted-text misa-mt-8">Không đọc được /gl/periods; không tự tạo kỳ hoặc năm tài chính thay thế.</Typography.Paragraph></>}
          {periods.data && periods.data.length === 0 && <><Tag color="orange">Chưa có dữ liệu</Tag><Typography.Paragraph className="apple-muted-text misa-mt-8">Máy chủ chưa trả kỳ kế toán nào.</Typography.Paragraph></>}
           {periods.data && periods.data.length > 0 && <Space orientation="vertical" className="w-full">
            <Tag color="green">Đã nhận {periods.data.length} kỳ từ máy chủ</Tag>
            {periods.data.slice(0, 12).map((period) => <Typography.Text key={period.id}>
              {period.name || `Kỳ #${period.id}`} · {period.start_date || '—'} → {period.end_date || '—'} · {period.is_closed === true ? 'Đã đóng' : period.is_closed === false ? 'Đang mở' : 'Trạng thái chưa rõ'}
            </Typography.Text>)}
            {periods.data.length > 12 && <Typography.Text type="secondary">Chỉ hiển thị 12 kỳ đầu; xem đầy đủ tại Kỳ kế toán.</Typography.Text>}
          </Space>}
        </Card>
           <Card size="small" title="Chế độ kế toán">
              {regimes.isLoading && <Spin size="small" description="Đang kiểm tra chế độ kế toán..." />}
              {regimes.isError && <><Tag color="orange">Chưa xác minh</Tag><Typography.Paragraph className="apple-muted-text misa-mt-8">Không đọc được chế độ kế toán từ máy chủ; không tự chọn chế độ thay thế.</Typography.Paragraph></>}
             {regimes.data && regimes.data.length === 0 && <><Tag color="orange">Chưa có dữ liệu</Tag><Typography.Paragraph className="apple-muted-text misa-mt-8">Máy chủ chưa trả cấu hình chế độ kế toán cho tenant này.</Typography.Paragraph></>}
              {regimes.data && regimes.data.length > 0 && <Space orientation="vertical" className="w-full">
               <Tag color="green">Đã nhận {regimes.data.length} cấu hình</Tag>
               {regimes.data.map((regime) => <Typography.Text key={regime.id}>
                 {regime.regime_label || regime.regime || 'Chưa có tên chế độ'} · {regime.effective_from || '—'} → {regime.effective_to || '—'}
               </Typography.Text>)}
             </Space>}
           </Card>
         <Card size="small" title="Mapping hạch toán đã duyệt">
            {approvedMappings.isLoading && <Spin size="small" description="Đang kiểm tra mapping..." />}
           {approvedMappings.isError && <><Tag color="orange">Chưa xác minh</Tag><Typography.Paragraph className="apple-muted-text misa-mt-8">Không đọc được danh sách mapping đã duyệt; không tự suy ra tài khoản thay thế.</Typography.Paragraph></>}
           {approvedMappings.data === 0 && <><Tag color="orange">Chưa có mapping hạch toán được duyệt</Tag><Typography.Paragraph className="apple-muted-text misa-mt-8">Các luồng ghi sổ cần cấu hình đúng nghiệp vụ trước khi có thể thực thi.</Typography.Paragraph></>}
           {approvedMappings.data !== undefined && approvedMappings.data > 0 && <><Tag color="green">Đã duyệt {approvedMappings.data} mapping</Tag><Typography.Paragraph className="apple-muted-text misa-mt-8">Mapping được lấy trực tiếp từ máy chủ và có thể xem lại trong danh sách cấu hình.</Typography.Paragraph></>}
           <Link to="/settings/account-mappings">Mở cấu hình mapping</Link>
         </Card>
        <Card size="small" title="Số dư đầu kỳ / opening balance">
          <Tag color="blue">Có thể nhập và đối chiếu</Tag>
          <Typography.Paragraph className="apple-muted-text misa-mt-8">Nhập số dư tài khoản, công nợ và tồn kho; hệ thống chỉ xác nhận khi chi tiết khớp tổng hợp.</Typography.Paragraph>
          <Link to="/settings/opening-balances">Mở số dư đầu kỳ</Link>
        </Card>
         <Card size="small" title="Hồ sơ nguồn / approval / SoD" className="md:col-span-2">
           <Tag color="orange">Chưa khả dụng</Tag>
           <Typography.Paragraph className="apple-muted-text misa-mt-8">Catalogue source dossier, maker-checker, chữ ký, lưu giữ và điều kiện phê duyệt chưa có evidence owner. Thao tác đóng kỳ sẽ tiếp tục bị chặn nếu thiếu evidence đối chiếu.</Typography.Paragraph>
         </Card>
      </div>
    </Card>
  </PageShell>;
};

export { parseApprovedMappingEvidence, parseCompanyEvidence, parsePeriodsEvidence, parseRegimeEvidence };
export default OnboardingReadiness;
