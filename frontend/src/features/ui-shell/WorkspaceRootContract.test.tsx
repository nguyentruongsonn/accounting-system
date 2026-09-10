import { describe, expect, it } from 'vitest';
import inventorySource from '../inventory/InventoryWorkspace.tsx?raw';
import generalLedgerSource from '../gl/GLWorkspace.tsx?raw';
import fixedAssetSource from '../fixed-asset/FixedAssetWorkspace.tsx?raw';
import payrollSource from '../payroll/PayrollWorkspace.tsx?raw';
import reportsSource from '../reports/ReportsWorkspace.tsx?raw';
import cashSource from '../cash/CashWorkspace.tsx?raw';
import purchaseSource from '../purchase/PurchaseWorkspace.tsx?raw';
import salesSource from '../sales/SalesWorkspace.tsx?raw';

const workspaceSources = [
  inventorySource,
  generalLedgerSource,
  fixedAssetSource,
  payrollSource,
  reportsSource,
  cashSource,
  purchaseSource,
  salesSource,
];

describe('canonical workspace root contract', () => {
  it('uses a shared page header and sibling toolbar around the module workspace', () => {
    for (const source of workspaceSources) {
      expect(source).toContain("from '../../components/layout/PageShell'");
      expect(source).toContain("from '../../components/layout/PageHeader'");
      expect(source).toContain('title={<PageHeader');
      if (source === reportsSource || source === cashSource) {
        expect(source).not.toContain("from '../../components/layout/PageToolbar'");
        expect(source).not.toMatch(/toolbar=\{\s*<PageToolbar(?:\s|\/?>)/);
      } else {
        expect(source).toContain("from '../../components/layout/PageToolbar'");
        expect(source).toMatch(/toolbar=\{\s*<PageToolbar(?:\s|\/?>)/);
      }
      expect(source).toMatch(/<(?:MisaWorkspaceLayout|ModuleWorkspace)\b|data-ui="workspace"/);
      expect(source).not.toMatch(/<PageShell[^>]*>\s*<PageToolbar/);
      expect(source).not.toContain('misa-workspace-card');
    }
  });

  it('keeps durable tab keys in each workspace while changing only the root contract', () => {
    expect(inventorySource).toContain("key: 'tab-receipts'");
    expect(generalLedgerSource).toContain("key: 'tab-journals'");
    expect(fixedAssetSource).toContain("key: 'tab-depreciation'");
    expect(payrollSource).toContain("key: 'tab-vouchers'");
    expect(reportsSource).toContain('REPORT_ROUTES');
    expect(cashSource).toContain("key: 'tab-transactions'");
    expect(purchaseSource).toContain("key: 'tab-invoices'");
    expect(salesSource).toContain("key: 'tab-invoices'");
  });
});
