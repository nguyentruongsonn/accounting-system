import { describe, expect, it } from 'vitest';
import { normalizePurchaseInvoiceListResponse } from './purchaseInvoiceQuery';

describe('normalizePurchaseInvoiceListResponse', () => {
  it('accepts the API list envelope', () => {
    expect(normalizePurchaseInvoiceListResponse({
      data: [{ id: 7, invoice_number: 'HDMH-0007' }],
    })).toEqual([{ id: 7, invoice_number: 'HDMH-0007' }]);
  });

  it('accepts a bare list and rejects malformed responses', () => {
    expect(normalizePurchaseInvoiceListResponse([{ id: 8 }])).toEqual([{ id: 8 }]);
    expect(() => normalizePurchaseInvoiceListResponse({ data: { id: 9 } }))
      .toThrow('Invalid purchase-invoice list response');
  });
});
