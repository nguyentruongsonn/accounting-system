import { describe, expect, it } from 'vitest';
import { parseCashPaymentCollection, parseCashReceiptCollection } from './cashVoucherQueries';

describe('cash voucher collection parsers', () => {
  it('accepts bare lists and data envelopes', () => {
    expect(parseCashReceiptCollection([{ id: 1 }], 'cash receipts')).toEqual([{ id: 1 }]);
    expect(parseCashPaymentCollection({ data: [{ id: 2 }] })).toEqual([{ id: 2 }]);
  });

  it('rejects malformed responses with the resource name', () => {
    expect(() => parseCashReceiptCollection({ data: { id: 3 } }, 'cash receipts'))
      .toThrow('Invalid cash receipts response');
    expect(() => parseCashPaymentCollection({ data: { id: 4 } }))
      .toThrow('Invalid cash payments response');
  });
});
