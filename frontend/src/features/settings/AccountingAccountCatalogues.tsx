import React from 'react';
import { Alert, Button, Form, Input, InputNumber, Popconfirm, Select, Space, Switch, Table, Tabs, Tag } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import PageHeader from '../../components/layout/PageHeader';
import PageShell from '../../components/layout/PageShell';
import ModalFrame from '../../components/layout/ModalFrame';
import DataTableSurface from '../../components/layout/DataTableSurface';

type AccountRow = {
  id: number;
  code: string;
  name: string;
  type?: string;
  nature?: string;
  level?: number;
  parent_code?: string | null;
  is_parent?: boolean;
  is_active?: boolean;
};

type DefaultAccountRow = {
  id: number | null;
  name: string;
  voucher_type: string;
  debit_account?: string | null;
  credit_account?: string | null;
  is_system?: boolean;
  is_active?: boolean;
};

type ClosingRuleRow = {
  id: number;
  rule_code: string;
  rule_name: string;
  rule_type?: string;
  debit_account?: string | null;
  credit_account?: string | null;
  source_account?: string | null;
  target_account?: string | null;
  sequence?: number;
  is_active?: boolean;
};

function readArray<T>(payload: unknown, label: string): T[] {
  if (Array.isArray(payload)) return payload as T[];
  if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
    return (payload as { data: T[] }).data;
  }
  throw new Error(`Invalid ${label} response.`);
}

const isDepositVoucherType = (value: unknown): boolean => value === 'thu_tien_gui' || value === 'chi_tien_gui';

const AccountStatus: React.FC<{ active?: boolean }> = ({ active }) => (
  <Tag color={active ? 'green' : 'default'}>{active ? 'Đang sử dụng' : 'Ngừng/Chưa rõ'}</Tag>
);

const AccountingAccountCatalogues: React.FC = () => {
  const queryClient = useQueryClient();
  const [defaultModalOpen, setDefaultModalOpen] = React.useState(false);
  const [closingModalOpen, setClosingModalOpen] = React.useState(false);
  const [editingDefault, setEditingDefault] = React.useState<DefaultAccountRow | null>(null);
  const [editingClosing, setEditingClosing] = React.useState<ClosingRuleRow | null>(null);
  const [accountSearch, setAccountSearch] = React.useState('');
  const [defaultSearch, setDefaultSearch] = React.useState('');
  const [closingSearch, setClosingSearch] = React.useState('');
  const [defaultForm] = Form.useForm();
  const [closingForm] = Form.useForm();
  const accounts = useQuery<AccountRow[]>({
    queryKey: ['account-catalogue'],
    queryFn: async () => readArray<AccountRow>((await api.get('/master/accounts')).data, 'account catalogue'),
  });
  const voucherTypes = useQuery<{ value: string; label: string }[]>({
    queryKey: ['voucher-type-settings', 'types'],
    queryFn: async () => readArray<{ value: string; label: string }>((await api.get('/master/voucher-type-settings/types')).data, 'voucher-type catalogue'),
  });
  const defaults = useQuery<DefaultAccountRow[]>({
    queryKey: ['voucher-type-settings', 'all'],
    queryFn: async () => {
      const typePayload = (await api.get('/master/voucher-type-settings/types')).data;
      const types = readArray<{ value: string }>(typePayload, 'voucher-type catalogue')
        .filter((type) => !isDepositVoucherType(type.value));
      const responses = await Promise.all(types.map((type) => api.get(`/master/voucher-type-settings?voucher_type=${encodeURIComponent(type.value)}&include_inactive=1`)));
      return responses.flatMap((response) => readArray<DefaultAccountRow>(response.data, 'default-account catalogue'));
    },
  });
  const closingRules = useQuery<ClosingRuleRow[]>({
    queryKey: ['closing-rules'],
    queryFn: async () => readArray<ClosingRuleRow>((await api.get('/master/closing-rules')).data, 'closing-account catalogue'),
  });

  const error = accounts.error || defaults.error || closingRules.error;
  const supportedVoucherTypes = (voucherTypes.data ?? []).filter((type) => !isDepositVoucherType(type.value));
  const accountOptions = (accounts.data ?? [])
    .filter((account) => account.is_active !== false && account.is_parent !== true)
    .map((account) => ({ value: account.code, label: `${account.code} — ${account.name}` }));
  const allAccountOptions = (accounts.data ?? [])
    .filter((account) => account.is_active !== false && account.is_parent !== true)
    .map((account) => ({ value: account.code, label: `${account.code} — ${account.name}` }));
  const normalizedAccountSearch = accountSearch.trim().toLowerCase();
  const normalizedDefaultSearch = defaultSearch.trim().toLowerCase();
  const normalizedClosingSearch = closingSearch.trim().toLowerCase();
  const visibleAccounts = (accounts.data ?? []).filter((account) => !normalizedAccountSearch
    || `${account.code} ${account.name}`.toLowerCase().includes(normalizedAccountSearch));
  const visibleDefaults = (defaults.data ?? []).filter((row) => !isDepositVoucherType(row.voucher_type)
    && (!normalizedDefaultSearch
    || `${row.voucher_type} ${row.name} ${row.debit_account ?? ''} ${row.credit_account ?? ''}`.toLowerCase().includes(normalizedDefaultSearch)));
  const visibleClosingRules = (closingRules.data ?? []).filter((row) => !normalizedClosingSearch
    || `${row.rule_code} ${row.rule_name} ${row.source_account ?? ''} ${row.target_account ?? ''}`.toLowerCase().includes(normalizedClosingSearch));
  const defaultMutation = useMutation({
    mutationFn: async ({ values, id }: { values: Record<string, unknown>; id?: number }) => {
      const response = id
        ? await api.put(`/master/voucher-type-settings/${id}`, values)
        : await api.post('/master/voucher-type-settings', values);
      const entity = response.data?.data ?? response.data;
      if (!entity || !Number.isInteger(entity.id)) {
        throw new Error('Máy chủ không trả về tài khoản ngầm định đã lưu.');
      }
      return entity;
    },
    onSuccess: (_entity, variables) => {
      message.success(variables.id ? 'Đã cập nhật tài khoản ngầm định từ máy chủ.' : 'Đã lưu tài khoản ngầm định từ máy chủ.');
      setDefaultModalOpen(false);
      setEditingDefault(null);
      defaultForm.resetFields();
      queryClient.invalidateQueries({ queryKey: ['voucher-type-settings'] });
    },
    onError: () => message.error('Không thể lưu tài khoản ngầm định.'),
  });
  const deleteDefaultMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.delete(`/master/voucher-type-settings/${id}`);
      if (response.status !== 204 && typeof response.data?.message !== 'string') {
        throw new Error('Máy chủ không xác nhận xóa tài khoản ngầm định.');
      }
    },
    onSuccess: () => {
      message.success('Đã xóa tài khoản ngầm định từ máy chủ.');
      queryClient.invalidateQueries({ queryKey: ['voucher-type-settings'] });
    },
    onError: () => message.error('Không thể xóa tài khoản ngầm định.'),
  });
  const promoteDefaultMutation = useMutation({
    mutationFn: async (row: DefaultAccountRow) => {
      if (isDepositVoucherType(row.voucher_type)) {
        throw new Error('Tiền gửi nằm ngoài phạm vi catalogue tiền mặt nội bộ.');
      }
      const response = await api.post('/master/voucher-type-settings', {
        voucher_type: row.voucher_type,
        name: row.name,
        debit_account: row.debit_account ?? null,
        credit_account: row.credit_account ?? null,
        filter_debit: null,
        filter_credit: null,
        is_active: true,
      });
      const entity = response.data?.data ?? response.data;
      if (!entity || !Number.isInteger(entity.id)) {
        throw new Error('Máy chủ không trả về cấu hình nội bộ đã lưu.');
      }
      return entity;
    },
    onSuccess: () => {
      message.info('Đã tạo bản cấu hình nội bộ từ mẫu máy chủ; chưa cấp quyền ghi sổ.');
      queryClient.invalidateQueries({ queryKey: ['voucher-type-settings'] });
    },
    onError: () => message.error('Không thể tạo bản cấu hình: tài khoản chưa có bằng chứng hợp lệ từ máy chủ.'),
  });
  const closingMutation = useMutation({
    mutationFn: async ({ values, id }: { values: Record<string, unknown>; id?: number }) => {
      const response = id
        ? await api.put(`/master/closing-rules/${id}`, values)
        : await api.post('/master/closing-rules', values);
      const entity = response.data?.data ?? response.data;
      if (!entity || !Number.isInteger(entity.id)) {
        throw new Error('Máy chủ không trả về quy tắc kết chuyển đã lưu.');
      }
      return entity;
    },
    onSuccess: (_entity, variables) => {
      message.success(variables.id ? 'Đã cập nhật quy tắc kết chuyển từ máy chủ.' : 'Đã lưu quy tắc kết chuyển từ máy chủ.');
      setClosingModalOpen(false);
      setEditingClosing(null);
      closingForm.resetFields();
      queryClient.invalidateQueries({ queryKey: ['closing-rules'] });
    },
    onError: () => message.error('Không thể lưu quy tắc kết chuyển.'),
  });
  const deleteClosingMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.delete(`/master/closing-rules/${id}`);
      if (response.status !== 204 && typeof response.data?.message !== 'string') {
        throw new Error('Máy chủ không xác nhận xóa quy tắc kết chuyển.');
      }
    },
    onSuccess: () => {
      message.success('Đã xóa quy tắc kết chuyển từ máy chủ.');
      queryClient.invalidateQueries({ queryKey: ['closing-rules'] });
    },
    onError: () => message.error('Không thể xóa quy tắc kết chuyển.'),
  });

  const openDefaultCreate = () => {
    setEditingDefault(null);
    defaultForm.resetFields();
    defaultForm.setFieldsValue({ is_active: true });
    setDefaultModalOpen(true);
  };
  const openDefaultEdit = (row: DefaultAccountRow) => {
    if (!Number.isInteger(row.id)) return;
    setEditingDefault(row);
    defaultForm.setFieldsValue(row);
    setDefaultModalOpen(true);
  };
  const openClosingCreate = () => {
    setEditingClosing(null);
    closingForm.resetFields();
    closingForm.setFieldsValue({ is_active: true });
    setClosingModalOpen(true);
  };
  const openClosingEdit = (row: ClosingRuleRow) => {
    if (!Number.isInteger(row.id)) return;
    setEditingClosing(row);
    closingForm.setFieldsValue(row);
    setClosingModalOpen(true);
  };

  return (
     <PageShell
       className="apple-settings-page"
       title={<PageHeader
        eyebrow="Thiết lập hệ thống"
        title="Tài khoản kế toán"
        description="Catalogue nội bộ lấy từ máy chủ: hệ thống tài khoản, tài khoản ngầm định và tài khoản kết chuyển."
        extra={<Space wrap>
          <Link to="/master/accounts"><Button>Mở hệ thống tài khoản</Button></Link>
          <Link to="/settings/account-mappings"><Button>Mapping được duyệt</Button></Link>
          <Button type="primary" disabled={accountOptions.length === 0 || supportedVoucherTypes.length === 0} onClick={openDefaultCreate}>
            Thêm tài khoản ngầm định
          </Button>
          <Button disabled={allAccountOptions.length === 0} onClick={openClosingCreate}>
            Thêm quy tắc kết chuyển
          </Button>
        </Space>}
      />}
    >

      <Alert
        className="mb-4"
        type="warning"
        showIcon
        message="Thiết lập nội bộ — chưa cấp quyền ghi sổ"
        description="Các dòng bên dưới là dữ liệu/cấu hình máy chủ hoặc mẫu hệ thống. Không tự xem mã tài khoản là mapping được duyệt; kết chuyển và ghi sổ vẫn qua gate riêng."
      />
      {error && <Alert className="mb-4" type="error" showIcon message="Không tải được catalogue tài khoản" description="Không có dữ liệu thay thế từ giao diện." />}

      <Tabs items={[
        {
          key: 'accounts',
          label: `Hệ thống tài khoản (${accounts.data?.length ?? 0})`,
          children: (
            <Space direction="vertical" className="w-full">
              <Input allowClear placeholder="Tìm theo mã hoặc tên tài khoản" value={accountSearch} onChange={(event) => setAccountSearch(event.target.value)} />
              <DataTableSurface summary={null}>
              <Table<AccountRow>
                rowKey="id"
                loading={accounts.isLoading}
                dataSource={visibleAccounts}
                pagination={{ pageSize: 25, showSizeChanger: false }}
                columns={[
                  { title: 'Mã TK', dataIndex: 'code', key: 'code' },
                  { title: 'Tên tài khoản', dataIndex: 'name', key: 'name' },
                  { title: 'Loại', dataIndex: 'type', key: 'type', render: (value) => value || '—' },
                  { title: 'Tính chất', dataIndex: 'nature', key: 'nature', render: (value) => value || '—' },
                  { title: 'TK cha', dataIndex: 'parent_code', key: 'parent_code', render: (value) => value || '—' },
                  { title: 'Trạng thái', dataIndex: 'is_active', key: 'is_active', render: (value) => <AccountStatus active={value} /> },
                ]}
              />
              </DataTableSurface>
            </Space>
          ),
        },
        {
          key: 'defaults',
          label: `Tài khoản ngầm định (${defaults.data?.length ?? 0})`,
          children: (
            <Space direction="vertical" className="w-full">
              <Input allowClear placeholder="Tìm theo loại, nội dung hoặc mã tài khoản" value={defaultSearch} onChange={(event) => setDefaultSearch(event.target.value)} />
              <DataTableSurface summary={null}>
              <Table<DefaultAccountRow>
                rowKey={(row) => `${row.voucher_type}-${row.name}`}
                loading={defaults.isLoading}
                dataSource={visibleDefaults}
                pagination={{ pageSize: 20, showSizeChanger: false }}
                columns={[
                  { title: 'Loại chứng từ', dataIndex: 'voucher_type', key: 'voucher_type' },
                  { title: 'Nội dung', dataIndex: 'name', key: 'name' },
                  { title: 'TK Nợ', dataIndex: 'debit_account', key: 'debit_account', render: (value) => value || '—' },
                  { title: 'TK Có', dataIndex: 'credit_account', key: 'credit_account', render: (value) => value || '—' },
                  { title: 'Nguồn', key: 'source', render: (_, row) => row.is_system || row.id === null ? <Tag color="blue">Mẫu máy chủ</Tag> : <Tag color="green">Đã lưu</Tag> },
                  {
                    title: 'Chức năng',
                    key: 'actions',
                    render: (_, row) => row.id === null ? (
                      <Button type="link" onClick={() => promoteDefaultMutation.mutate(row)}>
                        Tạo bản nội bộ
                      </Button>
                    ) : (
                      <Space>
                        <Button type="link" onClick={() => openDefaultEdit(row)}>Sửa</Button>
                        <Popconfirm title="Xóa cấu hình này?" onConfirm={() => deleteDefaultMutation.mutate(row.id as number)}>
                          <Button type="link" danger>Xóa</Button>
                        </Popconfirm>
                      </Space>
                    ),
                  },
                ]}
              />
              </DataTableSurface>
            </Space>
          ),
        },
        {
          key: 'closing',
          label: `Tài khoản kết chuyển (${closingRules.data?.length ?? 0})`,
          children: (
            <Space direction="vertical" className="w-full">
              <Input allowClear placeholder="Tìm mã, nội dung hoặc tài khoản nguồn/đích" value={closingSearch} onChange={(event) => setClosingSearch(event.target.value)} />
              <DataTableSurface summary={null}>
              <Table<ClosingRuleRow>
                rowKey="id"
                loading={closingRules.isLoading}
                dataSource={visibleClosingRules}
                pagination={{ pageSize: 20, showSizeChanger: false }}
                columns={[
                  { title: 'Mã quy tắc', dataIndex: 'rule_code', key: 'rule_code' },
                  { title: 'Nội dung', dataIndex: 'rule_name', key: 'rule_name' },
                  { title: 'Nguồn', dataIndex: 'source_account', key: 'source_account', render: (value) => value || '—' },
                  { title: 'Đích', dataIndex: 'target_account', key: 'target_account', render: (value) => value || '—' },
                  { title: 'Định khoản', key: 'entry', render: (_, row) => `${row.debit_account || '—'} / ${row.credit_account || '—'}` },
                  { title: 'Trạng thái', key: 'status', render: (_, row) => <Tag color={row.is_active ? 'orange' : 'default'}>{row.is_active ? 'Chưa phê duyệt thực thi' : 'Tắt'}</Tag> },
                  {
                    title: 'Chức năng',
                    key: 'actions',
                    render: (_, row) => (
                      <Space>
                        <Button type="link" onClick={() => openClosingEdit(row)}>Sửa</Button>
                        <Popconfirm title="Xóa quy tắc này?" onConfirm={() => deleteClosingMutation.mutate(row.id)}>
                          <Button type="link" danger>Xóa</Button>
                        </Popconfirm>
                      </Space>
                    ),
                  },
                ]}
              />
              </DataTableSurface>
            </Space>
          ),
        },
      ]} />

      <Modal
        title={editingDefault ? 'Sửa tài khoản ngầm định' : 'Thêm tài khoản ngầm định'}
        open={defaultModalOpen}
        confirmLoading={defaultMutation.isPending}
        onCancel={() => { setDefaultModalOpen(false); setEditingDefault(null); defaultForm.resetFields(); }}
        onOk={() => defaultForm.submit()}
      >
        <ModalFrame>
        <Form form={defaultForm} layout="vertical" onFinish={(values) => defaultMutation.mutate({ values, id: editingDefault?.id ?? undefined })}>
          <Form.Item name="voucher_type" label="Loại chứng từ tiền mặt" rules={[{ required: true }]}>
            <Select options={supportedVoucherTypes} placeholder="Chọn loại chứng từ trong phạm vi tiền mặt" />
          </Form.Item>
          <Form.Item name="name" label="Tên nghiệp vụ" rules={[{ required: true }]}>
            <Input />
          </Form.Item>
           <Space className="w-full" align="start">
             <Form.Item
               name="debit_account"
               label="TK Nợ"
               className="flex-1"
               dependencies={['credit_account']}
               rules={[({ getFieldValue }) => ({
                 validator: async (_rule, value) => {
                   if (value || getFieldValue('credit_account')) return;
                   throw new Error('Chọn ít nhất TK Nợ hoặc TK Có từ catalogue máy chủ.');
                 },
               })]}
             >
               <Select allowClear placeholder="Chọn từ catalogue máy chủ" options={accountOptions} />
            </Form.Item>
            <Form.Item
              name="credit_account"
              label="TK Có"
              className="flex-1"
              dependencies={['debit_account']}
              rules={[({ getFieldValue }) => ({
                validator: async (_rule, value) => {
                  if (value || getFieldValue('debit_account')) return;
                  throw new Error('Chọn ít nhất TK Nợ hoặc TK Có từ catalogue máy chủ.');
                },
              })]}
            >
               <Select allowClear placeholder="Chọn từ catalogue máy chủ" options={accountOptions} />
             </Form.Item>
           </Space>
           <Form.Item name="is_active" label="Đang sử dụng" valuePropName="checked"><Switch /></Form.Item>
         </Form>
         </ModalFrame>
      </Modal>
      <Modal
        title={editingClosing ? 'Sửa quy tắc kết chuyển' : 'Thêm quy tắc kết chuyển'}
        open={closingModalOpen}
        confirmLoading={closingMutation.isPending}
        onCancel={() => { setClosingModalOpen(false); setEditingClosing(null); closingForm.resetFields(); }}
        onOk={() => closingForm.submit()}
      >
        <ModalFrame>
        <Form form={closingForm} layout="vertical" onFinish={(values) => closingMutation.mutate({ values, id: editingClosing?.id ?? undefined })}>
          <Form.Item name="rule_code" label="Mã quy tắc" rules={[{ required: true }]}><Input /></Form.Item>
          <Form.Item name="rule_name" label="Tên quy tắc" rules={[{ required: true }]}><Input /></Form.Item>
          <Space className="w-full" align="start">
            <Form.Item name="rule_type" label="Loại" rules={[{ required: true }]} className="flex-1">
              <Select options={[{ value: 'revenue', label: 'Doanh thu' }, { value: 'expense', label: 'Chi phí' }, { value: 'result', label: 'Kết quả' }]} />
            </Form.Item>
            <Form.Item name="sequence" label="Thứ tự" rules={[{ required: true }]} className="flex-1"><InputNumber min={0} className="w-full" /></Form.Item>
          </Space>
          <Space className="w-full" align="start">
            <Form.Item name="debit_account" label="TK Nợ" rules={[{ required: true }]} className="flex-1"><Select options={allAccountOptions} placeholder="Chọn từ catalogue máy chủ" /></Form.Item>
            <Form.Item name="credit_account" label="TK Có" rules={[{ required: true }]} className="flex-1"><Select options={allAccountOptions} placeholder="Chọn từ catalogue máy chủ" /></Form.Item>
          </Space>
          <Space className="w-full" align="start">
            <Form.Item name="source_account" label="TK nguồn" className="flex-1"><Select allowClear options={allAccountOptions} placeholder="Tùy chọn" /></Form.Item>
            <Form.Item name="target_account" label="TK đích" className="flex-1"><Select allowClear options={allAccountOptions} placeholder="Tùy chọn" /></Form.Item>
          </Space>
           <Space className="w-full" align="start">
             <Form.Item name="closing_side" label="Bên kết chuyển" rules={[{ required: true }]} className="flex-1"><Select options={[{ value: 'debit', label: 'Nợ' }, { value: 'credit', label: 'Có' }, { value: 'both', label: 'Hai bên' }]} /></Form.Item>
             <Form.Item name="transfer_type" label="Cách kết chuyển" rules={[{ required: true }]} className="flex-1"><Select options={[{ value: 'turnover', label: 'Phát sinh' }, { value: 'balance_debit', label: 'Dư Nợ' }, { value: 'balance_credit', label: 'Dư Có' }, { value: 'formula', label: 'Công thức' }]} /></Form.Item>
           </Space>
           <Form.Item name="is_active" label="Đang sử dụng" valuePropName="checked"><Switch /></Form.Item>
           <Form.Item name="description" label="Diễn giải"><Input.TextArea rows={2} /></Form.Item>
        </Form>
        </ModalFrame>
      </Modal>
     </PageShell>
  );
};

export default AccountingAccountCatalogues;
