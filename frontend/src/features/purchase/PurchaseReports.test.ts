import { describe, expect, it } from 'vitest';
import { parsePurchaseReportResponse, parseSupplierOptions } from './PurchaseReports';

const row = {
  id: 7,
  source_type: 'purchase_invoice',
  source_label: 'Mua hàng',
  voucher_number: 'PN-001',
  voucher_date: '2026-08-10',
  accounting_date: '2026-08-10',
  supplier_id: 3,
  supplier_name: 'Nhà cung cấp A',
  description: 'Mua vật tư',
  sub_total: '100.00',
  discount_amount: '0.00',
  tax_amount: '10.00',
  total_amount: '110.00',
  signed_sub_total: '100.00',
  signed_discount_amount: '0.00',
  signed_tax_amount: '10.00',
  signed_total_amount: '110.00',
  is_posted: true,
};

const payload = {
  data: [row],
  totals: { sub_total: '100.00', discount_amount: '0.00', tax_amount: '10.00', total_amount: '110.00' },
  meta: {
    report_key: 'purchase_activity',
    date_basis: 'accounting_date',
    source: ['purchase_invoices', 'purchase_returns', 'purchase_discounts'],
    posted_only: true,
    from_date: null,
    to_date: null,
    supplier_id: null,
  },
};

describe('parsePurchaseReportResponse', () => {
  it('normalizes server decimal strings and provides stable row keys', () => {
    const result = parsePurchaseReportResponse(payload);

    expect(result.data[0].key).toBe('purchase_invoice:7');
    expect(result.data[0].total_amount).toBe('110.00');
    expect(result.meta.posted_only).toBe(true);
  });

  it('fails closed when a response contains an unposted row', () => {
    expect(() => parsePurchaseReportResponse({ ...payload, data: [{ ...row, is_posted: false }] })).toThrow(/not posted/);
  });

  it('fails closed when totals are missing', () => {
    expect(() => parsePurchaseReportResponse({ ...payload, totals: undefined })).toThrow(/totals/);
  });
});

describe('parseSupplierOptions', () => {
  it('accepts a valid catalogue envelope and preserves the display label', () => {
    expect(parseSupplierOptions({ data: [{ id: 3, code: 'NCC-03', name: 'Nhà cung cấp A' }] })).toEqual([
      { value: 3, label: 'NCC-03 - Nhà cung cấp A' },
    ]);
  });

  it('fails closed on a malformed catalogue row instead of hiding it as empty', () => {
    expect(() => parseSupplierOptions([{ id: 3, name: '' }])).toThrow(/supplier catalogue row/i);
  });
});
