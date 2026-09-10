import { describe, expect, it } from 'vitest';
import purchaseReports from '../purchase/PurchaseReports.tsx?raw';
import salesReports from '../sales/SalesReports.tsx?raw';
import stockCounts from '../inventory/InventoryStockCounts.tsx?raw';
import transfers from '../inventory/InventoryTransfers.tsx?raw';
import glWorkspace from '../gl/GLWorkspace.tsx?raw';
import generalJournals from '../gl/GeneralJournals.tsx?raw';
import closingEntries from '../gl/ClosingEntries.tsx?raw';

describe('workspace embedded surface contract', () => {
  it('lets workspace tabs suppress their nested page shell while retaining standalone mode', () => {
    for (const source of [purchaseReports, salesReports, stockCounts, transfers]) {
      expect(source).toContain('embedded?: boolean');
      expect(source).toContain('embedded={embedded}');
    }
  });

  it('keeps general-ledger workspace tabs embedded instead of nesting page shells', () => {
    expect(glWorkspace).toMatch(/<GeneralJournals\s+embedded\s*\/>/);
    expect(glWorkspace).toMatch(/<ClosingEntries\s+embedded\s*\/>/);
    expect(generalJournals).toContain('embedded?: boolean');
    expect(generalJournals).toContain('embedded={embedded}');
    expect(closingEntries).toContain('embedded?: boolean');
    expect(closingEntries).toContain('embedded={embedded}');
  });

  it('keeps the journal create and refresh actions in the parent workspace toolbar', () => {
    expect(glWorkspace).toContain('open-general-journal');
    expect(glWorkspace).toContain('refresh-general-journals');
    expect(generalJournals).toContain("window.addEventListener('open-general-journal'");
    expect(generalJournals).toContain("window.addEventListener('refresh-general-journals'");
  });
});
