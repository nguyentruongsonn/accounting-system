import { describe, expect, it } from 'vitest';
import { resolveActiveMenuKey, resolveBreadcrumb } from './MainLayout';

describe('main navigation active selection', () => {
  it.each([
    ['/cash/receipts', '/cash'],
    ['/cash/payments', '/cash'],
    ['/purchase/invoices', 'purchase-group'],
    ['/sales/invoices', 'sales-group'],
    ['/inventory/receipts', '/inventory'],
    ['/fixed-assets', '/fixed-assets'],
    ['/gl/journal-entries', '/gl'],
    ['/reports/balance-sheet', '/reports'],
    ['/master/customers', '/master/customers'],
  ])('maps %s to %s', (pathname, expected) => {
    expect(resolveActiveMenuKey(pathname)).toBe(expected);
  });
});

describe('inventory deep-link breadcrumbs', () => {
  it.each([
    ['/inventory/transfers', 'Phiếu chuyển kho'],
    ['/inventory/stock-counts', 'Biên bản kiểm kê kho'],
  ])('labels %s as %s', (pathname, expectedPage) => {
    expect(resolveBreadcrumb(pathname).page).toBe(expectedPage);
  });
});
