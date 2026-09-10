import { describe, expect, it } from 'vitest';
import { commercialReportSourceRoute, parseSourceRecordId } from './commercialReportSource';

describe('commercial report source navigation', () => {
  it.each([
    ['purchase_invoice', 7, '/purchase/invoices?source_id=7'],
    ['purchase_return', 8, '/purchase?tab=6&source_id=8'],
    ['purchase_discount', 9, '/purchase?tab=7&source_id=9'],
    ['sales_invoice', 10, '/sales/invoices?source_id=10'],
    ['sales_return', 11, '/sales?tab=return&source_id=11'],
    ['sales_discount', 12, '/sales?tab=discount&source_id=12'],
  ] as const)('routes %s to its source record', (type, id, expected) => {
    expect(commercialReportSourceRoute(type, id)).toBe(expected);
  });

  it('rejects unsupported source types and invalid record identities', () => {
    expect(() => commercialReportSourceRoute('unknown', 1)).toThrow(/unsupported/i);
    expect(() => commercialReportSourceRoute('sales_invoice', 0)).toThrow(/identity/i);
    expect(parseSourceRecordId('15')).toBe(15);
    expect(parseSourceRecordId('0')).toBeNull();
    expect(parseSourceRecordId('abc')).toBeNull();
  });
});
