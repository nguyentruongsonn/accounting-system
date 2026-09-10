import { describe, expect, it } from 'vitest';
import { parseCustomerOptions, parseSalesReportResponse } from './SalesReports';

const validReport = {
  data: [{
    id: 1,
    source_type: 'sales_invoice',
    source_label: 'Bán hàng',
    voucher_number: 'BH-001',
    voucher_date: '2026-08-10',
    accounting_date: '2026-08-10',
    customer_id: 2,
    customer_name: 'Khách hàng',
    description: 'Bán hàng',
    sub_total: '100.00',
    discount_amount: '0.00',
    tax_amount: '10.00',
    total_amount: '110.00',
    signed_sub_total: '100.00',
    signed_discount_amount: '0.00',
    signed_tax_amount: '10.00',
    signed_total_amount: '110.00',
    is_posted: true,
  }],
  totals: { sub_total: '100.00', discount_amount: '0.00', tax_amount: '10.00', total_amount: '110.00' },
  meta: {
    report_key: 'sales_activity',
    date_basis: 'accounting_date',
    source: ['sales_invoices', 'sales_returns', 'sales_discounts'],
    posted_only: true,
    from_date: '2026-08-01',
    to_date: '2026-08-31',
    customer_id: 2,
  },
};

describe('parseSalesReportResponse', () => {
  it('normalizes server-backed posted sales activity and exact totals', () => {
    const parsed = parseSalesReportResponse(validReport);
    expect(parsed.data[0].key).toBe('sales_invoice:1');
    expect(parsed.data[0].signed_total_amount).toBe('110.00');
    expect(parsed.totals.total_amount).toBe('110.00');
  });

  it('fails closed when a row is not posted or metadata is not accounting-date only', () => {
    expect(() => parseSalesReportResponse({
      ...validReport,
      data: [{ ...validReport.data[0], is_posted: false }],
    })).toThrow(/not posted/i);
    expect(() => parseSalesReportResponse({
      ...validReport,
      meta: { ...validReport.meta, posted_only: false },
    })).toThrow(/metadata/i);
  });
});

describe('parseCustomerOptions', () => {
  it('accepts a valid customer catalogue envelope and preserves the display label', () => {
    expect(parseCustomerOptions({ data: [{ id: 2, code: 'KH-02', name: 'Khách hàng' }] })).toEqual([
      { value: 2, label: 'KH-02 - Khách hàng' },
    ]);
  });

  it('fails closed on a malformed catalogue row instead of hiding it as empty', () => {
    expect(() => parseCustomerOptions([{ id: 2, name: '' }])).toThrow(/customer catalogue row/i);
  });
});
