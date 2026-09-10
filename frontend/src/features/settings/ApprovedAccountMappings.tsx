import React, { useCallback, useMemo, useState } from 'react';
import { Alert, Button, Descriptions, Drawer, Form, Input, Popconfirm, Select, Space, Table, Tag, Typography } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import { CheckOutlined, EditOutlined, EyeOutlined, PlusOutlined, ReloadOutlined, SettingOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { useAuthStore } from '../../store/useAuthStore';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import ModalFrame from '../../components/layout/ModalFrame';
import { getApiErrorMessage } from '../../utils/apiErrorMessage';
import { mappingContextFields } from './accountMappingContext';

type MappingStatus = 'draft' | 'approved';

export interface ApprovedAccountMapping {
  id: number;
  accounting_policy_version_id: number;
  mapping_key: string;
  mapping_context: Record<string, unknown>;
  context_hash: string;
  account_role: string;
  account_code: string;
  effective_from: string;
  effective_to: string;
  regulatory_dependencies: string[];
  status: MappingStatus;
  contract_hash: string | null;
  created_by: number;
  approved_by: number | null;
  approved_at: string | null;
  is_immutable: boolean;
  created_at: string | null;
  updated_at: string | null;
  limitation: string;
}

interface MappingFormValues {
  accounting_policy_version_id: number;
  mapping_key: string;
  context_entries: MappingContextEntry[];
  account_role: string;
  account_code: string;
  effective_range: [string, string];
  regulatory_dependencies_text: string;
}

interface MappingContextEntry {
  key: string;
  value: string;
  valueType: 'text' | 'number' | 'boolean';
}

interface ApprovedPolicyOption {
  id: number;
  policy_key: string;
  policy_version: string;
  effective_from: string;
  effective_to: string;
  status: 'approved';
}

interface AccountingPolicy extends Omit<ApprovedPolicyOption, 'status'> {
  accounting_regime_profile_id: number;
  fiscal_year?: number;
  status: MappingStatus;
  posting_rule_contract: {
    schema?: string;
    source_accounts?: PolicySourceAccount[];
  };
  regulatory_dependencies: string[];
  created_by: number;
  approved_by: number | null;
  approved_at: string | null;
  is_immutable: boolean;
}

interface AccountingProfile {
  id: number;
  fiscal_year: number;
  regime: string;
  regime_label: string;
  effective_from: string;
  effective_to: string;
}

interface PolicySourceAccount {
  account_code: string;
  category: 'revenue' | 'expense';
}

interface PolicyFormValues {
  accounting_regime_profile_id: number;
  policy_key: string;
  policy_version: string;
  effective_range: [string, string];
  source_accounts?: PolicySourceAccount[];
  regulatory_dependencies_text: string;
}

interface AccountOption {
  id: number;
  code: string;
  name: string;
  is_parent?: boolean;
  is_active?: boolean;
}

const MAPPING_TEMPLATES = [
  { value: 'inventory.voucher', label: 'Kho · Phiếu nhập/xuất' },
  { value: 'purchase.invoice', label: 'Mua hàng · Hóa đơn' },
  { value: 'purchase.return', label: 'Mua hàng · Trả hàng' },
  { value: 'purchase.discount', label: 'Mua hàng · Giảm giá' },
  { value: 'sales.invoice', label: 'Bán hàng · Hóa đơn' },
  { value: 'sales.return', label: 'Bán hàng · Trả hàng' },
  { value: 'sales.discount', label: 'Bán hàng · Giảm giá' },
  { value: 'cash_bank.voucher', label: 'Quỹ/Ngân hàng · Thu/chi' },
  { value: 'period_close.result', label: 'Tổng hợp · Kết chuyển cuối kỳ' },
];

const ACCOUNT_ROLE_TEMPLATES = [
  'debit', 'credit', 'settlement_debit', 'settlement_credit',
  'purchase_debit', 'revenue_credit', 'inventory_debit', 'inventory_credit',
  'cogs_debit', 'cogs_credit', 'input_vat', 'input_vat_reduction',
  'output_vat', 'output_vat_reduction', 'import_tax_payable',
  'result_clearing', 'retained_earnings',
];

const POLICY_TEMPLATES = [
  { value: 'posting.cash_receipt', label: 'Quỹ · Phiếu thu' },
  { value: 'posting.cash_payment', label: 'Quỹ · Phiếu chi' },
  { value: 'posting.bank_receipt', label: 'Ngân hàng · Thu tiền' },
  { value: 'posting.bank_payment', label: 'Ngân hàng · Chi tiền' },
  { value: 'posting.purchase_invoice', label: 'Mua hàng · Hóa đơn' },
  { value: 'posting.sales_invoice', label: 'Bán hàng · Hóa đơn' },
  { value: 'posting.inventory_receipt', label: 'Kho · Phiếu nhập' },
  { value: 'posting.inventory_issue', label: 'Kho · Phiếu xuất' },
  { value: 'posting.purchase_return', label: 'Mua hàng · Trả hàng' },
  { value: 'posting.purchase_discount', label: 'Mua hàng · Giảm giá' },
  { value: 'posting.sales_return', label: 'Bán hàng · Trả hàng' },
  { value: 'posting.sales_discount', label: 'Bán hàng · Giảm giá' },
  { value: 'posting.period_closing', label: 'Tổng hợp · Kết chuyển cuối kỳ' },
];

const POLICY_BY_MAPPING: Record<string, string[]> = {
  'inventory.voucher': ['posting.inventory_receipt', 'posting.inventory_issue'],
  'purchase.invoice': ['posting.purchase_invoice'],
  'purchase.return': ['posting.purchase_return'],
  'purchase.discount': ['posting.purchase_discount'],
  'sales.invoice': ['posting.sales_invoice'],
  'sales.return': ['posting.sales_return'],
  'sales.discount': ['posting.sales_discount'],
  'cash_bank.voucher': ['posting.cash_receipt', 'posting.cash_payment', 'posting.bank_receipt', 'posting.bank_payment'],
  'period_close.result': ['posting.period_closing'],
};

const hasPersistedMapping = (response: unknown): boolean => {
  const mapping = (response as { data?: { data?: unknown } })?.data?.data;
  if (!mapping || typeof mapping !== 'object') return false;
  const id = (mapping as { id?: unknown }).id;
  return (typeof id === 'number' || typeof id === 'string') && String(id).trim() !== '';
};

const hasPersistedPolicy = (response: unknown): boolean => {
  const policy = (response as { data?: { data?: unknown } })?.data?.data;
  if (!policy || typeof policy !== 'object') return false;
  const id = (policy as { id?: unknown }).id;
  return (typeof id === 'number' || typeof id === 'string') && String(id).trim() !== '';
};

const queryKey = ['approved-account-mappings'];
const permission = {
  view: 'accounting.account-mappings.view',
  create: 'accounting.account-mappings.create',
  update: 'accounting.account-mappings.update',
  approve: 'accounting.account-mappings.approve',
} as const;

function hasPermission(permissions: string[] | undefined, required: string): boolean {
  return permissions?.includes(required) ?? false;
}

function unwrapMappings(payload: unknown): ApprovedAccountMapping[] {
  if (Array.isArray(payload)) return payload as ApprovedAccountMapping[];
  if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
    return (payload as { data: ApprovedAccountMapping[] }).data;
  }
  throw new Error('Invalid approved-account-mappings response');
}

function unwrapCollection<T>(payload: unknown, label: string): T[] {
  if (Array.isArray(payload)) return payload as T[];
  if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
    return (payload as { data: T[] }).data;
  }
  throw new Error(`Invalid ${label} response`);
}

function toDateTime(value: string | null): string {
  return value ? new Date(value).toLocaleString('vi-VN') : '—';
}

function stringifyJson(value: unknown): string {
  return JSON.stringify(value ?? {}, null, 2);
}

function dependencies(value: string): string[] {
  return value.split('\n').map((line) => line.trim()).filter(Boolean);
}

function contextEntries(value: Record<string, unknown>): MappingContextEntry[] {
  return Object.entries(value).map(([key, raw]) => ({
    key,
    value: String(raw ?? ''),
    valueType: typeof raw === 'number' ? 'number' : typeof raw === 'boolean' ? 'boolean' : 'text',
  }));
}

function contextFromEntries(entries: MappingContextEntry[] | undefined): Record<string, unknown> {
  const context: Record<string, unknown> = {};
  for (const entry of entries ?? []) {
    const key = entry.key?.trim();
    const value = entry.value?.trim();
    if (!key && !value) continue;
    if (!key || !value) throw new Error('Mỗi điều kiện context phải có tên trường và giá trị.');
    if (Object.prototype.hasOwnProperty.call(context, key)) throw new Error(`Trường context “${key}” bị lặp.`);
    if (entry.valueType === 'number') {
      const parsed = Number(value);
      if (!Number.isFinite(parsed)) throw new Error(`Giá trị số của “${key}” không hợp lệ.`);
      context[key] = parsed;
    } else if (entry.valueType === 'boolean') {
      if (value !== 'true' && value !== 'false') throw new Error(`Giá trị boolean của “${key}” phải là true hoặc false.`);
      context[key] = value === 'true';
    } else {
      context[key] = value;
    }
  }
  return context;
}

function toPayload(values: MappingFormValues) {
  return {
    accounting_policy_version_id: Number(values.accounting_policy_version_id),
    mapping_key: values.mapping_key.trim(),
    mapping_context: contextFromEntries(values.context_entries),
    account_role: values.account_role.trim(),
    account_code: values.account_code.trim(),
    effective_from: values.effective_range[0],
    effective_to: values.effective_range[1],
    regulatory_dependencies: dependencies(values.regulatory_dependencies_text),
  };
}

const ApprovedAccountMappings: React.FC = () => {
  const queryClient = useQueryClient();
  const user = useAuthStore((state) => state.user);
  const permissions = user?.permissions;
  const canView = hasPermission(permissions, permission.view);
  const canCreate = hasPermission(permissions, permission.create);
  const canUpdate = hasPermission(permissions, permission.update);
  const canApprove = hasPermission(permissions, permission.approve);
  const [form] = Form.useForm<MappingFormValues>();
  const [policyForm] = Form.useForm<PolicyFormValues>();
  const [editing, setEditing] = useState<ApprovedAccountMapping | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [policyDrawerOpen, setPolicyDrawerOpen] = useState(false);
  const [policyFormOpen, setPolicyFormOpen] = useState(false);
  const [editingPolicy, setEditingPolicy] = useState<AccountingPolicy | null>(null);
  const [detail, setDetail] = useState<ApprovedAccountMapping | null>(null);
  const [status, setStatus] = useState<'all' | MappingStatus>('all');
  const selectedMappingKey = Form.useWatch('mapping_key', form);
  const selectedAccountRole = Form.useWatch('account_role', form);
  const selectedPolicyKey = Form.useWatch('policy_key', policyForm);
  const availableAccountRoles = selectedMappingKey === 'period_close.result'
    ? ['result_clearing', 'retained_earnings']
    : ACCOUNT_ROLE_TEMPLATES;

  const onMappingKeyChange = (value: string) => {
    const keys = mappingContextFields(value);
    form.setFieldsValue({
      context_entries: keys.map((key) => ({
        key,
        value: '',
        valueType: ['item_id', 'warehouse_id'].includes(key) ? 'number' : 'text',
      })),
    });
    form.setFieldValue('account_role', undefined);
  };

  const onAccountRoleChange = (value: string) => {
    if (!selectedMappingKey) return;
    const keys = mappingContextFields(selectedMappingKey, value);
    form.setFieldsValue({
      context_entries: keys.map((key) => ({
        key,
        value: '',
        valueType: ['item_id', 'warehouse_id'].includes(key) ? 'number' : 'text',
      })),
    });
  };

  const mappingsQuery = useQuery({
    queryKey: [...queryKey, status],
    enabled: canView,
    queryFn: async (): Promise<ApprovedAccountMapping[]> => {
      const { data } = await api.get('/approved-account-mappings', { params: status === 'all' ? undefined : { status } });
      return unwrapMappings(data);
    },
  });

  const policiesQuery = useQuery({
    queryKey: ['approved-accounting-policies'],
    enabled: canView && formOpen,
    queryFn: async (): Promise<ApprovedPolicyOption[]> => {
      const { data } = await api.get('/approved-account-mappings/policies');
      return unwrapCollection<ApprovedPolicyOption>(data, 'approved-account-mappings/policies');
    },
  });

  const policyListQuery = useQuery({
    queryKey: ['accounting-policies'],
    enabled: canView && policyDrawerOpen,
    queryFn: async (): Promise<AccountingPolicy[]> => {
      const { data } = await api.get('/accounting-policies', { params: undefined });
      return unwrapCollection<AccountingPolicy>(data, 'accounting-policies');
    },
  });

  const profilesQuery = useQuery({
    queryKey: ['accounting-policy-profiles'],
    enabled: canView && policyFormOpen,
    queryFn: async (): Promise<AccountingProfile[]> => {
      const { data } = await api.get('/accounting-policies/profiles');
      return unwrapCollection<AccountingProfile>(data, 'accounting-policies/profiles');
    },
  });

  const accountsQuery = useQuery({
    queryKey: ['chart-of-accounts', 'mapping-editor'],
    enabled: canView && (formOpen || policyFormOpen),
    queryFn: async (): Promise<AccountOption[]> => {
      const { data } = await api.get('/master/accounts', { params: { include_inactive: 0 } });
      return unwrapCollection<AccountOption>(data, 'master/accounts')
        .filter((account) => account.is_active !== false && account.is_parent !== true);
    },
  });

  const invalidate = async () => queryClient.invalidateQueries({ queryKey });
  const saveMutation = useMutation({
    mutationFn: async (values: MappingFormValues) => {
      const payload = toPayload(values);
      return editing ? api.put(`/approved-account-mappings/${editing.id}`, payload) : api.post('/approved-account-mappings', payload);
    },
    onSuccess: async (response) => {
      if (!hasPersistedMapping(response)) {
        message.error('Máy chủ chưa trả về mapping đã lưu. Không thể xác nhận thành công.');
        return;
      }
      message.success(editing ? 'Đã lưu dự thảo mapping.' : 'Đã tạo dự thảo mapping.');
      setFormOpen(false);
      setEditing(null);
      await invalidate();
    },
    onError: (error: unknown) => message.error(getApiErrorMessage(error, 'Không thể lưu. Máy chủ chưa chấp nhận dữ liệu mapping.')),
  });
  const approveMutation = useMutation({
    mutationFn: async (mapping: ApprovedAccountMapping) => api.post(`/approved-account-mappings/${mapping.id}/approve`),
    onSuccess: async (response) => {
      if (!hasPersistedMapping(response)) {
        message.error('Máy chủ chưa trả về mapping đã phê duyệt. Không thể xác nhận thành công.');
        return;
      }
      message.success('Đã gửi phê duyệt thành công.');
      await invalidate();
      setDetail(null);
    },
    onError: (error: unknown) => message.error(getApiErrorMessage(error, 'Không thể phê duyệt. Máy chủ từ chối thao tác.')),
  });

  const savePolicyMutation = useMutation({
    mutationFn: async (values: PolicyFormValues) => {
      const payload = {
      accounting_regime_profile_id: Number(values.accounting_regime_profile_id),
      policy_key: values.policy_key,
      policy_version: values.policy_version.trim(),
      effective_from: values.effective_range[0],
      effective_to: values.effective_range[1],
      source_accounts: values.policy_key === 'posting.period_closing' ? values.source_accounts : undefined,
      regulatory_dependencies: dependencies(values.regulatory_dependencies_text),
      };
      return editingPolicy ? api.put(`/accounting-policies/${editingPolicy.id}`, payload) : api.post('/accounting-policies', payload);
    },
    onSuccess: async (response) => {
      if (!hasPersistedPolicy(response)) {
        message.error('Máy chủ chưa trả về chính sách đã lưu. Không thể xác nhận thành công.');
        return;
      }
      message.success(editingPolicy ? 'Đã cập nhật dự thảo chính sách.' : 'Đã tạo dự thảo chính sách hạch toán.');
      setPolicyFormOpen(false);
      setEditingPolicy(null);
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['accounting-policies'] }),
        queryClient.invalidateQueries({ queryKey: ['approved-accounting-policies'] }),
      ]);
    },
    onError: (error: unknown) => {
      message.error(getApiErrorMessage(error, 'Không thể lưu chính sách hạch toán.'));
    },
  });
  const approvePolicyMutation = useMutation({
    mutationFn: async (policy: AccountingPolicy) => api.post(`/accounting-policies/${policy.id}/approve`),
    onSuccess: async (response) => {
      if (!hasPersistedPolicy(response)) {
        message.error('Máy chủ chưa trả về chính sách đã duyệt. Không thể xác nhận thành công.');
        return;
      }
      message.success('Đã duyệt chính sách hạch toán.');
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['accounting-policies'] }),
        queryClient.invalidateQueries({ queryKey: ['approved-accounting-policies'] }),
      ]);
    },
    onError: (error: unknown) => message.error(getApiErrorMessage(error, 'Không thể duyệt chính sách hạch toán.')),
  });

  const openCreate = () => {
    setEditing(null);
    form.resetFields();
    form.setFieldsValue({ context_entries: [], regulatory_dependencies_text: '', effective_range: ['', ''] });
    setFormOpen(true);
  };
  const openPolicyCreate = () => {
    setEditingPolicy(null);
    policyForm.resetFields();
    policyForm.setFieldsValue({ regulatory_dependencies_text: '', effective_range: ['', ''], source_accounts: [] });
    setPolicyFormOpen(true);
  };
  const openPolicyEdit = useCallback((policy: AccountingPolicy) => {
    setEditingPolicy(policy);
    policyForm.setFieldsValue({
      accounting_regime_profile_id: policy.accounting_regime_profile_id,
      policy_key: policy.policy_key,
      policy_version: policy.policy_version,
      effective_range: [policy.effective_from, policy.effective_to],
      source_accounts: policy.posting_rule_contract.source_accounts ?? [],
      regulatory_dependencies_text: policy.regulatory_dependencies.join('\n'),
    });
    setPolicyFormOpen(true);
  }, [policyForm]);
  const openEdit = useCallback((mapping: ApprovedAccountMapping) => {
    setEditing(mapping);
    form.setFieldsValue({
      accounting_policy_version_id: mapping.accounting_policy_version_id,
      mapping_key: mapping.mapping_key,
      context_entries: contextEntries(mapping.mapping_context),
      account_role: mapping.account_role,
      account_code: mapping.account_code,
      effective_range: [mapping.effective_from, mapping.effective_to],
      regulatory_dependencies_text: mapping.regulatory_dependencies.join('\n'),
    });
    setFormOpen(true);
  }, [form]);
  const canApproveMapping = useCallback((mapping: ApprovedAccountMapping) => canApprove && mapping.status === 'draft' && !mapping.is_immutable && mapping.created_by !== user?.id, [canApprove, user?.id]);
  const cannotSelfApprove = useCallback((mapping: ApprovedAccountMapping) => mapping.created_by === user?.id, [user?.id]);

  const columns = useMemo(() => [
    { title: 'Mapping', dataIndex: 'mapping_key', key: 'mapping_key', render: (value: string, record: ApprovedAccountMapping) => <Button type="link" onClick={() => setDetail(record)}>{value}</Button> },
    { title: 'Vai trò', dataIndex: 'account_role', key: 'account_role' },
    { title: 'Tài khoản', dataIndex: 'account_code', key: 'account_code', width: 120 },
    { title: 'Policy', dataIndex: 'accounting_policy_version_id', key: 'policy', width: 100, render: (id: number) => `#${id}` },
    { title: 'Hiệu lực', key: 'effective', width: 210, render: (_: unknown, record: ApprovedAccountMapping) => `${record.effective_from} → ${record.effective_to}` },
    { title: 'Trạng thái', dataIndex: 'status', key: 'status', width: 120, render: (value: MappingStatus) => <Tag color={value === 'approved' ? 'green' : 'gold'}>{value === 'approved' ? 'Đã phê duyệt' : 'Dự thảo'}</Tag> },
    {
      title: 'Thao tác', key: 'action', width: 185, render: (_: unknown, mapping: ApprovedAccountMapping) => (
        <Space size="small">
          <Button aria-label={`Xem mapping ${mapping.mapping_key}`} type="text" icon={<EyeOutlined />} onClick={() => setDetail(mapping)} />
          {canUpdate && mapping.status === 'draft' && !mapping.is_immutable && <Button aria-label={`Sửa mapping ${mapping.mapping_key}`} type="text" icon={<EditOutlined />} onClick={() => openEdit(mapping)} />}
          {canApproveMapping(mapping) && <Popconfirm title="Phê duyệt mapping này?" description="Sau khi phê duyệt, mapping là bất biến. Máy chủ sẽ kiểm tra lại maker-checker, policy, tài khoản và overlap." onConfirm={() => approveMutation.mutate(mapping)} okText="Phê duyệt" cancelText="Hủy"><Button type="text" icon={<CheckOutlined />} loading={approveMutation.isPending}>Phê duyệt</Button></Popconfirm>}
          {mapping.status === 'draft' && cannotSelfApprove(mapping) && <Typography.Text type="secondary">Chờ người khác duyệt</Typography.Text>}
        </Space>
      ),
    },
  ], [approveMutation, canApproveMapping, canUpdate, cannotSelfApprove, openEdit]);

  const policyColumns = useMemo(() => [
    { title: 'Nghiệp vụ', dataIndex: 'policy_key', key: 'policy_key', render: (value: string) => POLICY_TEMPLATES.find((item) => item.value === value)?.label ?? value },
    { title: 'Phiên bản', dataIndex: 'policy_version', key: 'policy_version', width: 130 },
    { title: 'Năm', dataIndex: 'fiscal_year', key: 'fiscal_year', width: 80 },
    { title: 'Hiệu lực', key: 'effective', width: 210, render: (_: unknown, policy: AccountingPolicy) => `${policy.effective_from} → ${policy.effective_to}` },
    { title: 'Trạng thái', dataIndex: 'status', key: 'status', width: 120, render: (value: MappingStatus) => <Tag color={value === 'approved' ? 'green' : 'gold'}>{value === 'approved' ? 'Đã duyệt' : 'Dự thảo'}</Tag> },
    {
      title: 'Thao tác', key: 'actions', width: 220, render: (_: unknown, policy: AccountingPolicy) => policy.status === 'draft'
        ? policy.created_by === user?.id
          ? <Space><Button aria-label={`Sửa chính sách ${policy.policy_version}`} type="text" icon={<EditOutlined />} onClick={() => openPolicyEdit(policy)} disabled={!canUpdate} /><Typography.Text type="secondary">Chờ admin duyệt</Typography.Text></Space>
          : canApprove
            ? <Popconfirm title="Duyệt chính sách này?" description="Sau khi duyệt, chính sách không thể sửa trực tiếp." okText="Duyệt" cancelText="Hủy" onConfirm={() => approvePolicyMutation.mutate(policy)}><Button type="link" loading={approvePolicyMutation.isPending}>Duyệt</Button></Popconfirm>
            : <Typography.Text type="secondary">Chờ duyệt</Typography.Text>
        : <Typography.Text type="success">Đang áp dụng</Typography.Text>,
    },
  ], [approvePolicyMutation, canApprove, canUpdate, openPolicyEdit, user?.id]);

  if (!canView) {
    return <PageShell title={<PageHeader eyebrow="Thiết lập" title="Mapping tài khoản được phê duyệt" description="Chứng cứ mapping theo policy và ngày hiệu lực; không tự sinh danh mục." />}><Alert type="warning" showIcon message="Bạn không có quyền xem mapping tài khoản" description={`Cần quyền ${permission.view}. Hệ thống không tải hay suy đoán dữ liệu khi chưa có quyền.`} /></PageShell>;
  }

  return (
    <PageShell title={<PageHeader eyebrow="Thiết lập" title="Cấu hình tài khoản hạch toán" description="Kế toán chuẩn bị cấu hình theo từng nghiệp vụ; admin kiểm tra và duyệt trước khi áp dụng." />} toolbar={<PageToolbar filters={<Space><span>Trạng thái:</span><Select value={status} onChange={setStatus} style={{ width: 160 }} options={[{ value: 'all', label: 'Tất cả' }, { value: 'draft', label: 'Dự thảo' }, { value: 'approved', label: 'Đã phê duyệt' }]} /></Space>} actions={<Space><Button icon={<ReloadOutlined />} onClick={() => void mappingsQuery.refetch()}>Tải lại</Button><Button icon={<SettingOutlined />} onClick={() => setPolicyDrawerOpen(true)}>Chính sách hạch toán</Button>{canCreate && <Button type="primary" className="misa-btn-primary" icon={<PlusOutlined />} onClick={openCreate}>Tạo dự thảo</Button>}</Space>} />}>
      {mappingsQuery.isError ? <Alert type="error" showIcon message="Không thể tải mapping tài khoản" description="Không hiển thị dữ liệu thay thế. Hãy kiểm tra quyền hoặc kết nối máy chủ." action={<Button size="small" onClick={() => void mappingsQuery.refetch()}>Thử lại mapping</Button>} /> : <DataTableSurface className="approved-mappings-table-surface"><Table rowKey="id" columns={columns} dataSource={mappingsQuery.data ?? []} loading={mappingsQuery.isLoading} pagination={{ pageSize: 50 }} /></DataTableSurface>}

      <Modal title={editing ? 'Sửa dự thảo mapping' : 'Tạo dự thảo mapping'} open={formOpen} onCancel={() => { setFormOpen(false); setEditing(null); }} onOk={() => form.submit()} okText={editing ? 'Lưu dự thảo' : 'Tạo dự thảo'} confirmLoading={saveMutation.isPending} width={760} destroyOnHidden>
        <ModalFrame>
        <Alert className="mb-4" type="info" showIcon message="Chọn đúng nghiệp vụ và tài khoản" description="Cấu hình chỉ có hiệu lực sau khi được admin duyệt. Các điều kiện chi tiết được nhập theo từng trường, không cần chỉnh sửa JSON." />
        {policiesQuery.isError && <Alert className="mb-4" type="error" showIcon message="Không tải được policy đã duyệt" description="Không thể chọn policy khi máy chủ chưa trả về dữ liệu; không tự tạo policy thay thế." action={<Button size="small" onClick={() => void policiesQuery.refetch()}>Thử lại policy</Button>} />}
        {accountsQuery.isError && <Alert className="mb-4" type="error" showIcon message="Không tải được danh mục tài khoản" description="Không thể chọn tài khoản khi danh mục chưa được xác minh; không nhập mã thay thế." action={<Button size="small" onClick={() => void accountsQuery.refetch()}>Thử lại danh mục tài khoản</Button>} />}
        <Form form={form} layout="vertical" onFinish={(values) => saveMutation.mutate(values)}>
          <div className="grid grid-cols-2 gap-4"><Form.Item name="accounting_policy_version_id" label="Chính sách hạch toán đã duyệt" rules={[{ required: true, message: 'Chọn chính sách đã được duyệt.' }]} extra={policiesQuery.isError ? 'Không tải được chính sách; kiểm tra quyền hoặc kết nối máy chủ.' : undefined}><Select showSearch optionFilterProp="label" loading={policiesQuery.isLoading} placeholder={selectedMappingKey ? 'Chọn chính sách phù hợp' : 'Chọn loại mapping trước'} disabled={!selectedMappingKey} options={policiesQuery.data?.filter((policy) => (POLICY_BY_MAPPING[selectedMappingKey] ?? []).includes(policy.policy_key)).map((policy) => ({ value: policy.id, label: `${POLICY_TEMPLATES.find((item) => item.value === policy.policy_key)?.label ?? policy.policy_key} · ${policy.policy_version}` }))} onChange={(value) => { const policy = policiesQuery.data?.find((candidate) => candidate.id === value); if (policy) form.setFieldsValue({ effective_range: [policy.effective_from, policy.effective_to] }); }} notFoundContent={policiesQuery.isLoading ? 'Đang tải...' : 'Chưa có chính sách phù hợp đã được duyệt'} /></Form.Item><Form.Item name="mapping_key" label="Loại mapping" rules={[{ required: true, message: 'Chọn loại mapping.' }]}><Select showSearch optionFilterProp="label" placeholder="Chọn loại mapping" options={MAPPING_TEMPLATES} onChange={(value) => { onMappingKeyChange(value); form.setFieldValue('accounting_policy_version_id', undefined); }} /></Form.Item></div>
          <div className="grid grid-cols-2 gap-4"><Form.Item name="account_role" label="Vai trò tài khoản" rules={[{ required: true, message: 'Chọn vai trò tài khoản.' }]}><Select showSearch optionFilterProp="label" placeholder="Chọn vai trò" options={availableAccountRoles.map((value) => ({ value, label: value }))} onChange={onAccountRoleChange} /></Form.Item><Form.Item name="account_code" label="Tài khoản trong danh mục" rules={[{ required: true, message: 'Chọn tài khoản.' }]}><Select showSearch optionFilterProp="label" loading={accountsQuery.isLoading} placeholder="Chọn tài khoản" options={accountsQuery.data?.map((account) => ({ value: account.code, label: `${account.code} · ${account.name}` }))} notFoundContent={accountsQuery.isLoading ? 'Đang tải...' : 'Chưa có tài khoản hạch toán phù hợp'} /></Form.Item></div>
          <div className="grid grid-cols-2 gap-4"><Form.Item name={['effective_range', 0]} label="Hiệu lực từ" rules={[{ required: true }]}><Input type="date" /></Form.Item><Form.Item name={['effective_range', 1]} label="Hiệu lực đến" dependencies={['effective_range', 0]} rules={[{ required: true }, ({ getFieldValue }) => ({ validator(_, value) { const from = getFieldValue(['effective_range', 0]); return !value || !from || value >= from ? Promise.resolve() : Promise.reject(new Error('Ngày kết thúc phải không trước ngày bắt đầu.')); } })]}><Input type="date" /></Form.Item></div>
          <Form.Item label="Điều kiện context" extra="Khai báo từng trường mà backend dùng để phân biệt mapping. Giá trị số (ví dụ item_id, warehouse_id) được giữ đúng kiểu; không cần sửa JSON.">
            <Form.List name="context_entries">
              {(fields, { add, remove }) => <Space direction="vertical" className="w-full" size="small">
                {selectedMappingKey === 'period_close.result' ? <Alert type="info" showIcon message="Kết chuyển cuối kỳ dùng context trống" description="Hai vai trò kết quả kinh doanh được phân giải trực tiếp theo policy và ngày hiệu lực; không cần nhập điều kiện JSON." /> : fields.map((field) => <Space key={field.key} align="start" className="w-full" wrap>
                  <Form.Item name={[field.name, 'key']} rules={[{ required: true, message: 'Nhập tên trường.' }]}><Input placeholder="Tên trường, ví dụ item_id" /></Form.Item>
                  <Form.Item name={[field.name, 'valueType']} initialValue="text"><Select style={{ width: 120 }} options={[{ value: 'text', label: 'Văn bản' }, { value: 'number', label: 'Số' }, { value: 'boolean', label: 'Đúng/Sai' }]} /></Form.Item>
                  <Form.Item name={[field.name, 'value']} rules={[{ required: true, message: 'Nhập giá trị.' }]}><Input placeholder="Giá trị" /></Form.Item>
                  <Button type="text" danger onClick={() => remove(field.name)}>Xóa</Button>
                </Space>)}
                {selectedMappingKey !== 'period_close.result' && mappingContextFields(selectedMappingKey, selectedAccountRole).length > 0 && <Button type="link" className="!px-0" onClick={() => { const keys = mappingContextFields(selectedMappingKey, selectedAccountRole); form.setFieldsValue({ context_entries: keys.map((key) => ({ key, value: '', valueType: ['item_id', 'warehouse_id'].includes(key) ? 'number' : 'text' })) }); }}>Nạp trường context theo mẫu vai trò</Button>}
                {selectedMappingKey !== 'period_close.result' && <Button type="dashed" onClick={() => add({ key: '', value: '', valueType: 'text' })}>Thêm điều kiện context</Button>}
              </Space>}
            </Form.List>
          </Form.Item>
          <Form.Item name="regulatory_dependencies_text" label="REGULATORY DEPENDENCY (mỗi dòng một văn bản/điều kiện)" extra="Để trống nếu owner đã xác định tường minh rằng không có dependency ngoài policy; backend sẽ lưu []. UI không diễn giải nghĩa vụ thuế từ chế độ kế toán."><Input.TextArea rows={3} placeholder="Ví dụ: Thông tư 99/2025/TT-BTC — cần owner xác minh phạm vi áp dụng" /></Form.Item>
        </Form>
        </ModalFrame>
      </Modal>

      <Drawer title="Danh sách chính sách hạch toán" open={policyDrawerOpen} onClose={() => setPolicyDrawerOpen(false)} size={920} extra={canCreate ? <Button type="primary" icon={<PlusOutlined />} onClick={openPolicyCreate}>Tạo chính sách</Button> : undefined}>
        <Alert className="mb-4" type="info" showIcon message="Quy trình hai người" description="Kế toán lập dự thảo bằng các trường nghiệp vụ. Admin khác người lập kiểm tra và duyệt; bản đã duyệt không sửa trực tiếp." />
        {policyListQuery.isError
          ? <Alert type="error" showIcon message="Không thể tải chính sách hạch toán" description="Hệ thống không dùng dữ liệu giả thay thế. Hãy kiểm tra quyền hoặc kết nối máy chủ." action={<Button size="small" onClick={() => void policyListQuery.refetch()}>Thử lại chính sách</Button>} />
          : <Table rowKey="id" size="small" columns={policyColumns} dataSource={policyListQuery.data ?? []} loading={policyListQuery.isLoading} pagination={{ pageSize: 20 }} />}
      </Drawer>

      <Modal title={editingPolicy ? 'Sửa dự thảo chính sách' : 'Tạo chính sách hạch toán'} open={policyFormOpen} onCancel={() => { setPolicyFormOpen(false); setEditingPolicy(null); }} onOk={() => policyForm.submit()} okText={editingPolicy ? 'Lưu dự thảo' : 'Tạo dự thảo'} confirmLoading={savePolicyMutation.isPending} width={820} destroyOnHidden>
        <ModalFrame>
          {profilesQuery.isError && <Alert className="mb-4" type="error" showIcon message="Không tải được năm tài chính" description="Không thể chọn kỳ áp dụng khi máy chủ chưa trả về profile; không tự chọn năm thay thế." action={<Button size="small" onClick={() => void profilesQuery.refetch()}>Thử lại năm tài chính</Button>} />}
          {accountsQuery.isError && <Alert className="mb-4" type="error" showIcon message="Không tải được danh mục tài khoản" description="Không thể chọn tài khoản nguồn khi danh mục chưa được xác minh; không nhập mã thay thế." action={<Button size="small" onClick={() => void accountsQuery.refetch()}>Thử lại danh mục tài khoản</Button>} />}
          <Form form={policyForm} layout="vertical" onFinish={(values) => savePolicyMutation.mutate(values)}>
            <div className="grid grid-cols-2 gap-4">
              <Form.Item name="accounting_regime_profile_id" label="Năm tài chính" rules={[{ required: true, message: 'Chọn năm tài chính.' }]}>
                <Select showSearch optionFilterProp="label" loading={profilesQuery.isLoading} placeholder="Chọn năm tài chính" options={profilesQuery.data?.map((profile) => ({ value: profile.id, label: `${profile.fiscal_year} · ${profile.regime_label || profile.regime}` }))} onChange={(id) => { const profile = profilesQuery.data?.find((item) => item.id === id); if (profile) policyForm.setFieldValue('effective_range', [profile.effective_from, profile.effective_to]); }} />
              </Form.Item>
              <Form.Item name="policy_key" label="Loại nghiệp vụ" rules={[{ required: true, message: 'Chọn loại nghiệp vụ.' }]}>
                <Select showSearch optionFilterProp="label" placeholder="Chọn nghiệp vụ" options={POLICY_TEMPLATES} onChange={() => policyForm.setFieldValue('source_accounts', [])} />
              </Form.Item>
            </div>
            <Form.Item name="policy_version" label="Mã phiên bản" rules={[{ required: true, message: 'Nhập mã phiên bản.' }, { max: 80 }]} extra="Ví dụ: 2026.1. Khi thay đổi cấu hình đã duyệt, hãy tạo phiên bản mới."><Input placeholder="2026.1" /></Form.Item>
            <div className="grid grid-cols-2 gap-4">
              <Form.Item name={['effective_range', 0]} label="Hiệu lực từ" rules={[{ required: true, message: 'Chọn ngày bắt đầu.' }]}><Input type="date" /></Form.Item>
              <Form.Item name={['effective_range', 1]} label="Hiệu lực đến" rules={[{ required: true, message: 'Chọn ngày kết thúc.' }]}><Input type="date" /></Form.Item>
            </div>
            {selectedPolicyKey === 'posting.period_closing' && <Form.Item label="Tài khoản nguồn kết chuyển" required extra="Chỉ chọn tài khoản chi tiết đang sử dụng; mỗi tài khoản chỉ khai báo một lần.">
              <Form.List name="source_accounts" rules={[{ validator: async (_, rows) => { if (!rows?.length) throw new Error('Thêm ít nhất một tài khoản nguồn kết chuyển.'); } }]}>
                {(fields, { add, remove }, { errors }) => <Space direction="vertical" className="w-full">
                  {fields.map((field) => <div key={field.key} className="grid grid-cols-[1fr_180px_auto] gap-3 items-start">
                    <Form.Item name={[field.name, 'account_code']} rules={[{ required: true, message: 'Chọn tài khoản.' }]}><Select showSearch optionFilterProp="label" loading={accountsQuery.isLoading} placeholder="Chọn tài khoản nguồn" options={accountsQuery.data?.map((account) => ({ value: account.code, label: `${account.code} · ${account.name}` }))} /></Form.Item>
                    <Form.Item name={[field.name, 'category']} rules={[{ required: true, message: 'Chọn nhóm.' }]}><Select placeholder="Chọn nhóm" options={[{ value: 'revenue', label: 'Doanh thu' }, { value: 'expense', label: 'Chi phí' }]} /></Form.Item>
                    <Button type="text" danger onClick={() => remove(field.name)}>Xóa</Button>
                  </div>)}
                  <Form.ErrorList errors={errors} />
                  <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ account_code: undefined, category: undefined })}>Thêm tài khoản nguồn</Button>
                </Space>}
              </Form.List>
            </Form.Item>}
            <Form.Item name="regulatory_dependencies_text" label="Căn cứ/ghi chú kiểm soát (mỗi dòng một nội dung)" extra="Có thể để trống nếu doanh nghiệp không cần ghi chú bổ sung."><Input.TextArea rows={3} /></Form.Item>
          </Form>
        </ModalFrame>
      </Modal>

      <Drawer title="Chi tiết mapping tài khoản" open={detail !== null} onClose={() => setDetail(null)} size={640}>
      {detail && <>
          {detail.is_immutable ? <Alert className="mb-4" type="success" showIcon message="Bản ghi đã phê duyệt và bất biến" description={detail.limitation} /> : <Typography.Text className="mb-4 block" type="secondary">Bản ghi đang là dự thảo. {detail.limitation}</Typography.Text>}
          <Descriptions bordered column={1} size="small"><Descriptions.Item label="ID">{detail.id}</Descriptions.Item><Descriptions.Item label="Policy">#{detail.accounting_policy_version_id}</Descriptions.Item><Descriptions.Item label="Mapping / vai trò">{detail.mapping_key} / {detail.account_role}</Descriptions.Item><Descriptions.Item label="Tài khoản">{detail.account_code}</Descriptions.Item><Descriptions.Item label="Hiệu lực">{detail.effective_from} → {detail.effective_to}</Descriptions.Item><Descriptions.Item label="Người lập">#{detail.created_by}</Descriptions.Item><Descriptions.Item label="Người duyệt">{detail.approved_by ? `#${detail.approved_by}` : '—'}</Descriptions.Item><Descriptions.Item label="Thời điểm duyệt">{toDateTime(detail.approved_at)}</Descriptions.Item><Descriptions.Item label="Context hash"><Typography.Text copyable>{detail.context_hash}</Typography.Text></Descriptions.Item><Descriptions.Item label="Contract hash">{detail.contract_hash ? <Typography.Text copyable>{detail.contract_hash}</Typography.Text> : 'Chưa ký'}</Descriptions.Item><Descriptions.Item label="Context"><pre className="m-0 whitespace-pre-wrap">{stringifyJson(detail.mapping_context)}</pre></Descriptions.Item><Descriptions.Item label="REGULATORY DEPENDENCY">{detail.regulatory_dependencies.length ? <ul className="m-0 pl-4">{detail.regulatory_dependencies.map((item) => <li key={item}>{item}</li>)}</ul> : '[]'}</Descriptions.Item></Descriptions>
          {canApproveMapping(detail) && <Popconfirm title="Phê duyệt mapping này?" onConfirm={() => approveMutation.mutate(detail)} okText="Phê duyệt" cancelText="Hủy"><Button className="mt-4" type="primary" icon={<CheckOutlined />} loading={approveMutation.isPending}>Phê duyệt</Button></Popconfirm>}
          {detail.status === 'draft' && cannotSelfApprove(detail) && <Alert className="mt-4" type="warning" showIcon message="Không thể tự phê duyệt" description="Dự thảo do chính bạn lập; một người có quyền phê duyệt khác phải thực hiện thao tác này." />}
        </>}
      </Drawer>
    </PageShell>
  );
};

export default ApprovedAccountMappings;
