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
import { getApiErrorMessage } from '../../utils/apiErrorMessage';

type Warehouse = { id: number; code?: string; name: string; is_active?: boolean };
type Item = { id: number; code?: string; name: string; unit?: string | null; is_active?: boolean };
type CountLine = { id?: number; item_id: number; item?: Item; unit?: string | null; counted_quantity: number; description?: string | null };
type StockCount = {
  id: number;
  count_number: string;
  count_date: string;
  warehouse_id: number;
  warehouse?: Warehouse;
  description?: string | null;
  status?: string;
  is_posted?: boolean;
  lines?: CountLine[];
};
type VarianceLine = {
  line_id: number;
  item_id: number;
  item_code?: string | null;
  item_name?: string | null;
  unit?: string | null;
  book_quantity: string;
  counted_quantity: string;
  variance_quantity: string;
};
type VariancePreview = {
  count_id: number;
  count_number: string;
  count_date: string;
  warehouse_id: number;
  lines: VarianceLine[];
};

type AdjustmentDraftResult = {
  issue_drafts?: Array<{ id: number }>;
  receipt_drafts?: Array<{ id: number }>;
};

const EMPTY_STOCK_COUNTS: StockCount[] = [];

function arrayData<T>(payload: unknown): T[] {
  if (Array.isArray(payload)) return payload as T[];
  if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
    return (payload as { data: T[] }).data;
  }
  throw new Error('Máy chủ không trả về danh sách hợp lệ.');
}

function entityData(payload: unknown): StockCount {
  const entity = payload && typeof payload === 'object' && 'data' in payload
    ? (payload as { data?: unknown }).data
    : payload;
  if (!entity || typeof entity !== 'object' || !Number.isInteger((entity as { id?: unknown }).id)) {
    throw new Error('Máy chủ không trả về biên bản kiểm kê đã lưu.');
  }
  return entity as StockCount;
}

type InventoryStockCountsProps = {
  embedded?: boolean;
  onOpenAdjustmentDraft?: (kind: 'receipt' | 'issue', id: number) => void;
};

const InventoryStockCounts: React.FC<InventoryStockCountsProps> = ({ embedded = false, onOpenAdjustmentDraft }) => {
  const queryClient = useQueryClient();
  const [open, setOpen] = React.useState(false);
  const [editing, setEditing] = React.useState<StockCount | null>(null);
  const [variancePreview, setVariancePreview] = React.useState<VariancePreview | null>(null);
  const [draftResult, setDraftResult] = React.useState<AdjustmentDraftResult | null>(null);
  const [search, setSearch] = React.useState('');
  const [form] = Form.useForm();

  const counts = useQuery<StockCount[]>({
    queryKey: ['inventory-stock-counts'],
    queryFn: async () => arrayData<StockCount>((await api.get('/inventory/stock-counts')).data),
  });
  const warehouses = useQuery<Warehouse[]>({
    queryKey: ['inventory-stock-count-warehouses'],
    queryFn: async () => arrayData<Warehouse>((await api.get('/master/warehouses')).data),
  });
  const items = useQuery<Item[]>({
    queryKey: ['inventory-stock-count-items'],
    queryFn: async () => arrayData<Item>((await api.get('/inventory/items')).data),
  });

  const save = useMutation({
    mutationFn: async (values: Record<string, unknown>) => {
      const payload = {
        ...values,
        count_date: (values.count_date as Dayjs).format('YYYY-MM-DD'),
      };
      const response = editing
        ? await api.put(`/inventory/stock-counts/${editing.id}`, payload)
        : await api.post('/inventory/stock-counts', payload);
      return entityData(response.data);
    },
    onSuccess: () => {
      message.success(editing ? 'Đã cập nhật biên bản kiểm kê nháp.' : 'Đã lưu biên bản kiểm kê nháp.');
      setOpen(false);
      setEditing(null);
      form.resetFields();
      queryClient.invalidateQueries({ queryKey: ['inventory-stock-counts'] });
    },
    onError: (error: unknown) => message.error(getApiErrorMessage(error, 'Không thể lưu biên bản kiểm kê; máy chủ không xác nhận dữ liệu.')),
  });

  const remove = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.delete(`/inventory/stock-counts/${id}`);
      if (response.status !== 204 && typeof response.data?.message !== 'string') {
        throw new Error('Máy chủ không xác nhận xóa biên bản kiểm kê.');
      }
    },
    onSuccess: () => {
      message.success('Đã xóa biên bản kiểm kê nháp.');
      queryClient.invalidateQueries({ queryKey: ['inventory-stock-counts'] });
    },
    onError: (error: unknown) => message.error(getApiErrorMessage(error, 'Không thể xóa biên bản kiểm kê.')),
  });

  const variance = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.get(`/inventory/stock-counts/${id}/variance`);
      const data = response.data?.data;
      if (!data || !Array.isArray(data.lines)) throw new Error('Máy chủ không trả về chênh lệch kiểm kê hợp lệ.');
      return data as VariancePreview;
    },
    onSuccess: setVariancePreview,
    onError: (error: unknown) => message.error(getApiErrorMessage(error, 'Không thể tải chênh lệch kiểm kê từ máy chủ.')),
  });

  const adjustmentDrafts = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.post(`/inventory/stock-counts/${id}/adjustment-drafts`);
      const result = response.data?.data as AdjustmentDraftResult | undefined;
      if (!result || (!Array.isArray(result.receipt_drafts) && !Array.isArray(result.issue_drafts))) {
        throw new Error('Máy chủ không trả về chứng từ điều chỉnh nháp hợp lệ.');
      }
      return result;
    },
    onSuccess: (result) => {
      setDraftResult(result);
      message.success('Đã tạo chứng từ điều chỉnh nháp từ biên bản kiểm kê. Mở phiếu để bổ sung tài khoản rồi ghi sổ.');
      queryClient.invalidateQueries({ queryKey: ['inventory-stock-counts'] });
      queryClient.invalidateQueries({ queryKey: ['inventory-receipts'] });
      queryClient.invalidateQueries({ queryKey: ['inventory-issues'] });
    },
    onError: (error: unknown) => message.error(getApiErrorMessage(error, 'Không thể tạo chứng từ điều chỉnh nháp; kiểm tra kỳ, quyền và dữ liệu kiểm kê.')),
  });

  const warehouseOptions = (warehouses.data ?? [])
    .filter((warehouse) => warehouse.is_active !== false && Number.isInteger(warehouse.id))
    .map((warehouse) => ({ value: warehouse.id, label: `${warehouse.code ?? '—'} — ${warehouse.name}` }));
  const itemOptions = (items.data ?? [])
    .filter((item) => item.is_active !== false && Number.isInteger(item.id))
    .map((item) => ({ value: item.id, label: `${item.code ?? '—'} — ${item.name}` }));

  const catalogueError = warehouses.isError || items.isError;
  const catalogueUnavailable = catalogueError || warehouses.isLoading || items.isLoading
    || warehouseOptions.length === 0 || itemOptions.length === 0;
  const retryCatalogues = () => {
    void Promise.all([
      warehouses.isError ? warehouses.refetch() : undefined,
      items.isError ? items.refetch() : undefined,
    ]);
  };

  const filteredCounts = React.useMemo(() => {
    const source = counts.data ?? EMPTY_STOCK_COUNTS;
    const needle = search.trim().toLowerCase();
    if (!needle) return source;
    return source.filter((row) => [row.count_number, row.count_date, row.warehouse?.code, row.warehouse?.name, row.status]
      .some((value) => String(value ?? '').toLowerCase().includes(needle)));
  }, [counts.data, search]);

  const openCreate = React.useCallback(() => {
    if (catalogueUnavailable) {
      message.warning('Chưa có đủ catalogue kho và hàng hóa từ máy chủ để lập biên bản.');
      return;
    }
    setEditing(null);
    form.resetFields();
    setOpen(true);
  }, [catalogueUnavailable, form]);
  React.useEffect(() => {
    const handleCreate = () => openCreate();
    window.addEventListener('open-inventory-stock-count', handleCreate);
    return () => window.removeEventListener('open-inventory-stock-count', handleCreate);
  }, [openCreate]);

  React.useEffect(() => {
    const handleRefresh = () => { void counts.refetch(); };
    const handleSearch = (e: Event) => {
      const ce = e as CustomEvent<string>;
      setSearch(ce.detail ?? '');
    };
    window.addEventListener('refresh-inventory-stock-count', handleRefresh);
    window.addEventListener('search-inventory-audit', handleSearch);
    return () => {
      window.removeEventListener('refresh-inventory-stock-count', handleRefresh);
      window.removeEventListener('search-inventory-audit', handleSearch);
    };
  }, [counts]);
  const openEdit = (row: StockCount) => {
    setEditing(row);
    form.setFieldsValue({
      count_number: row.count_number,
      count_date: dayjs(row.count_date),
      warehouse_id: row.warehouse_id,
      description: row.description ?? undefined,
      lines: (row.lines ?? []).map((line) => ({
        item_id: line.item_id,
        unit: line.unit ?? undefined,
        counted_quantity: Number(line.counted_quantity),
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
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            allowClear
            prefix={<SearchOutlined className="misa-color-muted" />}
            placeholder="Tìm số biên bản, kho, trạng thái..."
            className="misa-w-280"
          />
        </div>
      }
      actions={
        <div className="misa-toolbar-right flex items-center gap-2">
          <Button
            icon={<ReloadOutlined />}
            className="misa-btn-tool"
            title="Làm mới (F5)"
            onClick={() => void counts.refetch()}
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
            disabled={warehouseOptions.length === 0 || itemOptions.length === 0}
            onClick={openCreate}
          >
            Thêm biên bản nháp
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
        title="Kiểm kê kho"
        description="Chỉ ghi nhận số đếm thực tế"
      />}
      toolbar={embedded ? undefined : createToolbar}
    >
      {counts.error && <Alert
        className="mb-4"
        type="error"
        showIcon
        title="Không tải được biên bản kiểm kê"
        description="Không dùng dữ liệu mẫu thay thế."
        action={<Button size="small" onClick={() => void counts.refetch()}>Thử lại biên bản kiểm kê</Button>}
      />}
      {catalogueError && <Alert
        className="mb-4"
        type="error"
        showIcon
        title="Không tải được danh mục kho hoặc hàng hóa"
        description="Không dùng danh sách rỗng thay thế; hãy tải lại danh mục từ máy chủ trước khi lập biên bản."
        action={<Button size="small" onClick={retryCatalogues}>Thử lại danh mục kiểm kê</Button>}
      />}
      <DataTableSurface>
        <Table<StockCount>
          rowKey="id"
          loading={counts.isLoading}
          dataSource={filteredCounts}
          locale={{ emptyText: 'Chưa có biên bản kiểm kê từ máy chủ' }}
          pagination={{ pageSize: 20, showSizeChanger: false }}
          columns={[
          { title: 'Số biên bản', dataIndex: 'count_number', key: 'count_number', render: (value) => value || '—' },
          { title: 'Ngày', dataIndex: 'count_date', key: 'count_date', render: (value) => value || '—' },
          { title: 'Kho', key: 'warehouse', render: (_, row) => row.warehouse?.name || '—' },
          { title: 'Số dòng', key: 'lines', render: (_, row) => Array.isArray(row.lines) ? row.lines.length : '—' },
          { title: 'Trạng thái', dataIndex: 'status', key: 'status', render: (value) => value === 'draft' ? 'Nháp' : value || '—' },
          {
            title: 'Chức năng', key: 'actions', render: (_, row) => (
              <Space>
                <Button type="link" loading={variance.isPending} onClick={() => { setDraftResult(null); variance.mutate(row.id); }}>Chênh lệch</Button>
                <Button type="link" onClick={() => openEdit(row)} disabled={row.status !== 'draft' || row.is_posted === true}>Sửa</Button>
                <Popconfirm title="Xóa biên bản nháp này?" onConfirm={() => remove.mutate(row.id)}>
                  <Button type="link" danger disabled={row.status !== 'draft' || row.is_posted === true}>Xóa</Button>
                </Popconfirm>
              </Space>
            ),
          },
          ]}
        />
      </DataTableSurface>

      <Modal
        title={editing ? 'Sửa biên bản kiểm kê nháp' : 'Thêm biên bản kiểm kê nháp'}
        open={open}
        confirmLoading={save.isPending}
        okButtonProps={{ disabled: catalogueUnavailable }}
        onCancel={() => { setOpen(false); setEditing(null); form.resetFields(); }}
        onOk={() => form.submit()}
        okText="Cất"
        cancelText="Hủy"
        width={900}
      >
        <ModalFrame>
          {catalogueError && <Alert
            className="mb-4"
            type="error"
            showIcon
            title="Chưa thể xác thực danh mục kiểm kê"
            description="Kho và hàng hóa phải được tải thành công từ máy chủ trước khi cất biên bản."
            action={<Button size="small" onClick={retryCatalogues}>Thử lại danh mục kiểm kê</Button>}
          />}
          <Form form={form} layout="vertical" onFinish={(values) => save.mutate(values)}>
          <div className="misa-master-card misa-stock-count-master-card">
          <div className="misa-form-grid">
            <Form.Item name="count_number" label="Số biên bản" rules={[{ required: true, message: 'Nhập số biên bản' }]} className="flex-1"><Input /></Form.Item>
            <Form.Item name="count_date" label="Ngày kiểm kê" rules={[{ required: true, message: 'Chọn ngày' }]} className="flex-1"><DatePicker className="w-full" format="DD/MM/YYYY" /></Form.Item>
            <Form.Item name="warehouse_id" label="Kho" rules={[{ required: true, message: 'Chọn kho' }]} className="flex-1"><Select options={warehouseOptions} placeholder="Chọn từ catalogue máy chủ" /></Form.Item>
          </div>
          <Form.Item name="description" label="Diễn giải"><Input.TextArea rows={2} /></Form.Item>
          <Form.List name="lines" rules={[{ validator: async (_, value) => { if (!Array.isArray(value) || value.length === 0) throw new Error('Thêm ít nhất một dòng hàng hóa'); } }]}>
            {(fields, { add, remove: removeLine }, { errors }) => (
              <>
                {fields.map((field) => (
                  <div key={field.key} className="misa-stock-count-line">
                    <Form.Item {...field} name={[field.name, 'item_id']} label="Hàng hóa" rules={[{ required: true, message: 'Chọn hàng hóa' }]}><Select className="w-64" options={itemOptions} placeholder="Chọn từ máy chủ" /></Form.Item>
                    <Form.Item {...field} name={[field.name, 'counted_quantity']} label="Số lượng thực tế" rules={[{ required: true, type: 'number', min: 0 }]}><InputNumber className="w-36" min={0} /></Form.Item>
                    <Form.Item {...field} name={[field.name, 'unit']} label="Đơn vị"><Input className="w-28" /></Form.Item>
                    <Form.Item {...field} name={[field.name, 'description']} label="Diễn giải"><Input className="w-48" /></Form.Item>
                    <Button type="link" danger onClick={() => removeLine(field.name)}>Xóa</Button>
                  </div>
                ))}
                <Form.ErrorList errors={errors} />
                <Button type="dashed" onClick={() => add()} block>Thêm dòng hàng hóa</Button>
              </>
            )}
          </Form.List>
          </div>
          </Form>
        </ModalFrame>
      </Modal>

      <Modal
        title={variancePreview ? `Chênh lệch kiểm kê — ${variancePreview.count_number}` : 'Chênh lệch kiểm kê'}
        open={variancePreview !== null}
        footer={(
          <Space>
            <Button onClick={() => { setVariancePreview(null); setDraftResult(null); }}>Đóng</Button>
            <Button
              type="primary"
              loading={adjustmentDrafts.isPending}
              disabled={!variancePreview?.lines.some((line) => Number(line.variance_quantity) !== 0)}
              onClick={() => variancePreview && adjustmentDrafts.mutate(variancePreview.count_id)}
            >
              Tạo phiếu điều chỉnh nháp
            </Button>
          </Space>
        )}
        onCancel={() => { setVariancePreview(null); setDraftResult(null); }}
        width={760}
      >
        <ModalFrame>
          <p className="misa-color-muted misa-mb-12">Số tồn sổ được tính tại ngày kiểm kê từ dữ liệu đã ghi sổ; xem lại trước khi lập chứng từ điều chỉnh.</p>
          {draftResult && (
            <Alert
              className="misa-mb-12"
              type="success"
              showIcon
              title="Đã tạo chứng từ điều chỉnh ở trạng thái nháp"
              description="Mở từng phiếu để bổ sung đơn giá và tài khoản theo mapping đã phê duyệt, sau đó dùng thao tác Ghi sổ của phiếu kho."
              action={(
                <Space wrap>
                  {draftResult.receipt_drafts?.map((draft) => (
                    <Button
                      key={`receipt-${draft.id}`}
                      size="small"
                      onClick={() => onOpenAdjustmentDraft?.('receipt', draft.id)}
                      disabled={!onOpenAdjustmentDraft}
                    >
                      Mở phiếu nhập nháp
                    </Button>
                  ))}
                  {draftResult.issue_drafts?.map((draft) => (
                    <Button
                      key={`issue-${draft.id}`}
                      size="small"
                      onClick={() => onOpenAdjustmentDraft?.('issue', draft.id)}
                      disabled={!onOpenAdjustmentDraft}
                    >
                      Mở phiếu xuất nháp
                    </Button>
                  ))}
                </Space>
              )}
            />
          )}
          <Table<VarianceLine>
            rowKey="line_id"
            pagination={false}
            size="small"
            dataSource={variancePreview?.lines ?? []}
            columns={[
              { title: 'Mã hàng', dataIndex: 'item_code', key: 'item_code', render: (value) => value || '—' },
              { title: 'Tên hàng hóa', dataIndex: 'item_name', key: 'item_name', render: (value) => value || '—' },
              { title: 'Tồn sổ', dataIndex: 'book_quantity', key: 'book_quantity', align: 'right' },
              { title: 'Thực tế', dataIndex: 'counted_quantity', key: 'counted_quantity', align: 'right' },
              { title: 'Chênh lệch', dataIndex: 'variance_quantity', key: 'variance_quantity', align: 'right', render: (value) => <strong>{value}</strong> },
            ]}
          />
        </ModalFrame>
      </Modal>
    </PageShell>
  );
};

export default InventoryStockCounts;
