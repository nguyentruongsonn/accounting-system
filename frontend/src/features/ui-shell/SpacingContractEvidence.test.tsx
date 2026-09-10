import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { UI_SPACING } from '../../components/layout/spacing';
import PageShell from '../../components/layout/PageShell';
import PageToolbar from '../../components/layout/PageToolbar';
import cashPaymentRequests from '../cash/CashPaymentRequests.tsx?raw';
import cashTransactions from '../cash/CashTransactions.tsx?raw';
import purchaseOrders from '../purchase/PurchaseOrders.tsx?raw';
import purchaseWorkspace from '../purchase/PurchaseWorkspace.tsx?raw';

describe('shared spacing contract', () => {
  it('defines one spacing scale and applies it to page, toolbar, and table surfaces', () => {
    expect(UI_SPACING.control).toBe('8px');
    expect(UI_SPACING.group).toBe('12px');
    expect(UI_SPACING.section).toBe('16px');
    expect(UI_SPACING.page).toBe('20px');
    expect(UI_SPACING.shell).toBe('24px');
  });

  it('keeps cash requests, cash transactions, purchasing, and process pages on the same shell contract', () => {
    expect(cashPaymentRequests).toContain("from '../../components/layout/PageShell'");
    expect(cashPaymentRequests).toContain("from '../../components/layout/PageToolbar'");
    expect(cashPaymentRequests).toContain("from '../../components/layout/DataTableSurface'");
    expect(cashPaymentRequests).toContain('<PageShell');
    expect(cashPaymentRequests).toContain('<PageToolbar');
    expect(cashPaymentRequests).toContain('<DataTableSurface');

    for (const source of [cashTransactions, purchaseOrders, purchaseWorkspace]) {
      expect(source).toContain('<PageShell');
      expect(source).toContain('<PageToolbar');
    }
  });

  it('keeps the page primitive edge-to-edge while its toolbar owns the compact control group', () => {
    render(
      <PageShell toolbar={<PageToolbar actions={<button type="button">Thêm</button>} />}>
        <div className="ui-table-empty">Không có dữ liệu</div>
      </PageShell>,
    );

    expect(screen.getByTestId('ui-page-shell')).toHaveAttribute('data-surface', 'page');
    expect(screen.getByText('Thêm').closest('.ui-page-toolbar')).toHaveAttribute('data-layout', 'toolbar');
    expect(screen.getByText('Không có dữ liệu')).toHaveClass('ui-table-empty');
  });
});
