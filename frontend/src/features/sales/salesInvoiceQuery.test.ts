import { describe, expect, it } from 'vitest';
import { parseSalesInvoiceCollection } from './salesInvoiceQuery';

describe('parseSalesInvoiceCollection', () => {
  it('accepts bare lists and API data envelopes', () => {
    expect(parseSalesInvoiceCollection([{ id: 1 }], 'sales invoices')).toEqual([{ id: 1 }]);
    expect(parseSalesInvoiceCollection({ data: [{ id: 2 }] }, 'sales invoices')).toEqual([{ id: 2 }]);
  });

  it('rejects malformed catalogue responses', () => {
    expect(() => parseSalesInvoiceCollection({ data: { id: 3 } }, 'sales invoices'))
      .toThrow('Invalid sales invoices response');
  });
});
