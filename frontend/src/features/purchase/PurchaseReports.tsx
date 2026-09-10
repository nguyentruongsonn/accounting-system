import React, { useMemo, useState } from 'react';
import { Alert, Button, DatePicker, Input, Select, Table, Tag } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import type { ColumnsType } from 'antd/es/table';
import { DownloadOutlined, PrinterOutlined, ReloadOutlined, SearchOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { flushSync } from 'react-dom';
import dayjs, { type Dayjs } from 'dayjs';
import api from '../../api/axios';
import PageHeader from '../../components/layout/PageHeader';
import PageShell from '../../components/layout/PageShell';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import ExportExcelButton from '../../components/ExportExcelButton';
import { formatDecimalMoney, normalizeDecimalMoney } from '../../utils/decimalMoney';
import { commercialReportSourceRoute } from '../reports/commercialReportSource';

export interface PurchaseReportRow {
  key: string;
  id: number;
  source_type: 'purchase_invoice' | 'purchase_return' | 'purchase_discount';
  source_label: string;
  voucher_number: string;
  voucher_date: string | null;
  accounting_date: string | null;
  supplier_id: number | null;
  supplier_name: string;
  description: string | null;
  sub_total: string;
  discount_amount: string;
  tax_amount: string;
  total_amount: string;
  signed_sub_total: string;
  signed_discount_amount: string;
  signed_tax_amount: string;
  signed_total_amount: string;
  is_posted: true;
}

export interface PurchaseReportResponse {
  data: PurchaseReportRow[];
  totals: {
    sub_total: string;
    discount_amount: string;
    tax_amount: string;
    total_amount: string;
  };
  meta: {
    report_key: string;
    date_basis: string;
    source: string[];
    posted_only: boolean;
    from_date: string | null;
    to_date: string | null;
    supplier_id: number | null;
  };
}

type UnknownRecord = Record<string, unknown>;

function asRecord(value: unknown, label: string): UnknownRecord {
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error(`Invalid ${label} response.`);
  return value as UnknownRecord;
}

function requiredString(value: unknown, label: string): string {
  if (typeof value !== 'string') throw new Error(`Invalid ${label}.`);
  return value;
}

function nullableString(value: unknown, label: string): string | null {
  if (value === null || value === undefined || value === '') return null;
  return requiredString(value, label);
}

function money(value: unknown, label: string): string {
  if (typeof value !== 'string' && typeof value !== 'number') throw new Error(`Invalid ${label}.`);
  try {
    return normalizeDecimalMoney(value);
  } catch {
    throw new Error(`Invalid ${label}.`);
  }
}

/** Validate the server envelope before rendering any purchase report value. */
export function parsePurchaseReportResponse(value: unknown): PurchaseReportResponse {
  const payload = asRecord(value, 'purchase report');
  if (!Array.isArray(payload.data)) throw new Error('Invalid purchase report data.');
  const rawTotals = asRecord(payload.totals, 'purchase report totals');
  const rawMeta = asRecord(payload.meta, 'purchase report metadata');
  const totals = {
    sub_total: money(rawTotals.sub_total, 'purchase report subtotal'),
    discount_amount: money(rawTotals.discount_amount, 'purchase report discount'),
    tax_amount: money(rawTotals.tax_amount, 'purchase report tax'),
    total_amount: money(rawTotals.total_amount, 'purchase report total'),
  };

  const data = payload.data.map((raw, index) => {
    const row = asRecord(raw, `purchase report row ${index + 1}`);
    const sourceType = row.source_type;
    if (sourceType !== 'purchase_invoice' && sourceType !== 'purchase_return' && sourceType !== 'purchase_discount') throw new Error(`Invalid purchase report source at row ${index + 1}.`);
    const numericId = Number(row.id);
    if (!Number.isSafeInteger(numericId) || numericId < 1) throw new Error(`Invalid purchase report id at row ${index + 1}.`);
    if (row.is_posted !== true) throw new Error(`Purchase report row ${index + 1} is not posted.`);
    const numericSupplierId = row.supplier_id === null || row.supplier_id === undefined ? null : Number(row.supplier_id);
    if (numericSupplierId !== null && (!Number.isSafeInteger(numericSupplierId) || numericSupplierId < 1)) throw new Error(`Invalid purchase report supplier at row ${index + 1}.`);

    return {
      key: `${sourceType}:${numericId}`,
      id: numericId,
      source_type: sourceType,
      source_label: requiredString(row.source_label, `purchase report source label at row ${index + 1}`),
      voucher_number: requiredString(row.voucher_number, `purchase report voucher at row ${index + 1}`),
      voucher_date: nullableString(row.voucher_date, `purchase report voucher date at row ${index + 1}`),
      accounting_date: nullableString(row.accounting_date, `purchase report accounting date at row ${index + 1}`),
      supplier_id: numericSupplierId,
      supplier_name: requiredString(row.supplier_name, `purchase report supplier at row ${index + 1}`),
      description: nullableString(row.description, `purchase report description at row ${index + 1}`),
      sub_total: money(row.sub_total, `purchase report subtotal at row ${index + 1}`),
      discount_amount: money(row.discount_amount, `purchase report discount at row ${index + 1}`),
      tax_amount: money(row.tax_amount, `purchase report tax at row ${index + 1}`),
      total_amount: money(row.total_amount, `purchase report total at row ${index + 1}`),
      signed_sub_total: money(row.signed_sub_total, `purchase report signed subtotal at row ${index + 1}`),
      signed_discount_amount: money(row.signed_discount_amount, `purchase report signed discount at row ${index + 1}`),
      signed_tax_amount: money(row.signed_tax_amount, `purchase report signed tax at row ${index + 1}`),
      signed_total_amount: money(row.signed_total_amount, `purchase report signed total at row ${index + 1}`),
      is_posted: true,
    } as PurchaseReportRow;
  });

  if (rawMeta.posted_only !== true || rawMeta.date_basis !== 'accounting_date') throw new Error('Purchase report metadata is not a posted accounting-date view.');
  if (!Array.isArray(rawMeta.source) || !rawMeta.source.every((item) => typeof item === 'string')) throw new Error('Invalid purchase report source metadata.');

  return {
    data,
    totals,
    meta: {
      report_key: requiredString(rawMeta.report_key, 'purchase report key'),
      date_basis: requiredString(rawMeta.date_basis, 'purchase report date basis'),
      source: rawMeta.source as string[],
      posted_only: true,
      from_date: nullableString(rawMeta.from_date, 'purchase report from date'),
      to_date: nullableString(rawMeta.to_date, 'purchase report to date'),
      supplier_id: rawMeta.supplier_id === null || rawMeta.supplier_id === undefined ? null : Number(rawMeta.supplier_id),
    },
  };
}

export function parseSupplierOptions(value: unknown): { value: number; label: string }[] {
  const payload = Array.isArray(value) ? value : asRecord(value, 'supplier catalogue').data;
  if (!Array.isArray(payload)) throw new Error('Invalid supplier catalogue response.');
  return payload.map((item, index) => {
    if (!item || typeof item !== 'object') throw new Error(`Invalid supplier catalogue row ${index + 1}.`);
    const row = item as UnknownRecord;
    const id = Number(row.id);
    const name = row.name;
    const code = row.code;
    if (!Number.isSafeInteger(id) || id < 1 || typeof name !== 'string' || name.trim() === '') {
      throw new Error(`Invalid supplier catalogue row ${index + 1}.`);
    }
    if (code !== undefined && code !== null && typeof code !== 'string') {
      throw new Error(`Invalid supplier code at row ${index + 1}.`);
    }
    return { value: id, label: code ? `${code} - ${name}` : name };
  });
}

function formatMoney(value: string): string {
  return `${formatDecimalMoney(value)} ₫`;
}

function escapeCsv(value: string): string {
  return `"${value.replaceAll('"', '""')}"`;
}

function exportPurchaseReport(rows: PurchaseReportRow[]): void {
  const header = ['Ngày hạch toán', 'Ngày chứng từ', 'Số chứng từ', 'Loại', 'Nhà cung cấp', 'Diễn giải', 'Tiền hàng', 'Chiết khấu', 'Tiền thuế', 'Tổng tiền'];
  const body = rows.map((row) => [
    row.accounting_date ?? '',
    row.voucher_date ?? '',
    row.voucher_number,
    row.source_label,
    row.supplier_name,
    row.description ?? '',
    row.signed_sub_total,
    row.signed_discount_amount,
    row.signed_tax_amount,
    row.signed_total_amount
  ]);
  const csv = [header, ...body].map((line) => line.map(escapeCsv).join(',')).join('\r\n');
  const blob = new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = 'bao-cao-mua-hang.csv';
  link.click();
  URL.revokeObjectURL(url);
  message.success('Đã xuất dữ liệu báo cáo.');
}

interface PurchaseReportsProps { embedded?: boolean; }

export const PurchaseReports: React.FC<PurchaseReportsProps> = ({ embedded = false }) => {
  const navigate = useNavigate();
  const [isPrinting, setIsPrinting] = useState(false);
  const [datePreset, setDatePreset] = useState('Tất cả');
  const [fromDate, setFromDate] = useState<Dayjs | null>(null);
  const [toDate, setToDate] = useState<Dayjs | null>(null);
  const [supplierId, setSupplierId] = useState<number | undefined>();
  const [searchText, setSearchText] = useState('');

  const handlePresetChange = (preset: string) => {
    setDatePreset(preset);
    const now = dayjs();
    if (preset === 'Tất cả') {
      setFromDate(null);
      setToDate(null);
    } else if (preset === 'Hôm nay') {
      setFromDate(now.startOf('day'));
      setToDate(now.endOf('day'));
    } else if (preset === 'Tuần này') {
      setFromDate(now.startOf('week'));
      setToDate(now.endOf('week'));
    } else if (preset === 'Tháng này') {
      setFromDate(now.startOf('month'));
      setToDate(now.endOf('month'));
    } else if (preset === 'Tháng trước') {
      const lastMonth = now.subtract(1, 'month');
      setFromDate(lastMonth.startOf('month'));
      setToDate(lastMonth.endOf('month'));
    } else if (preset === 'Quý này') {
      const qMonth = Math.floor(now.month() / 3) * 3;
      setFromDate(now.month(qMonth).startOf('month'));
      setToDate(now.month(qMonth + 2).endOf('month'));
    } else if (preset === 'Quý trước') {
      const prevQMonth = (Math.floor(now.month() / 3) - 1) * 3;
      const qYear = prevQMonth < 0 ? now.year() - 1 : now.year();
      const actualQMonth = (prevQMonth + 12) % 12;
      setFromDate(now.year(qYear).month(actualQMonth).startOf('month'));
      setToDate(now.year(qYear).month(actualQMonth + 2).endOf('month'));
    } else if (preset === 'Năm nay') {
      setFromDate(now.startOf('year'));
      setToDate(now.endOf('year'));
    } else if (preset === 'Đầu năm đến hiện tại') {
      setFromDate(now.startOf('year'));
      setToDate(now);
    }
  };

  const params = useMemo(() => ({
    ...(fromDate ? { from_date: fromDate.format('YYYY-MM-DD') } : {}),
    ...(toDate ? { to_date: toDate.format('YYYY-MM-DD') } : {}),
    ...(supplierId ? { supplier_id: supplierId } : {}),
  }), [fromDate, toDate, supplierId]);

  const reportQuery = useQuery({
    queryKey: ['purchase-report', params],
    queryFn: async () => parsePurchaseReportResponse((await api.get('/purchase/reports', { params })).data),
  });

  const suppliersQuery = useQuery({
    queryKey: ['purchase-report-suppliers'],
    queryFn: async () => parseSupplierOptions((await api.get('/master/suppliers')).data),
  });

  const reportReady = !reportQuery.isFetching && !reportQuery.isError && reportQuery.data !== undefined;
  const rawRows = reportReady ? reportQuery.data!.data : [];
  const totals = reportReady ? reportQuery.data!.totals : undefined;

  const filteredRows = useMemo(() => {
    if (!searchText.trim()) return rawRows;
    const q = searchText.toLowerCase().trim();
    return rawRows.filter((r) => 
      r.voucher_number.toLowerCase().includes(q) ||
      r.supplier_name.toLowerCase().includes(q) ||
      (r.description && r.description.toLowerCase().includes(q)) ||
      r.source_label.toLowerCase().includes(q)
    );
  }, [rawRows, searchText]);

  const activeTotals = useMemo(() => {
    if (!reportReady || !totals) return undefined;
    if (!searchText.trim()) return totals;
    let sub = 0;
    let disc = 0;
    let tax = 0;
    let total = 0;
    filteredRows.forEach((r) => {
      sub += Number(r.signed_sub_total) || 0;
      disc += Number(r.signed_discount_amount) || 0;
      tax += Number(r.signed_tax_amount) || 0;
      total += Number(r.signed_total_amount) || 0;
    });
    return {
      sub_total: String(sub),
      discount_amount: String(disc),
      tax_amount: String(tax),
      total_amount: String(total),
    };
  }, [reportReady, totals, searchText, filteredRows]);

  const excelColumns = [
    { title: 'Ngày hạch toán', dataIndex: 'accounting_date' },
    { title: 'Ngày chứng từ', dataIndex: 'voucher_date' },
    { title: 'Số chứng từ', dataIndex: 'voucher_number' },
    { title: 'Loại chứng từ', dataIndex: 'source_label' },
    { title: 'Nhà cung cấp', dataIndex: 'supplier_name' },
    { title: 'Diễn giải', dataIndex: 'description' },
    { title: 'Tiền hàng', dataIndex: 'signed_sub_total' },
    { title: 'Chiết khấu', dataIndex: 'signed_discount_amount' },
    { title: 'Tiền thuế GTGT', dataIndex: 'signed_tax_amount' },
    { title: 'Tổng tiền', dataIndex: 'signed_total_amount' },
  ];

  const excelRows = activeTotals
    ? [...filteredRows, {
        description: 'Tổng cộng',
        signed_sub_total: activeTotals.sub_total,
        signed_discount_amount: activeTotals.discount_amount,
        signed_tax_amount: activeTotals.tax_amount,
        signed_total_amount: activeTotals.total_amount,
      }]
    : [];

  const excelAction = (
    <ExportExcelButton
      data={excelRows}
      columns={excelColumns}
      filename="bao-cao-purchase"
      monetaryFields={['signed_sub_total', 'signed_discount_amount', 'signed_tax_amount', 'signed_total_amount']}
      disabled={filteredRows.length === 0}
    />
  );

  const printAllRows = () => {
    flushSync(() => setIsPrinting(true));
    window.print();
    setIsPrinting(false);
  };

  const columns: ColumnsType<PurchaseReportRow> = [
    {
      title: 'Ngày hạch toán',
      dataIndex: 'accounting_date',
      key: 'accounting_date',
      width: 120,
      render: (value) => value ?? '—'
    },
    {
      title: 'Ngày chứng từ',
      dataIndex: 'voucher_date',
      key: 'voucher_date',
      width: 110,
      render: (value) => value ?? '—'
    },
    {
      title: 'Số chứng từ',
      dataIndex: 'voucher_number',
      key: 'voucher_number',
      width: 130,
      render: (value, row) => (
        <Button
          type="link"
          className="misa-text-blue-bold p-0"
          aria-label={`Mở chứng từ ${value}`}
          onClick={() => navigate(commercialReportSourceRoute(row.source_type, row.id))}
        >
          {value}
        </Button>
      )
    },
    {
      title: 'Loại chứng từ',
      dataIndex: 'source_label',
      key: 'source_label',
      width: 155,
      render: (value, row) => {
        const color = row.source_type === 'purchase_invoice' ? 'blue' : row.source_type === 'purchase_return' ? 'orange' : 'purple';
        return <Tag color={color}>{value}</Tag>;
      }
    },
    {
      title: 'Nhà cung cấp',
      dataIndex: 'supplier_name',
      key: 'supplier_name',
      width: 220,
      ellipsis: true,
      render: (value) => value || '—'
    },
    {
      title: 'Diễn giải',
      dataIndex: 'description',
      key: 'description',
      ellipsis: true,
      render: (value) => value || '—'
    },
    {
      title: 'Tiền hàng',
      dataIndex: 'signed_sub_total',
      key: 'signed_sub_total',
      width: 130,
      align: 'right',
      render: (value) => formatMoney(value)
    },
    {
      title: 'Chiết khấu',
      dataIndex: 'signed_discount_amount',
      key: 'signed_discount_amount',
      width: 110,
      align: 'right',
      render: (value) => <span style={{ color: Number(value) > 0 ? '#fa8c16' : undefined }}>{formatMoney(value)}</span>
    },
    {
      title: 'Tiền thuế',
      dataIndex: 'signed_tax_amount',
      key: 'signed_tax_amount',
      width: 110,
      align: 'right',
      render: (value) => <span style={{ color: Number(value) > 0 ? '#722ed1' : undefined }}>{formatMoney(value)}</span>
    },
    {
      title: 'Tổng tiền',
      dataIndex: 'signed_total_amount',
      key: 'signed_total_amount',
      width: 140,
      align: 'right',
      render: (value, row) => (
        <span className={row.source_type === 'purchase_invoice' ? 'misa-text-dark-bold' : 'misa-text-red'}>
          {formatMoney(value)}
        </span>
      )
    },
    {
      title: 'Trạng thái',
      dataIndex: 'is_posted',
      key: 'is_posted',
      width: 105,
      align: 'center',
      render: () => <Tag color="green">Đã ghi sổ</Tag>
    },
  ];

  const reportToolbar = (
    <PageToolbar
      filters={(
        <div className="ui-page-toolbar__filter-group flex items-center flex-wrap gap-2">
          <Input
            placeholder="Tìm số chứng từ, nhà cung cấp, diễn giải..."
            prefix={<SearchOutlined className="misa-color-muted" />}
            className="misa-w-260"
            allowClear
            value={searchText}
            onChange={(e) => setSearchText(e.target.value)}
          />
          <Select
            value={datePreset}
            onChange={handlePresetChange}
            className="misa-w-160"
            options={[
              { value: 'Tất cả', label: 'Tất cả thời gian' },
              { value: 'Hôm nay', label: 'Hôm nay' },
              { value: 'Tuần này', label: 'Tuần này' },
              { value: 'Tháng này', label: 'Tháng này' },
              { value: 'Tháng trước', label: 'Tháng trước' },
              { value: 'Quý này', label: 'Quý này' },
              { value: 'Quý trước', label: 'Quý trước' },
              { value: 'Năm nay', label: 'Năm nay' },
              { value: 'Đầu năm đến hiện tại', label: 'Đầu năm đến hiện tại' },
              { value: 'Tùy chọn', label: 'Tùy chọn' },
            ]}
          />
          <DatePicker
            aria-label="Từ ngày hạch toán"
            placeholder="Từ ngày"
            format="DD/MM/YYYY"
            value={fromDate}
            onChange={(d) => { setFromDate(d); setDatePreset('Tùy chọn'); }}
            allowClear
            style={{ width: 125 }}
          />
          <span className="text-gray-400">—</span>
          <DatePicker
            aria-label="Đến ngày hạch toán"
            placeholder="Đến ngày"
            format="DD/MM/YYYY"
            value={toDate}
            onChange={(d) => { setToDate(d); setDatePreset('Tùy chọn'); }}
            allowClear
            style={{ width: 125 }}
          />
          <Select
            allowClear
            showSearch
            optionFilterProp="label"
            aria-label="Nhà cung cấp"
            placeholder="Tất cả nhà cung cấp"
            value={supplierId}
            options={suppliersQuery.data ?? []}
            onChange={(value) => setSupplierId(value === undefined ? undefined : Number(value))}
            loading={suppliersQuery.isLoading}
            style={{ minWidth: 200 }}
          />
          {(fromDate || toDate || supplierId !== undefined || searchText) && (
            <Button
              size="small"
              onClick={() => {
                setFromDate(null);
                setToDate(null);
                setSupplierId(undefined);
                setSearchText('');
                setDatePreset('Tất cả');
              }}
            >
              Xóa lọc
            </Button>
          )}
        </div>
      )}
      actions={(
        <div className="ui-page-toolbar__action-group flex items-center gap-2">
          <Button
            icon={<ReloadOutlined />}
            onClick={() => void reportQuery.refetch()}
            loading={reportQuery.isFetching}
            title="Tải lại"
            className="misa-btn-tool"
          >
            Tải lại
          </Button>
          <Button
            icon={<PrinterOutlined />}
            onClick={printAllRows}
            disabled={filteredRows.length === 0}
            title="In báo cáo"
            className="misa-btn-tool"
          >
            In
          </Button>
          {excelAction}
          <Button
            icon={<DownloadOutlined />}
            onClick={() => exportPurchaseReport(filteredRows)}
            disabled={filteredRows.length === 0}
            title="Xuất CSV"
            className="misa-btn-tool"
          >
            Xuất CSV
          </Button>
        </div>
      )}
    />
  );

  return (
    <PageShell
      embedded={embedded}
      className="apple-ledger-page"
      title={embedded ? undefined : <PageHeader eyebrow="MUA HÀNG" title="Báo cáo mua hàng" description="Số liệu lấy trực tiếp từ chứng từ mua hàng đã ghi sổ theo ngày hạch toán." />}
      toolbar={!embedded && <PageToolbar
        filters={reportToolbar.props.filters}
        actions={reportToolbar.props.actions}
      />}
    >
      {embedded && reportToolbar}
      <DataTableSurface>
        {reportQuery.isFetching && <p role="status">Đang tải dữ liệu báo cáo...</p>}
        {reportQuery.isError && (
          <Alert
            className="mb-3"
            type="error"
            showIcon
            title="Không thể tải báo cáo mua hàng"
            description="Máy chủ trả về lỗi hoặc cấu trúc dữ liệu không hợp lệ; danh sách không được thay bằng dữ liệu rỗng. Hãy thử tải lại."
            action={<Button size="small" onClick={() => void reportQuery.refetch()}>Thử lại</Button>}
          />
        )}
        {suppliersQuery.isError && (
          <Alert
            className="mb-3"
            type="warning"
            showIcon
            title="Không thể tải danh mục nhà cung cấp"
            description="Báo cáo vẫn hiển thị theo bộ lọc hiện tại; hãy thử lại để chọn nhà cung cấp."
            action={<Button size="small" onClick={() => void suppliersQuery.refetch()}>Thử lại danh mục nhà cung cấp</Button>}
          />
        )}

        {/* 4 Summary Cards */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
          <div
            className="misa-report-summary-card"
            style={{
              background: '#fff',
              border: '1px solid #e8e8e8',
              borderRadius: 8,
              padding: '12px 16px',
              boxShadow: '0 1px 2px rgba(0,0,0,0.03)',
              borderLeft: '4px solid #1677ff',
            }}
          >
            <div style={{ fontSize: 13, color: '#64748b', fontWeight: 500 }}>Tổng tiền hàng</div>
            <div style={{ fontSize: 18, fontWeight: 700, color: '#1677ff', marginTop: 4 }}>
              {activeTotals ? formatMoney(activeTotals.sub_total) : '—'}
            </div>
          </div>

          <div
            className="misa-report-summary-card"
            style={{
              background: '#fff',
              border: '1px solid #e8e8e8',
              borderRadius: 8,
              padding: '12px 16px',
              boxShadow: '0 1px 2px rgba(0,0,0,0.03)',
              borderLeft: '4px solid #fa8c16',
            }}
          >
            <div style={{ fontSize: 13, color: '#64748b', fontWeight: 500 }}>Tiền chiết khấu</div>
            <div style={{ fontSize: 18, fontWeight: 700, color: '#fa8c16', marginTop: 4 }}>
              {activeTotals ? formatMoney(activeTotals.discount_amount) : '—'}
            </div>
          </div>

          <div
            className="misa-report-summary-card"
            style={{
              background: '#fff',
              border: '1px solid #e8e8e8',
              borderRadius: 8,
              padding: '12px 16px',
              boxShadow: '0 1px 2px rgba(0,0,0,0.03)',
              borderLeft: '4px solid #722ed1',
            }}
          >
            <div style={{ fontSize: 13, color: '#64748b', fontWeight: 500 }}>Tiền thuế GTGT</div>
            <div style={{ fontSize: 18, fontWeight: 700, color: '#722ed1', marginTop: 4 }}>
              {activeTotals ? formatMoney(activeTotals.tax_amount) : '—'}
            </div>
          </div>

          <div
            className="misa-report-summary-card"
            style={{
              background: '#fff',
              border: '1px solid #e8e8e8',
              borderRadius: 8,
              padding: '12px 16px',
              boxShadow: '0 1px 2px rgba(0,0,0,0.03)',
              borderLeft: '4px solid #1677ff',
            }}
          >
            <div style={{ fontSize: 13, color: '#64748b', fontWeight: 500 }}>Tổng thanh toán (Giá trị ròng)</div>
            <div style={{ fontSize: 18, fontWeight: 700, color: '#1677ff', marginTop: 4 }}>
              {activeTotals ? formatMoney(activeTotals.total_amount) : '—'}
            </div>
          </div>
        </div>

        {/* Report Table */}
        <Table<PurchaseReportRow>
          rowKey="key"
          columns={columns}
          dataSource={filteredRows}
          loading={reportQuery.isFetching}
          scroll={{ x: 1380 }}
          pagination={isPrinting ? false : { pageSize: 20, showSizeChanger: true, showTotal: (total) => `Tổng số: ${total} bản ghi` }}
          locale={{ emptyText: reportQuery.isError ? 'Không thể xác định dữ liệu báo cáo' : 'Không có chứng từ đã ghi sổ trong bộ lọc' }}
          summary={() => activeTotals && (
            <Table.Summary fixed>
              <Table.Summary.Row className="misa-table-summary-row">
                <Table.Summary.Cell index={0} colSpan={6}>
                  <strong>Tổng cộng ({filteredRows.length} chứng từ)</strong>
                </Table.Summary.Cell>
                <Table.Summary.Cell index={6} align="right">
                  <strong>{formatMoney(activeTotals.sub_total)}</strong>
                </Table.Summary.Cell>
                <Table.Summary.Cell index={7} align="right">
                  <strong style={{ color: '#fa8c16' }}>{formatMoney(activeTotals.discount_amount)}</strong>
                </Table.Summary.Cell>
                <Table.Summary.Cell index={8} align="right">
                  <strong style={{ color: '#722ed1' }}>{formatMoney(activeTotals.tax_amount)}</strong>
                </Table.Summary.Cell>
                <Table.Summary.Cell index={9} align="right">
                  <strong style={{ color: '#1677ff', fontSize: 14 }}>{formatMoney(activeTotals.total_amount)}</strong>
                </Table.Summary.Cell>
                <Table.Summary.Cell index={10} />
              </Table.Summary.Row>
            </Table.Summary>
          )}
        />
      </DataTableSurface>
    </PageShell>
  );
};

export default PurchaseReports;
