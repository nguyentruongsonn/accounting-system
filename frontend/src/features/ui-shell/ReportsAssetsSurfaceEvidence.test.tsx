import { describe, expect, it } from 'vitest';

import fixedAssetsSource from '../assets/FixedAssets.tsx?raw';
import toolsSource from '../assets/Tools.tsx?raw';
import stockReportSource from '../inventory/StockReport.tsx?raw';
import costCalculationSource from '../inventory/CostCalculation.tsx?raw';
import apAgingSource from '../purchase/APAgingReport.tsx?raw';
import purchaseReportsSource from '../purchase/PurchaseReports.tsx?raw';
import arAgingSource from '../sales/ARAgingReport.tsx?raw';
import salesReportsSource from '../sales/SalesReports.tsx?raw';
import balanceSheetSource from '../reports/BalanceSheetReport.tsx?raw';
import incomeStatementSource from '../reports/IncomeStatementReport.tsx?raw';
import trialBalanceSource from '../reports/TrialBalanceReport.tsx?raw';

const sharedPageImports = (source: string) => {
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

describe('canonical presentation surfaces for report and asset pages', () => {
  it('uses the shared page/list contract for asset and inventory screens', () => {
    for (const source of [fixedAssetsSource, toolsSource, stockReportSource, costCalculationSource]) {
      sharedPageImports(source);
    }
  });

  it('uses the shared page/list contract for purchasing and sales reports', () => {
    for (const source of [apAgingSource, purchaseReportsSource, arAgingSource, salesReportsSource]) {
      sharedPageImports(source);
    }
    expect(purchaseReportsSource).toContain('toolbar={!embedded && <PageToolbar');
    expect(salesReportsSource).toContain('toolbar={!embedded ? reportToolbar : undefined}');
    expect(salesReportsSource).toContain('{embedded && reportToolbar}');
  });

  it('uses the shared page/list contract for statutory report tables', () => {
    for (const source of [balanceSheetSource, incomeStatementSource, trialBalanceSource]) {
      sharedPageImports(source);
    }
  });
});
