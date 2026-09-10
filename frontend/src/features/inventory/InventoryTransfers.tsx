import React from 'react';
import { Alert, Button, DatePicker, Form, Input, InputNumber, Popconfirm, Select, Space, Table } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import { PlusOutlined, ReloadOutlined, PrinterOutlined, SearchOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { Dayjs } from 'dayjs';
import api from '../../api/axios';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import ModalFrame from '../../components/layout/ModalFrame';
import { useAuthStore } from '../../store/useAuthStore';
import { getApiErrorMessage } from '../../utils/apiErrorMessage';

type Warehouse = { id: number; code?: string; name: string; is_active?: boolean };
type Item = { id: number; code?: string; name: string; unit?: string | null; is_active?: boolean };
type TransferLine = { id?: number; item_id: number; item?: Item; unit?: string | null; quantity: number; description?: string | null };
type Transfer = {
  id: number;
  transfer_number: string;
  transfer_date: string;
  from_warehouse_id: number;
  to_warehouse_id: number;
  from_warehouse?: Warehouse;
  to_warehouse?: Warehouse;
  description?: string | null;
  status?: string;
  is_posted?: boolean;
  lines?: TransferLine[];
};

function arrayData<T>(payload: unknown): T[] {
  if (Array.isArray(payload)) return payload as T[];
  if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
    return (payload as { data: T[] }).data;
  }
  throw new Error('Máy chủ không trả về danh sách hợp lệ.');
}

function entityData(payload: unknown): Transfer {
  const entity = payload && typeof payload === 'object' && 'data' in payload
    ? (payload as { data?: unknown }).data
    : payload;
  if (!entity || typeof entity !== 'object' || !Number.isInteger((entity as { id?: unknown }).id)) {
    throw new Error('Máy chủ không trả về phiếu điều chuyển đã lưu.');
  }
  return entity as Transfer;
}

type InventoryTransfersProps = {
  embedded?: boolean;
};

const EMPTY_TRANSFERS: Transfer[] = [];
const EMPTY_PERMISSIONS: string[] = [];

/**
 * Keep the table input referentially stable when a query adapter returns a
 * freshly allocated but unchanged array. rc-table runs its scroll-shadow
 * effect whenever `data` changes; passing that unstable fallback can cause a
 * render loop in compact/virtualized containers.
 */
function useStableRows<T>(rows: T[] | undefined): T[] {
  const ref = React.useRef<T[]>(EMPTY_TRANSFERS as T[]);
  const next = rows ?? EMPTY_TRANSFERS as T[];
  const previous = ref.current;
  const unchanged = previous.length === next.length && next.every((row, index) => row === previous[index]);
  if (!unchanged) ref.current = next;
  return ref.current;
}

const InventoryTransfers: React.FC<InventoryTransfersProps> = ({ embedded = false }) => {
  const queryClient = useQueryClient();
  const [open, setOpen] = React.useState(false);
  const [editing, setEditing] = React.useState<Transfer | null>(null);
  const [form] = Form.useForm();
  const permissions = useAuthStore((state) => state.user?.permissions ?? EMPTY_PERMISSIONS);
  const canPost = permissions.includes('inventory.transfers.post');
  const canUnpost = permissions.includes('inventory.transfers.unpost');

  const transfers = useQuery<Transfer[]>({
    queryKey: ['inventory-transfers'],
    queryFn: async () => arrayData<Transfer>((await api.get('/inventory/transfers')).data),
  });
  const warehouses = useQuery<Warehouse[]>({
    queryKey: ['inventory-transfer-warehouses'],
    queryFn: async () => arrayData<Warehouse>((await api.get('/master/warehouses')).data),
  });
  const items = useQuery<Item[]>({
    queryKey: ['inventory-transfer-items'],
    queryFn: async () => arrayData<Item>((await api.get('/inventory/items')).data),
  });

  const transferRows = useStableRows(transfers.data);
  const [search, setSearch] = React.useState('');

  const filteredTransferRows = React.useMemo(() => {
    if (!search.trim()) return transferRows;
    const q = search.toLowerCase().trim();
    return transferRows.filter((row: Transfer) => {
      const num = String(row.transfer_number || '').toLowerCase();
      const desc = String(row.description || '').toLowerCase();
      const from = String(row.from_warehouse?.name || '').toLowerCase();
      const to = String(row.to_warehouse?.name || '').toLowerCase();
      return num.includes(q) || desc.includes(q) || from.includes(q) || to.includes(q);
    });
  }, [transferRows, search]);

  const save = useMutation({
    mutationFn: async (values: Record<string, unknown>) => {
      const payload = {
        ...values,
        transfer_date: (values.transfer_date as Dayjs).format('YYYY-MM-DD'),
      };
      const response = editing
        ? await api.put(`/inventory/transfers/${editing.id}`, payload)
        : await api.post('/inventory/transfers', payload);
      return entityData(response.data);
    },
    onSuccess: () => {
      message.success(editing ? 'Đã cập nhật phiếu điều chuyển nháp.' : 'Đã lưu phiếu điều chuyển nháp.');
      setOpen(false);
      setEditing(null);
      form.resetFields();
      queryClient.invalidateQueries({ queryKey: ['inventory-transfers'] });
    },
    onError: (error: unknown) => message.error(getApiErrorMessage(error, 'Không thể lưu phiếu điều chuyển; máy chủ không xác nhận dữ liệu.')),
  });

  const remove = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.delete(`/inventory/transfers/${id}`);
      if (response.status !== 204 && typeof response.data?.message !== 'string') {
        throw new Error('Máy chủ không xác nhận xóa phiếu điều chuyển.');
      }
    },
    onSuccess: () => {
      message.success('Đã xóa phiếu điều chuyển nháp.');
      queryClient.invalidateQueries({ queryKey: ['inventory-transfers'] });
    },
    onError: (error: unknown) => message.error(getApiErrorMessage(error, 'Không thể xóa phiếu điều chuyển.')),
  });

  const post = useMutation({
    mutationFn: async (id: number) => entityData((await api.post(`/inventory/transfers/${id}/post`)).data),
    onSuccess: () => {
      message.success('Đã ghi sổ phiếu điều chuyển kho.');
      queryClient.invalidateQueries({ queryKey: ['inventory-transfers'] });
      queryClient.invalidateQueries({ queryKey: ['stock-report'] });
    },
    onError: (error: unknown) => message.error(getApiErrorMessage(error, 'Không thể ghi sổ phiếu điều chuyển; máy chủ đã từ chối thao tác.')),
  });

  const unpost = useMutation({
    mutationFn: async (id: number) => entityData((await api.post(`/inventory/transfers/${id}/unpost`)).data),
    onSuccess: () => {
      message.success('Đã bỏ ghi sổ phiếu điều chuyển kho.');
      queryClient.invalidateQueries({ queryKey: ['inventory-transfers'] });
      queryClient.invalidateQueries({ queryKey: ['stock-report'] });
    },
    onError: (error: unknown) => message.error(getApiErrorMessage(error, 'Không thể bỏ ghi sổ phiếu điều chuyển; máy chủ đã từ chối thao tác.')),
  });

  const warehouseOptions = (warehouses.data ?? [])
    .filter((warehouse) => warehouse.is_active !== false && Number.isInteger(warehouse.id))
    .map((warehouse) => ({ value: warehouse.id, label: `${warehouse.code ?? '—'} — ${warehouse.name}` }));
  const itemOptions = (items.data ?? [])
    .filter((item) => item.is_active !== false && Number.isInteger(item.id))
    .map((item) => ({ value: item.id, label: `${item.code ?? '—'} — ${item.name}` }));

  const catalogueError = warehouses.isError || items.isError;
  const catalogueUnavailable = catalogueError || warehouses.isLoading || items.isLoading
    || warehouseOptions.length < 2 || itemOptions.length === 0;
  const retryCatalogues = () => {
    void Promise.all([
      warehouses.isError ? warehouses.refetch() : undefined,
      items.isError ? items.refetch() : undefined,
    ]);
  };

  const openCreate = React.useCallback(() => {
    if (catalogueUnavailable) {
      message.warning('Chưa có đủ catalogue kho và hàng hóa từ máy chủ để lập phiếu.');
      return;
    }
    setEditing(null);
    form.resetFields();
    setOpen(true);
  }, [catalogueUnavailable, form]);

  React.useEffect(() => {
    const handleCreate = () => openCreate();
    window.addEventListener('open-inventory-transfer', handleCreate);
    return () => window.removeEventListener('open-inventory-transfer', handleCreate);
  }, [openCreate]);

  React.useEffect(() => {
    const handleRefresh = () => { void transfers.refetch(); };
    const handleSearch = (e: Event) => {
      const ce = e as CustomEvent<string>;
      setSearch(ce.detail ?? '');
    };
    window.addEventListener('refresh-inventory-transfer', handleRefresh);
    window.addEventListener('search-inventory-transfer', handleSearch);
    return () => {
      window.removeEventListener('refresh-inventory-transfer', handleRefresh);
      window.removeEventListener('search-inventory-transfer', handleSearch);
    };
  }, [transfers]);
  const openEdit = (row: Transfer) => {
    setEditing(row);
    form.setFieldsValue({
      transfer_number: row.transfer_number,
      transfer_date: dayjs(row.transfer_date),
      from_warehouse_id: row.from_warehouse_id,
      to_warehouse_id: row.to_warehouse_id,
      description: row.description ?? undefined,
      lines: (row.lines ?? []).map((line) => ({
        item_id: line.item_id,
        unit: line.unit ?? undefined,
        quantity: line.quantity,
        description: line.description ?? undefined,
      })),
    });
    setOpen(true);
  };

  const createToolbar = (
    <PageToolbar
      filters={
        <div className="misa-toolbar-left flex items-center gap-2">
          <Input
            placeholder="Tìm theo số phiếu điều chuyển, kho..."
            prefix={<SearchOutlined className="misa-color-muted" />}
            className="misa-w-280"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            allowClear
          />
        </div>
      }
      actions={
        <div className="misa-toolbar-right flex items-center gap-2">
          <Button
            icon={<ReloadOutlined />}
            className="misa-btn-tool"
            title="Làm mới (F5)"
            onClick={() => void transfers.refetch()}
          />
          <Button
            icon={<PrinterOutlined />}
            className="misa-btn-tool"
            title="In danh sách"
            onClick={() => window.print()}
          />
          <Button
            type="primary"
            className="misa-btn-primary"
            icon={<PlusOutlined />}
            disabled={warehouseOptions.length < 2 || itemOptions.length === 0}
            onClick={openCreate}
          >
            Thêm phiếu điều chuyển
          </Button>
        </div>
      }
    />
  );

  return (
    <PageShell
      embedded={embedded}
      title={<PageHeader
        eyebrow="KHO"
        title="Chuyển kho nội bộ"
        description="Điều chuyển tồn kho giữa các kho trong cùng đơn vị"
      />}
      toolbar={embedded ? undefined : createToolbar}
    >
      {transfers.error && <Alert
        className="mb-4"
        type="error"
        showIcon
        title="Không tải được phiếu điều chuyển"
        description="Không dùng dữ liệu mẫu thay thế."
        action={<Button size="small" onClick={() => void transfers.refetch()}>Thử lại phiếu điều chuyển</Button>}
      />}
      {catalogueError && <Alert
        className="mb-4"
        type="error"
        showIcon
        title="Không tải được danh mục kho hoặc hàng hóa"
        description="Không dùng danh sách rỗng thay thế; hãy tải lại danh mục từ máy chủ trước khi lập hoặc lưu phiếu."
        action={<Button size="small" onClick={retryCatalogues}>Thử lại danh mục điều chuyển</Button>}
      />}
      <DataTableSurface>
        <Table<Transfer>
          rowKey="id"
          loading={transfers.isLoading}
          dataSource={filteredTransferRows}
          locale={{ emptyText: 'Chưa có phiếu điều chuyển từ máy chủ' }}
          pagination={{ pageSize: 20, showSizeChanger: false }}
          columns={[
          { title: 'Số phiếu', dataIndex: 'transfer_number', key: 'transfer_number', render: (value) => value || '—' },
          { title: 'Ngày', dataIndex: 'transfer_date', key: 'transfer_date', render: (value) => value || '—' },
          { title: 'Kho xuất', key: 'from', render: (_, row) => row.from_warehouse?.name || '—' },
          { title: 'Kho nhận', key: 'to', render: (_, row) => row.to_warehouse?.name || '—' },
          { title: 'Số dòng', key: 'lines', render: (_, row) => Array.isArray(row.lines) ? row.lines.length : '—' },
          { title: 'Trạng thái', dataIndex: 'status', key: 'status', render: (value) => value === 'draft' ? 'Nháp' : value === 'posted' ? 'Đã ghi sổ' : value || '—' },
          {
            title: 'Chức năng', key: 'actions', render: (_, row) => (
              <Space>
                <Button type="link" onClick={() => openEdit(row)} disabled={row.status !== 'draft' || row.is_posted === true}>Sửa</Button>
                <Popconfirm title="Xóa phiếu nháp này?" onConfirm={() => remove.mutate(row.id)}>
                  <Button type="link" danger disabled={row.status !== 'draft' || row.is_posted === true}>Xóa</Button>
                </Popconfirm>
                {row.status === 'draft' && row.is_posted !== true && canPost && (
                  <Popconfirm title="Ghi sổ phiếu điều chuyển này?" onConfirm={() => post.mutate(row.id)}>
                    <Button type="link" loading={post.isPending}>Ghi sổ</Button>
                  </Popconfirm>
                )}
                {row.status === 'posted' && row.is_posted === true && canUnpost && (
                  <Popconfirm title="Bỏ ghi sổ và tạo event đảo?" onConfirm={() => unpost.mutate(row.id)}>
                    <Button type="link" danger loading={unpost.isPending}>Bỏ ghi sổ</Button>
                  </Popconfirm>
                )}
              </Space>
            ),
          },
          ]}
        />
      </DataTableSurface>

      <Modal
        title={editing ? 'Sửa phiếu điều chuyển' : 'Thêm phiếu điều chuyển'}
        open={open}
        confirmLoading={save.isPending}
        okButtonProps={{ disabled: catalogueUnavailable }}
        onCancel={() => { setOpen(false); setEditing(null); form.resetFields(); }}
        onOk={() => form.submit()}
        width={900}
      >
        <ModalFrame>
          {catalogueError && <Alert
            className="mb-4"
            type="error"
            showIcon
            title="Chưa thể xác thực danh mục điều chuyển"
            description="Kho và hàng hóa phải được tải thành công từ máy chủ trước khi cất chứng từ."
            action={<Button size="small" onClick={retryCatalogues}>Thử lại danh mục điều chuyển</Button>}
          />}
          <Form form={form} layout="vertical" onFinish={(values) => save.mutate(values)}>
          <Space className="w-full" align="start">
            <Form.Item name="transfer_number" label="Số phiếu" rules={[{ required: true, message: 'Nhập số phiếu' }]} className="flex-1"><Input /></Form.Item>
            <Form.Item name="transfer_date" label="Ngày điều chuyển" rules={[{ required: true, message: 'Chọn ngày' }]} className="flex-1"><DatePicker className="w-full" format="DD/MM/YYYY" /></Form.Item>
          </Space>
          <Space className="w-full" align="start">
            <Form.Item name="from_warehouse_id" label="Kho xuất" rules={[{ required: true, message: 'Chọn kho xuất' }]} className="flex-1"><Select options={warehouseOptions} placeholder="Chọn từ catalogue máy chủ" /></Form.Item>
            <Form.Item name="to_warehouse_id" label="Kho nhận" rules={[{ required: true, message: 'Chọn kho nhận' }]} className="flex-1"><Select options={warehouseOptions} placeholder="Chọn từ catalogue máy chủ" /></Form.Item>
          </Space>
          <Form.Item name="description" label="Diễn giải"><Input.TextArea rows={2} /></Form.Item>
          <Form.List name="lines" rules={[{ validator: async (_, value) => { if (!Array.isArray(value) || value.length === 0) throw new Error('Thêm ít nhất một dòng hàng hóa'); } }]}>
            {(fields, { add, remove: removeLine }, { errors }) => (
              <>
                {fields.map((field) => (
                  <Space key={field.key} className="w-full" align="start">
                    <Form.Item {...field} name={[field.name, 'item_id']} label="Hàng hóa" rules={[{ required: true, message: 'Chọn hàng hóa' }]}><Select className="w-64" options={itemOptions} placeholder="Chọn từ máy chủ" /></Form.Item>
                    <Form.Item {...field} name={[field.name, 'quantity']} label="Số lượng" rules={[{ required: true, type: 'number', min: 0.000001 }]}><InputNumber className="w-28" /></Form.Item>
                    <Form.Item {...field} name={[field.name, 'unit']} label="Đơn vị"><Input className="w-28" /></Form.Item>
                    <Form.Item {...field} name={[field.name, 'description']} label="Diễn giải"><Input className="w-48" /></Form.Item>
                    <Button type="link" danger onClick={() => removeLine(field.name)}>Xóa</Button>
                  </Space>
                ))}
                <Form.ErrorList errors={errors} />
                <Button type="dashed" onClick={() => add()} block>Thêm dòng hàng hóa</Button>
              </>
            )}
          </Form.List>
          </Form>
        </ModalFrame>
      </Modal>
    </PageShell>
  );
};

export default InventoryTransfers;
