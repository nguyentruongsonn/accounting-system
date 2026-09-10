import { describe, expect, it } from 'vitest';
import cashAudit from './CashAudit.tsx?raw';
import cashForecast from './CashForecast.tsx?raw';
import cashPaymentRequests from './CashPaymentRequests.tsx?raw';
import cashAdvanceSettlements from './CashAdvanceSettlements.tsx?raw';
import cashTransactions from './CashTransactions.tsx?raw';
import cashPayments from './CashPayments.tsx?raw';
import cashReceipts from './CashReceipts.tsx?raw';
import cashReports from './CashReports.tsx?raw';

const cashListSources = [
  cashAudit,
  cashForecast,
  cashPaymentRequests,
  cashAdvanceSettlements,
  cashTransactions,
  cashPayments,
  cashReceipts,
];

describe('cash module surface contract', () => {
  it('keeps every cash screen on the shared page/list primitives', () => {
    for (const source of cashListSources) {
      expect(source).toContain("from '../../components/layout/PageShell'");
      expect(source).toContain("from '../../components/layout/PageHeader'");
      expect(source).toContain("from '../../components/layout/PageToolbar'");
      expect(source).toContain("from '../../components/layout/DataTableSurface'");
      expect(source).toContain('<PageShell');
      expect(source).toContain('<PageHeader');
      expect(source).toContain('<PageToolbar');
      expect(source).toContain('<DataTableSurface');
      expect(source).not.toContain('cash-list-page');
      expect(source).not.toContain('cash-toolbar');
      expect(source).not.toContain('cash-table-surface');
    }
  });

  it('keeps the report workbench on the dedicated selector/result surfaces', () => {
    expect(cashReports).toContain("from '../../components/layout/PageShell'");
    expect(cashReports).toContain("from '../../components/layout/PageToolbar'");
    expect(cashReports).toContain('<CashReportSelector');
    expect(cashReports).toContain('<CashReportResult');
    expect(cashReports).toContain('<PageToolbar');
    expect(cashReports).not.toContain("from '../../components/layout/PageHeader'");
  });

  it('keeps voucher screens on a canonical toolbar before their table surface', () => {
    for (const source of [cashTransactions, cashPayments, cashReceipts]) {
      expect(source.indexOf('<PageToolbar')).toBeGreaterThan(-1);
      expect(source.indexOf('<DataTableSurface')).toBeGreaterThan(source.indexOf('<PageToolbar'));
    }
  });

  it('places cash voucher dialog bodies in the shared modal frame', () => {
    for (const source of [cashPayments, cashReceipts]) {
      expect(source).toContain("from '../../components/layout/ModalFrame'");
      expect(source).toContain('<ModalFrame');
    }
  });
});
