export type CashReportCode = 'S03a1-DNN' | 'S03a2-DNN' | 'CA-01' | 'CA-02' | 'CA-03';
export type CashReportColumnType = 'date' | 'text' | 'money' | 'status' | 'number';
export type CashReportStatus = 'posted' | 'draft' | 'voided' | 'all';
export type CashReportSourceType = 'receipt' | 'payment';

export interface CashReportFilters {
  date_from: string;
  date_to: string;
  search?: string;
  status: CashReportStatus;
  cash_account?: string;
}

export interface CashReportColumn {
  key: string;
  label: string;
  type: CashReportColumnType;
}

export interface ReportDefinition {
  code: CashReportCode;
  name: string;
  group: 'Nhật ký' | 'Phân tích' | 'Sổ quỹ';
  search: string;
}

export interface CashReportResponse {
  report: {
    code: CashReportCode;
    name: string;
    date_from: string;
    date_to: string;
    status: CashReportStatus;
    cash_account: string | null;
    search: string;
  };
  columns: CashReportColumn[];
  summary: Record<string, number>;
  rows: Array<Record<string, unknown>>;
}

export const REPORTS = [
  { code: 'S03a1-DNN', name: 'Sổ nhật ký thu tiền', group: 'Nhật ký', search: '' },
  { code: 'S03a2-DNN', name: 'Sổ nhật ký chi tiền', group: 'Nhật ký', search: '' },
  { code: 'CA-01', name: 'Bảng kê số dư tiền theo ngày', group: 'Sổ quỹ', search: '' },
  { code: 'CA-02', name: 'Dòng tiền', group: 'Phân tích', search: '' },
  { code: 'CA-03', name: 'Sổ kế toán chi tiết quỹ tiền mặt', group: 'Sổ quỹ', search: '' },
] as const satisfies readonly ReportDefinition[];

const CODES = new Set<CashReportCode>(REPORTS.map((report) => report.code));
const STATUSES = new Set<CashReportStatus>(['posted', 'draft', 'voided', 'all']);
const ROW_STATUSES = new Set<Exclude<CashReportStatus, 'all'>>(['posted', 'draft', 'voided']);
const COLUMN_TYPES = new Set<CashReportColumnType>(['date', 'text', 'money', 'status', 'number']);
const SAFE_INTEGER = Number.MAX_SAFE_INTEGER;

const requiredColumns: Record<CashReportCode, readonly CashReportColumn[]> = {
  'S03a1-DNN': [
    column('posting_date', 'date'), column('voucher_date', 'date'), column('voucher_number', 'text'),
    column('contact_name', 'text'), column('description', 'text'), column('cash_account', 'text'),
    column('counterpart_account', 'text'), column('amount', 'money'), column('status', 'status'),
  ],
  'S03a2-DNN': [
    column('posting_date', 'date'), column('voucher_date', 'date'), column('voucher_number', 'text'),
    column('contact_name', 'text'), column('description', 'text'), column('cash_account', 'text'),
    column('counterpart_account', 'text'), column('amount', 'money'), column('status', 'status'),
  ],
  'CA-01': [
    column('posting_date', 'date'), column('opening_balance', 'money'), column('total_receipts', 'money'),
    column('total_payments', 'money'), column('closing_balance', 'money'),
  ],
  'CA-02': [
    column('posting_date', 'date'), column('direction', 'text'), column('transaction_count', 'number'), column('amount', 'money'),
  ],
  'CA-03': [
    column('posting_date', 'date'), column('voucher_date', 'date'), column('voucher_number', 'text'), column('direction', 'text'),
    column('contact_name', 'text'), column('description', 'text'), column('cash_account', 'text'), column('counterpart_account', 'text'),
    column('receipt_amount', 'money'), column('payment_amount', 'money'), column('running_balance', 'money'),
  ],
};

const requiredSummary: Record<CashReportCode, readonly string[]> = {
  'S03a1-DNN': ['row_count', 'total_receipts'],
  'S03a2-DNN': ['row_count', 'total_payments'],
  'CA-01': ['opening_balance', 'total_receipts', 'total_payments', 'closing_balance'],
  'CA-02': ['total_receipts', 'total_payments', 'net_cash_flow'],
  'CA-03': ['opening_balance', 'total_receipts', 'total_payments', 'closing_balance'],
};

function column(key: string, type: CashReportColumnType): CashReportColumn {
  return { key, label: key, type };
}

function invalid(): never {
  throw new Error('Invalid cash report response');
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function hasOwn(value: Record<string, unknown>, key: string): boolean {
  return Object.prototype.hasOwnProperty.call(value, key);
}

function string(value: unknown, allowEmpty = false): string {
  if (typeof value !== 'string' || (!allowEmpty && value.trim() === '')) invalid();
  return value;
}

function isIsoDate(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const date = new Date(`${value}T00:00:00.000Z`);
  return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === value;
}

function isoDate(value: unknown): string {
  const date = string(value);
  if (!isIsoDate(date)) invalid();
  return date;
}

function safeInteger(value: unknown, nonNegative = false): number {
  if (typeof value !== 'number' || !Number.isSafeInteger(value) || Math.abs(value) > SAFE_INTEGER || (nonNegative && value < 0)) invalid();
  return value;
}

function cashAccount(value: unknown): string | null {
  if (value === null) return null;
  const account = string(value);
  if (!/^111\d*$/.test(account)) invalid();
  return account;
}

function reportCode(value: unknown): CashReportCode {
  if (typeof value !== 'string' || !CODES.has(value as CashReportCode)) invalid();
  return value as CashReportCode;
}

function status(value: unknown): CashReportStatus {
  if (typeof value !== 'string' || !STATUSES.has(value as CashReportStatus)) invalid();
  return value as CashReportStatus;
}

function parseColumns(value: unknown, code: CashReportCode): CashReportColumn[] {
  if (!Array.isArray(value)) invalid();
  const keys = new Set<string>();
  const columns = value.map((item): CashReportColumn => {
    if (!isRecord(item)) invalid();
    const key = string(item.key);
    if (!/^[A-Za-z][A-Za-z0-9_]*$/.test(key) || keys.has(key)) invalid();
    const label = string(item.label);
    if (typeof item.type !== 'string' || !COLUMN_TYPES.has(item.type as CashReportColumnType)) {
      throw new Error('Unsupported cash report column type');
    }
    keys.add(key);
    return { key, label, type: item.type as CashReportColumnType };
  });

  const expectedColumns = requiredColumns[code];
  if (columns.length !== expectedColumns.length) invalid();
  for (const currentColumn of columns) {
    if (!expectedColumns.some((expected) => expected.key === currentColumn.key && expected.type === currentColumn.type)) invalid();
  }
  return columns;
}

function parseSummary(value: unknown, code: CashReportCode): Record<string, number> {
  if (!isRecord(value)) invalid();
  const summary: Record<string, number> = Object.create(null) as Record<string, number>;
  for (const key of requiredSummary[code]) {
    if (!hasOwn(value, key)) invalid();
    summary[key] = safeInteger(value[key], key === 'row_count');
  }
  return summary;
}

function parseCell(value: unknown, type: CashReportColumnType): string | number {
  if (type === 'date') return isoDate(value);
  if (type === 'text') return string(value, true);
  if (type === 'money') return safeInteger(value);
  if (type === 'number') return safeInteger(value, true);
  if (typeof value !== 'string' || !ROW_STATUSES.has(value as Exclude<CashReportStatus, 'all'>)) invalid();
  return value;
}

function parseRows(value: unknown, columns: CashReportColumn[]): Array<Record<string, unknown>> {
  if (!Array.isArray(value)) invalid();
  const keys = new Set<string>();
  return value.map((item) => {
    if (!isRecord(item)) invalid();
    const key = string(item.key);
    if (keys.has(key)) invalid();
    keys.add(key);
    const row: Record<string, unknown> = Object.create(null) as Record<string, unknown>;
    row.key = key;
    for (const currentColumn of columns) {
      if (!hasOwn(item, currentColumn.key)) invalid();
      row[currentColumn.key] = parseCell(item[currentColumn.key], currentColumn.type);
    }
    const hasSourceType = hasOwn(item, 'source_type');
    const hasSourceId = hasOwn(item, 'source_id');
    if (hasSourceType !== hasSourceId) invalid();
    if (hasSourceType) {
      const sourceType = item.source_type;
      if (typeof sourceType !== 'string' || (sourceType !== 'receipt' && sourceType !== 'payment')) invalid();
      const sourceId = safeInteger(item.source_id, true);
      if (sourceId <= 0) invalid();
      row.source_type = sourceType;
      row.source_id = sourceId;
    }
    return row;
  });
}

/** Parses the server's inner `{ report, columns, summary, rows }` value, not its HTTP envelope. */
export function parseCashReportResponse(value: unknown): CashReportResponse {
  if (!isRecord(value) || !isRecord(value.report)) invalid();
  const code = reportCode(value.report.code);
  const dateFrom = isoDate(value.report.date_from);
  const dateTo = isoDate(value.report.date_to);
  if (dateFrom > dateTo) invalid();
  const reportStatus = status(value.report.status);
  if ((code === 'CA-01' || code === 'CA-02' || code === 'CA-03') && reportStatus !== 'posted') invalid();
  const search = string(value.report.search, true);
  if (search !== search.trim()) invalid();
  const columns = parseColumns(value.columns, code);

  return {
    report: {
      code,
      name: string(value.report.name),
      date_from: dateFrom,
      date_to: dateTo,
      status: reportStatus,
      cash_account: cashAccount(value.report.cash_account),
      search,
    },
    columns,
    summary: parseSummary(value.summary, code),
    rows: parseRows(value.rows, columns),
  };
}
