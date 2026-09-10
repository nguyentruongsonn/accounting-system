import { describe, expect, it } from 'vitest';
import salesReturns from './SalesReturns.tsx?raw';
import salesDiscounts from './SalesDiscounts.tsx?raw';
import purchaseReturns from '../purchase/PurchaseReturns.tsx?raw';
import purchaseDiscounts from '../purchase/PurchaseDiscounts.tsx?raw';

describe('sales adjustment list loading boundary', () => {
  it('uses one filter layout wrapper for the sales-discount toolbar', () => {
    const nestedToolbarWrapper = /<div className="misa-toolbar-left">\s*<div className="misa-toolbar-left">/;

    expect(salesDiscounts).toContain('<PageToolbar');
    expect(salesDiscounts).not.toMatch(nestedToolbarWrapper);
  });

  it('keeps API failures visible and retryable instead of presenting an empty list', () => {
    for (const source of [salesReturns, salesDiscounts, purchaseReturns, purchaseDiscounts]) {
      expect(source).toContain('isError: isListError');
      expect(source).toContain('Không thể tải danh sách chứng từ');
      expect(source).toContain('refetchList');
      expect(source).not.toContain('} catch {\n                return [];');
    }
  });
});
