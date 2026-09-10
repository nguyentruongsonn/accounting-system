import { describe, expect, it } from 'vitest';
import dashboardSource from '../dashboard/Dashboard.tsx?raw';
import companySettingsSource from '../settings/CompanySettings.tsx?raw';
import bankBoundarySource from '../bank/BankCompatibilityBoundary.tsx?raw';
import itemsSource from '../inventory/Items.tsx?raw';
import periodsSource from '../gl/Periods.tsx?raw';
import salesReturnsSource from '../sales/SalesReturns.tsx?raw';
import purchaseDiscountsSource from '../purchase/PurchaseDiscounts.tsx?raw';
import salesDiscountsSource from '../sales/SalesDiscounts.tsx?raw';
import purchaseReceiveInvoicesSource from '../purchase/PurchaseReceiveInvoices.tsx?raw';
import salesInvoicesSource from '../sales/SalesInvoices.tsx?raw';
import inventoryReceiptsSource from '../inventory/InventoryReceipts.tsx?raw';
import inventoryIssuesSource from '../inventory/InventoryIssues.tsx?raw';
import journalEntriesSource from '../gl/JournalEntries.tsx?raw';
import payrollListSource from '../payroll/PayrollList.tsx?raw';
import apArBoundarySource from '../reports/ApArReconciliationInputBoundary.tsx?raw';
import statutoryReadinessSource from '../reports/StatutoryFinancialStatementReadiness.tsx?raw';
import approvedMappingsSource from '../settings/ApprovedAccountMappings.tsx?raw';
import onboardingReadinessSource from '../settings/OnboardingReadiness.tsx?raw';
import fixedAssetDepreciationsSource from '../fixed-asset/FixedAssetDepreciations.tsx?raw';
import fixedAssetRevaluationsSource from '../fixed-asset/FixedAssetRevaluations.tsx?raw';
import fixedAssetDisposalsSource from '../fixed-asset/FixedAssetDisposals.tsx?raw';
import payrollVouchersSource from '../payroll/PayrollVouchers.tsx?raw';
import generalJournalsSource from '../gl/GeneralJournals.tsx?raw';
import bankTransactionsSource from '../bank/BankTransactions.tsx?raw';
import costingWorkspaceSource from '../costing/CostingWorkspace.tsx?raw';
import periodCloseWorkbenchSource from '../gl/PeriodCloseWorkbench.tsx?raw';
import accountingAuditTrailSource from '../settings/AccountingAuditTrailExplorer.tsx?raw';
import accountingAccountCataloguesSource from '../settings/AccountingAccountCatalogues.tsx?raw';
import cashWorkspaceSource from '../cash/CashWorkspace.tsx?raw';

const canonicalPageContract = (source: string) => {
  expect(source).toContain("from '../../components/layout/PageShell'");
  expect(source).toContain("from '../../components/layout/PageHeader'");
  expect(source).toContain("from '../../components/layout/PageToolbar'");
  expect(source).toContain('<PageShell');
  expect(source).toContain('<PageHeader');
  expect(source).toContain('<PageToolbar');
};

describe('canonical page structure for migrated outliers', () => {
  it('migrates dashboard to the shared page shell without changing tab state handlers', () => {
    expect(dashboardSource).toContain("from '../../components/layout/PageShell'");
    expect(dashboardSource).toContain("from '../../components/layout/PageHeader'");
    expect(dashboardSource).toContain('<PageShell');
    expect(dashboardSource).toContain('<PageHeader');
    expect(dashboardSource).not.toContain("from '../../components/layout/PageToolbar'");
    expect(dashboardSource).not.toContain('<PageToolbar');
    expect(dashboardSource).toContain('activeKey={activeTab}');
    expect(dashboardSource).toContain('onChange={setActiveTab}');
    expect(dashboardSource).not.toContain('<div className="apple-dashboard">');
  });

  it('migrates company settings to the shared page shell while retaining form submit behavior', () => {
    canonicalPageContract(companySettingsSource);
    expect(companySettingsSource).toContain('onFinish={(values) => mutation.mutate(values)}');
    expect(companySettingsSource).toContain('disabled={!company?.id || mutation.isPending}');
    expect(companySettingsSource).not.toContain('apple-settings-title');
  });

  it('migrates the bank compatibility boundary without exposing mutation behavior', () => {
    canonicalPageContract(bankBoundarySource);
    expect(bankBoundarySource).toContain('không tải dữ liệu và không cho tạo/sửa/ghi sổ');
    expect(bankBoundarySource).not.toContain('api.post');
    expect(bankBoundarySource).not.toContain('misa-page-container">');
  });

  it('migrates inventory items and accounting periods to the shared list contract', () => {
    for (const source of [itemsSource]) {
      expect(source).toContain("from '../../components/layout/PageShell'");
      expect(source).toContain("from '../../components/layout/PageToolbar'");
      expect(source).toContain("from '../../components/layout/DataTableSurface'");
      expect(source).toContain('<PageShell');
      expect(source).toContain('<PageToolbar');
      expect(source).toContain('<DataTableSurface');
      expect(source).not.toContain('misa-page-container');
      expect(source).not.toContain('apple-ledger-page');
    }
    expect(periodsSource).toContain("from '../../components/layout/PageShell'");
    expect(periodsSource).toContain("from '../../components/layout/PageHeader'");
    expect(periodsSource).toContain("from '../../components/layout/PageToolbar'");
    expect(periodsSource).toContain("from '../../components/layout/DataTableSurface'");
    expect(periodsSource).toContain('<PageShell');
    expect(periodsSource).toContain('<PageToolbar');
    expect(periodsSource).toContain('<DataTableSurface');
  });

  it('migrates sales returns and purchase discounts to the shared list contract', () => {
    for (const source of [salesReturnsSource, purchaseDiscountsSource]) {
      expect(source).toContain("from '../../components/layout/PageShell'");
      expect(source).toContain("from '../../components/layout/PageToolbar'");
      expect(source).toContain("from '../../components/layout/DataTableSurface'");
      expect(source).toContain('<PageShell');
      expect(source).toContain('<PageToolbar');
      expect(source).toContain('<DataTableSurface');
      expect(source).not.toContain('misa-page-container');
      expect(source).not.toContain('misa-table-wrapper');
    }
  });

  it('migrates sales discounts and purchase receive invoices to the shared list contract', () => {
    for (const source of [salesDiscountsSource, purchaseReceiveInvoicesSource]) {
      expect(source).toContain("from '../../components/layout/PageShell'");
      expect(source).toContain("from '../../components/layout/PageToolbar'");
      expect(source).toContain("from '../../components/layout/DataTableSurface'");
      expect(source).toContain('<PageShell');
      expect(source).toContain('<PageToolbar');
      expect(source).toContain('<DataTableSurface');
      expect(source).not.toContain('misa-page-container');
      expect(source).not.toContain('misa-list-toolbar');
      expect(source).not.toContain('misa-table-wrapper');
    }
  });

  it('keeps the sales invoice page header, toolbar, table, and modal siblings', () => {
    expect(salesInvoicesSource).toContain("from '../../components/layout/PageShell'");
    expect(salesInvoicesSource).toContain("from '../../components/layout/PageToolbar'");
    expect(salesInvoicesSource).toContain("from '../../components/layout/DataTableSurface'");
    expect(salesInvoicesSource).toContain('<PageShell');
    expect(salesInvoicesSource).toContain('<PageHeader');
    expect(salesInvoicesSource).toContain('<PageToolbar');
    expect(salesInvoicesSource).toContain('<DataTableSurface');
    expect(salesInvoicesSource).not.toContain('misa-list-toolbar');
    expect(salesInvoicesSource).not.toContain('misa-table-wrapper');
  });

  it('keeps inventory voucher tables outside the modal siblings on the shared shell', () => {
    for (const source of [inventoryReceiptsSource, inventoryIssuesSource]) {
      expect(source).toContain('<PageShell');
      expect(source).toContain('<PageHeader');
      expect(source).toContain('<PageToolbar');
      expect(source).toContain('<DataTableSurface');
      expect(source).not.toContain('misa-list-toolbar');
      expect(source).not.toContain('misa-table-wrapper');
    }
  });

  it('uses the same shell for journal entries and payroll lists', () => {
    for (const source of [journalEntriesSource, payrollListSource]) {
      expect(source).toContain("from '../../components/layout/PageShell'");
      expect(source).toContain("from '../../components/layout/PageToolbar'");
      expect(source).toContain("from '../../components/layout/DataTableSurface'");
      expect(source).toContain('<PageShell');
      expect(source).toContain('<PageToolbar');
      expect(source).toContain('<DataTableSurface');
      expect(source).not.toContain('misa-page-container');
    }
  });

  it('migrates evidence and settings pages to the shared page contract', () => {
    for (const source of [apArBoundarySource, statutoryReadinessSource, approvedMappingsSource]) {
      canonicalPageContract(source);
      expect(source).not.toContain('misa-page-container');
    }
    for (const source of [apArBoundarySource, statutoryReadinessSource]) {
      expect(source).not.toContain('<PageToolbar />');
    }
    expect(onboardingReadinessSource).toContain("from '../../components/layout/PageShell'");
    expect(onboardingReadinessSource).toContain("from '../../components/layout/PageHeader'");
    expect(onboardingReadinessSource).toContain('<PageShell');
    expect(onboardingReadinessSource).toContain('<PageHeader');
    expect(onboardingReadinessSource).not.toContain("from '../../components/layout/PageToolbar'");
    expect(onboardingReadinessSource).not.toContain('<PageToolbar');
  });

  it('migrates fixed-asset and payroll voucher list roots to the shared table contract', () => {
    for (const source of [fixedAssetDepreciationsSource, fixedAssetRevaluationsSource, fixedAssetDisposalsSource, payrollVouchersSource]) {
      expect(source).toContain("from '../../components/layout/PageShell'");
      expect(source).toContain("from '../../components/layout/PageHeader'");
      expect(source).toContain("from '../../components/layout/PageToolbar'");
      expect(source).toContain("from '../../components/layout/DataTableSurface'");
      expect(source).toContain('<PageShell');
      expect(source).toContain('<PageHeader');
      expect(source).toContain('<PageToolbar');
      expect(source).toContain('<DataTableSurface');
      expect(source).not.toContain('misa-page-container');
    }
  });

  it('migrates general journals and bank transactions to the shared table contract', () => {
    for (const source of [generalJournalsSource]) {
      expect(source).toContain("from '../../components/layout/PageShell'");
      expect(source).toContain("from '../../components/layout/PageHeader'");
      expect(source).toContain("from '../../components/layout/PageToolbar'");
      expect(source).toContain("from '../../components/layout/DataTableSurface'");
      expect(source).toContain('<PageShell');
      expect(source).toContain('<PageHeader');
      expect(source).toContain('<PageToolbar');
      expect(source).toContain('<DataTableSurface');
      expect(source).not.toContain('misa-page-container');
    }
    // BankTransactions owns a real multi-region toolbar in the page body;
    // avoid a second empty PageToolbar slot in the shared shell.
    expect(bankTransactionsSource).toContain("from '../../components/layout/PageShell'");
    expect(bankTransactionsSource).toContain("from '../../components/layout/PageHeader'");
    expect(bankTransactionsSource).not.toContain("from '../../components/layout/PageToolbar'");
    expect(bankTransactionsSource).toContain('<PageShell');
    expect(bankTransactionsSource).toContain('<PageHeader');
    expect(bankTransactionsSource).toContain('<DataTableSurface');
    expect(bankTransactionsSource).not.toContain('misa-page-container');
  });

  it('migrates costing, close-control, and audit evidence roots to the shared page contract', () => {
    for (const source of [costingWorkspaceSource, accountingAuditTrailSource]) {
      canonicalPageContract(source);
      expect(source).not.toContain('misa-page-container');
    }
    expect(periodCloseWorkbenchSource).toContain("from '../../components/layout/PageShell'");
    expect(periodCloseWorkbenchSource).toContain("from '../../components/layout/PageHeader'");
    expect(periodCloseWorkbenchSource).toContain('<PageShell');
    expect(periodCloseWorkbenchSource).toContain('<PageHeader');
    expect(periodCloseWorkbenchSource).not.toContain("from '../../components/layout/PageToolbar'");
    expect(periodCloseWorkbenchSource).not.toContain('<PageToolbar');
  });

  it('does not reserve an empty outer toolbar above the cash workspace', () => {
    expect(cashWorkspaceSource).toContain('<PageShell');
    expect(cashWorkspaceSource).toContain('<CashReports');
    expect(cashWorkspaceSource).not.toContain('<PageToolbar />');
  });

  it('removes empty read-only toolbars while preserving authorized actions', () => {
    for (const source of [dashboardSource, onboardingReadinessSource, accountingAccountCataloguesSource]) {
      expect(source).not.toContain("from '../../components/layout/PageToolbar'");
      expect(source).not.toContain('<PageToolbar />');
    }

    expect(approvedMappingsSource).toContain("from '../../components/layout/PageToolbar'");
    expect(approvedMappingsSource).toContain('toolbar={<PageToolbar filters=');
    expect(approvedMappingsSource).not.toContain('<PageToolbar />');

    expect(accountingAuditTrailSource).toContain("from '../../components/layout/PageToolbar'");
    expect(accountingAuditTrailSource).toContain('toolbar={<PageToolbar actions=');
    expect(accountingAuditTrailSource).not.toContain('<PageToolbar />');
  });
});
