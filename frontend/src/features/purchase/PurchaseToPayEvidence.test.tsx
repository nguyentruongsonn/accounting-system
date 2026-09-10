import { describe, expect, it } from 'vitest';
import source from './PurchaseInvoices.tsx?raw';

describe('purchase-to-pay response boundary', () => {
  it('rejects malformed invoice list payloads instead of presenting an empty list as valid', () => {
    expect(source).toContain("throw new Error('Invalid purchase-invoice list response')");
    expect(source).toContain("setIsPayByInvoiceModalOpen(true)");
    expect(source).not.toContain("return Array.isArray(data) ? data : (data?.data || []);");
  });
});
