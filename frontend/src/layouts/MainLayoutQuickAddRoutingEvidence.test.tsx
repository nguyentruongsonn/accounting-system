import { describe, expect, it } from 'vitest';
import cashReceipts from '../features/cash/CashReceipts.tsx?raw';
import cashPayments from '../features/cash/CashPayments.tsx?raw';
import purchaseInvoices from '../features/purchase/PurchaseInvoices.tsx?raw';
import salesInvoices from '../features/sales/SalesInvoices.tsx?raw';
import inventoryReceipts from '../features/inventory/InventoryReceipts.tsx?raw';
import inventoryIssues from '../features/inventory/InventoryIssues.tsx?raw';

describe('quick-add create handoff', () => {
  it('turns action=create deep links into the existing create form handlers', () => {
    expect(cashReceipts).toContain('useSearchParams');
    expect(cashReceipts).toContain("const action = searchParams.get('action')");
    expect(cashReceipts).toContain("action === 'create'");

    for (const source of [cashPayments, purchaseInvoices, salesInvoices]) {
      expect(source).toContain('useSearchParams');
      expect(source).toContain("searchParams.get('action') === 'create'");
    }
    for (const source of [inventoryReceipts, inventoryIssues]) {
      expect(source).toContain('new URLSearchParams(window.location.search)');
      expect(source).toContain("searchParams.get('action') === 'create'");
    }
  });
});
