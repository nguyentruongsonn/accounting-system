import { describe, expect, it } from 'vitest';
import mainLayout from '../../layouts/MainLayout.tsx?raw';
import cashTransactions from '../cash/CashTransactions.tsx?raw';
// @ts-expect-error Node filesystem access is intentional for static visual evidence.
import { readFileSync } from 'node:fs';

const compactStyles = readFileSync('src/styles/ui-compact.css', 'utf8');

describe('approved compact ERP mockup parity', () => {
  it('uses the dark navigation shell and route breadcrumb from the approved mockup', () => {
    expect(mainLayout).toContain('app-breadcrumb');
    expect(compactStyles).toContain('--ui-shell-sidebar: #172335');
    expect(compactStyles).toContain('.app-sidebar');
    expect(compactStyles).toContain('.app-breadcrumb');
    expect(compactStyles).toContain('.misa-quick-add-btn-box');
  });

  it('uses one cash page heading, action row, filter bar, and table surface', () => {
    expect(cashTransactions).toContain('PageShell');
    expect(cashTransactions).toContain('PageHeader');
    expect(cashTransactions).toContain('PageToolbar');
    expect(cashTransactions).toContain('DataTableSurface');
    expect(compactStyles).toContain('.ui-page-shell');
    expect(compactStyles).toContain('.ui-page-toolbar');
  });
});
