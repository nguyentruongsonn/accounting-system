import { describe, expect, it } from 'vitest';
import { parseCashReportResponse } from './cashReportContract';

type Code = 'S03a1-DNN' | 'S03a2-DNN' | 'CA-01' | 'CA-02' | 'CA-03';

type Payload = {
  report: Record<string, unknown>;
  columns: Array<{ key: string; label: string; type: string }>;
  summary: Record<string, number>;
  rows: Array<Record<string, unknown>>;
};

const journalColumns = [
  ['posting_date', 'date'], ['voucher_date', 'date'], ['voucher_number', 'text'],
  ['contact_name', 'text'], ['description', 'text'], ['cash_account', 'text'],
  ['counterpart_account', 'text'], ['amount', 'money'], ['status', 'status'],
] as const;

const columnsByCode = {
  'S03a1-DNN': journalColumns,
  'S03a2-DNN': journalColumns,
  'CA-01': [
    ['posting_date', 'date'], ['opening_balance', 'money'], ['total_receipts', 'money'],
    ['total_payments', 'money'], ['closing_balance', 'money'],
  ],
  'CA-02': [
    ['posting_date', 'date'], ['direction', 'text'], ['transaction_count', 'number'], ['amount', 'money'],
  ],
  'CA-03': [
    ['posting_date', 'date'], ['voucher_date', 'date'], ['voucher_number', 'text'], ['direction', 'text'],
    ['contact_name', 'text'], ['description', 'text'], ['cash_account', 'text'], ['counterpart_account', 'text'],
    ['receipt_amount', 'money'], ['payment_amount', 'money'], ['running_balance', 'money'],
  ],
} as const;

const reportNames: Record<Code, string> = {
  'S03a1-DNN': 'Sổ nhật ký thu tiền',
  'S03a2-DNN': 'Sổ nhật ký chi tiền',
  'CA-01': 'Bảng kê số dư tiền theo ngày',
  'CA-02': 'Dòng tiền',
  'CA-03': 'Sổ kế toán chi tiết quỹ tiền mặt',
};

function validPayload(code: Code): Payload {
  const common = {
    report: {
      code,
      name: reportNames[code],
      date_from: '2026-08-01',
      date_to: '2026-08-31',
      status: code.startsWith('S') ? 'all' : 'posted',
      cash_account: code === 'CA-03' ? '1111' : null,
      search: 'invoice 10',
    },
    columns: columnsByCode[code].map(([key, type]) => ({ key, label: `Column ${key}`, type })),
  };

  if (code === 'S03a1-DNN' || code === 'S03a2-DNN') {
    return {
      ...common,
      summary: code === 'S03a1-DNN'
        ? { row_count: 1, total_receipts: 1000 }
        : { row_count: 1, total_payments: 1000 },
      rows: [{
        key: `${code}:1`, posting_date: '2026-08-10', voucher_date: '2026-08-09', voucher_number: 'PT-10',
        contact_name: '', description: '', cash_account: '1111', counterpart_account: '131', amount: 1000, status: 'posted',
        ignored_markup: '<img src=x onerror=alert(1)>',
      }],
    };
  }

  if (code === 'CA-01') {
    return {
      ...common,
      summary: { opening_balance: 500, total_receipts: 1000, total_payments: 250, closing_balance: 1250 },
      rows: [{ key: 'CA-01:2026-08-10', posting_date: '2026-08-10', opening_balance: 500, total_receipts: 1000, total_payments: 250, closing_balance: 1250 }],
    };
  }

  if (code === 'CA-02') {
    return {
      ...common,
      summary: { total_receipts: 1000, total_payments: 250, net_cash_flow: 750 },
      rows: [{ key: 'CA-02:2026-08-10:receipt', posting_date: '2026-08-10', direction: 'receipt', transaction_count: 1, amount: 1000 }],
    };
  }

  return {
    ...common,
    summary: { opening_balance: 500, total_receipts: 1000, total_payments: 250, closing_balance: 1250 },
    rows: [{
      key: 'CA-03:receipt:10:20', posting_date: '2026-08-10', voucher_date: '2026-08-09', voucher_number: 'PT-10',
      direction: 'receipt', contact_name: '', description: '', cash_account: '1111', counterpart_account: '131',
      receipt_amount: 1000, payment_amount: 0, running_balance: 1500,
    }],
  };
}

describe('parseCashReportResponse', () => {
  it('rejects an envelope or another malformed inner response', () => {
    expect(() => parseCashReportResponse({ data: [] })).toThrow('Invalid cash report response');
    expect(() => parseCashReportResponse([])).toThrow('Invalid cash report response');
  });

  it.each<Code>(['S03a1-DNN', 'S03a2-DNN', 'CA-01', 'CA-02', 'CA-03'])('accepts the complete %s server contract', (code) => {
    const parsed = parseCashReportResponse(validPayload(code));

    expect(parsed.report.code).toBe(code);
    expect(parsed.rows[0].key).toBeTruthy();
    expect(parsed.rows[0]).not.toHaveProperty('ignored_markup');
  });

  it('accepts an empty CA-03 period with valid nonzero balances without recomputing totals', () => {
    const payload = validPayload('CA-03');
    payload.rows = [];
    payload.summary = { opening_balance: 900, total_receipts: 0, total_payments: 0, closing_balance: 900 };

    expect(parseCashReportResponse(payload).summary).toEqual(payload.summary);
  });

  it('preserves a validated source identity for journal and ledger drilldown', () => {
    const payload = validPayload('S03a1-DNN');
    payload.rows[0].source_type = 'receipt';
    payload.rows[0].source_id = 42;

    const parsed = parseCashReportResponse(payload);

    expect(parsed.rows[0]).toMatchObject({ source_type: 'receipt', source_id: 42 });
  });

  it('rejects partial or unsafe source identities', () => {
    const partial = validPayload('S03a1-DNN');
    partial.rows[0].source_type = 'receipt';
    expect(() => parseCashReportResponse(partial)).toThrow('Invalid cash report response');

    const unsafe = validPayload('S03a1-DNN');
    unsafe.rows[0].source_type = 'receipt';
    unsafe.rows[0].source_id = 0;
    expect(() => parseCashReportResponse(unsafe)).toThrow('Invalid cash report response');
  });

  it('rejects unsupported reports, statuses, cash accounts, and unnormalised search metadata', () => {
    const unknownCode = validPayload('CA-01');
    unknownCode.report.code = 'NOPE' as never;
    expect(() => parseCashReportResponse(unknownCode)).toThrow('Invalid cash report response');

    const allBalance = validPayload('CA-01');
    allBalance.report.status = 'all';
    expect(() => parseCashReportResponse(allBalance)).toThrow('Invalid cash report response');

    const invalidAccount = validPayload('CA-03');
    invalidAccount.report.cash_account = '1121';
    expect(() => parseCashReportResponse(invalidAccount)).toThrow('Invalid cash report response');

    const unnormalisedSearch = validPayload('CA-02');
    unnormalisedSearch.report.search = ' invoice 10 ';
    expect(() => parseCashReportResponse(unnormalisedSearch)).toThrow('Invalid cash report response');
  });

  it('rejects malformed or inverted ISO report and cell dates', () => {
    const malformed = validPayload('CA-01');
    malformed.report.date_from = '2026/08/01';
    expect(() => parseCashReportResponse(malformed)).toThrow('Invalid cash report response');

    const inverted = validPayload('CA-01');
    inverted.report.date_from = '2026-09-01';
    expect(() => parseCashReportResponse(inverted)).toThrow('Invalid cash report response');

    const invalidCell = validPayload('CA-02');
    invalidCell.rows[0]['posting_date'] = '2026-02-30';
    expect(() => parseCashReportResponse(invalidCell)).toThrow('Invalid cash report response');
  });

  it('rejects duplicate, missing, or unsupported columns and report-specific summary keys', () => {
    const duplicateColumn = validPayload('CA-02');
    duplicateColumn.columns.push({ key: 'amount', label: 'Again', type: 'money' });
    expect(() => parseCashReportResponse(duplicateColumn)).toThrow('Invalid cash report response');

    const unsupportedType = validPayload('CA-02');
    unsupportedType.columns[0].type = 'html';
    expect(() => parseCashReportResponse(unsupportedType)).toThrow('Unsupported cash report column type');

    const extraColumn = validPayload('CA-02');
    extraColumn.columns.push({ key: 'uncontracted_note', label: 'Uncontracted note', type: 'text' });
    extraColumn.rows[0]['uncontracted_note'] = '<img src=x onerror=alert(1)>';
    expect(() => parseCashReportResponse(extraColumn)).toThrow('Invalid cash report response');

    const missingColumn = validPayload('CA-03');
    missingColumn.columns = missingColumn.columns.filter((column) => column.key !== 'running_balance');
    expect(() => parseCashReportResponse(missingColumn)).toThrow('Invalid cash report response');

    const missingSummary = validPayload('CA-02');
    delete missingSummary.summary.net_cash_flow;
    expect(() => parseCashReportResponse(missingSummary)).toThrow('Invalid cash report response');
  });

  it('rejects corrupt cells, unsafe money/counts, and duplicate row keys', () => {
    const corruptCell = validPayload('S03a1-DNN');
    corruptCell.rows[0]['amount'] = '1000';
    expect(() => parseCashReportResponse(corruptCell)).toThrow('Invalid cash report response');

    const unsafeMoney = validPayload('CA-03');
    unsafeMoney.summary.closing_balance = Number.MAX_SAFE_INTEGER + 1;
    expect(() => parseCashReportResponse(unsafeMoney)).toThrow('Invalid cash report response');

    const unsafeCount = validPayload('CA-02');
    unsafeCount.rows[0]['transaction_count'] = -1;
    expect(() => parseCashReportResponse(unsafeCount)).toThrow('Invalid cash report response');

    const duplicateRows = validPayload('CA-02');
    duplicateRows.rows.push({ ...duplicateRows.rows[0] });
    expect(() => parseCashReportResponse(duplicateRows)).toThrow('Invalid cash report response');
  });
});
