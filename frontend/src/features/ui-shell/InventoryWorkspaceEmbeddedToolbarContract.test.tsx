import { describe, expect, it } from 'vitest';
import inventoryWorkspace from '../inventory/InventoryWorkspace.tsx?raw';
import stockCounts from '../inventory/InventoryStockCounts.tsx?raw';
import transfers from '../inventory/InventoryTransfers.tsx?raw';
import receipts from '../inventory/InventoryReceipts.tsx?raw';
import issues from '../inventory/InventoryIssues.tsx?raw';
import costing from '../inventory/CostCalculation.tsx?raw';
import reports from '../inventory/StockReport.tsx?raw';

describe('inventory embedded workspace toolbar contract', () => {
  it('keeps create actions in the parent toolbar and uses explicit child events', () => {
    expect(inventoryWorkspace).toContain('open-inventory-transfer');
    expect(inventoryWorkspace).toContain('open-inventory-stock-count');
    expect(inventoryWorkspace).toContain('refresh-inventory-receipt');
    expect(inventoryWorkspace).toContain('refresh-inventory-issue');
    expect(inventoryWorkspace).toMatch(/<InventoryReceipts\s+embedded(?:\s|>)/);
    expect(inventoryWorkspace).toMatch(/<InventoryIssues\s+embedded(?:\s|>)/);
    expect(inventoryWorkspace).toMatch(/<CostCalculation\s+embedded(?:\s|>)/);
    expect(inventoryWorkspace).toMatch(/<StockReport\s+embedded(?:\s|>)/);
    expect(stockCounts).toContain("window.addEventListener('open-inventory-stock-count'");
    expect(transfers).toContain("window.addEventListener('open-inventory-transfer'");
    expect(stockCounts).not.toContain('{embedded && createToolbar}');
    expect(transfers).not.toContain('{embedded && createToolbar}');
    expect(receipts).toContain('embedded={embedded}');
    expect(issues).toContain('embedded={embedded}');
    expect(costing).toContain('embedded={embedded}');
    expect(reports).toContain('embedded={embedded}');
    expect(costing).toContain('{!embedded && <PageToolbar');
    expect(reports).toContain('{!embedded && <PageToolbar');
  });
});
