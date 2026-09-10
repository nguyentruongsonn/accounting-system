import { describe, expect, it } from 'vitest';
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

const voucherSources = [
  cashPayments,
  cashTransactions,
  salesInvoices,
  salesOrders,
  salesQuotes,
  purchaseInvoices,
  purchaseOrders,
  purchaseContracts,
  inventoryReceipts,
  inventoryIssues,
  generalJournals,
];

describe('voucher surface structure', () => {
  it('uses the shared shell adapters for every voucher surface', () => {
    for (const source of voucherSources) {
      expect(source).toContain("from '../../components/layout/PageShell'");
      expect(source).toContain("from '../../components/layout/PageToolbar'");
      expect(source).toContain("from '../../components/layout/DataTableSurface'");
      expect(source).toContain('<PageShell');
      expect(source).toContain('<PageToolbar');
      expect(source).toContain('<DataTableSurface');
    }
  });

  it('keeps native editable table markup on cash and journal surfaces', () => {
    expect(cashPayments).toContain('<table');
    expect(cashTransactions).toContain('<table');
    expect(generalJournals).toContain('<table');
  });
});
