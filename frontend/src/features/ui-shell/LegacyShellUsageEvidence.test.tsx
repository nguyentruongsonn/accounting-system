import { describe, expect, it } from 'vitest';
import cashReceipts from '../cash/CashReceipts.tsx?raw';
import purchaseReturns from '../purchase/PurchaseReturns.tsx?raw';
import cashPayments from '../cash/CashPayments.tsx?raw';
import cashTransactions from '../cash/CashTransactions.tsx?raw';
import salesInvoices from '../sales/SalesInvoices.tsx?raw';
import salesOrders from '../sales/SalesOrders.tsx?raw';
import salesQuotes from '../sales/SalesQuotes.tsx?raw';
import purchaseInvoices from '../purchase/PurchaseInvoices.tsx?raw';
import purchaseOrders from '../purchase/PurchaseOrders.tsx?raw';
import purchaseContracts from '../purchase/PurchaseContracts.tsx?raw';
import inventoryReceipts from '../inventory/InventoryReceipts.tsx?raw';
import inventoryIssues from '../inventory/InventoryIssues.tsx?raw';
import generalJournals from '../gl/GeneralJournals.tsx?raw';

describe('legacy shell usage', () => {
  it('does not reintroduce retired presentation classes on migrated surfaces', () => {
    const migrated = [cashReceipts, purchaseReturns, cashPayments, cashTransactions, salesInvoices, salesOrders,
      salesQuotes, purchaseInvoices, purchaseOrders, purchaseContracts, inventoryReceipts, inventoryIssues, generalJournals];
    for (const source of migrated) {
      expect(source).not.toMatch(/apple-section-heading|cash-inline-note|misa-btn-tool-circle-green|misa-report-notice|misa-workspace-shell/);
    }
  });
});
