import { describe, expect, it } from 'vitest';
import bankAccountsSource from '../bank/BankAccounts.tsx?raw';
import bankReconciliationSource from '../bank/BankStatementReconciliation.tsx?raw';
import systemOptionsSource from '../settings/SystemOptions.tsx?raw';
import roleManagementSource from '../settings/RoleManagement.tsx?raw';
import periodLockSource from '../gl/PeriodLock.tsx?raw';
import closingEntriesSource from '../gl/ClosingEntries.tsx?raw';
import invoicesManagementSource from '../invoices-management/InvoicesManagement.tsx?raw';

const pageSources = [
  bankAccountsSource,
  bankReconciliationSource,
  systemOptionsSource,
  roleManagementSource,
  periodLockSource,
  closingEntriesSource,
  invoicesManagementSource,
];

describe('simple pages use the canonical visual shell', () => {
  it('uses the shared page shell and header primitives', () => {
    for (const source of pageSources) {
      expect(source).toContain("from '../../components/layout/PageShell'");
      expect(source).toContain("from '../../components/layout/PageHeader'");
      expect(source).toContain('<PageShell');
      expect(source).toContain('<PageHeader');
      expect(source).not.toContain('misa-page-container');
      expect(source).not.toContain('misa-list-toolbar');
    }
    for (const source of [bankAccountsSource, bankReconciliationSource, systemOptionsSource, roleManagementSource, closingEntriesSource, invoicesManagementSource]) {
      expect(source).toContain("from '../../components/layout/PageToolbar'");
      expect(source).toContain('<PageToolbar');
    }
    for (const source of [bankReconciliationSource]) {
      expect(source).not.toContain('<PageToolbar />');
    }
    expect(periodLockSource).not.toContain("from '../../components/layout/PageToolbar'");
    expect(periodLockSource).not.toContain('<PageToolbar');
  });

  it('uses the shared table surface for table-bearing pages', () => {
    for (const source of [bankAccountsSource, bankReconciliationSource, closingEntriesSource]) {
      expect(source).toContain("from '../../components/layout/DataTableSurface'");
      expect(source).toContain('<DataTableSurface');
    }
  });
});
