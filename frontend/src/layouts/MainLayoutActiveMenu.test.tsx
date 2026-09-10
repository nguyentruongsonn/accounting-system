import { describe, expect, it } from 'vitest';
import { resolveActiveMenuKey } from './MainLayout';

describe('main layout active menu mapping', () => {
  it.each([
    ['/cash/receipts', '/cash'],
    ['/purchase?tab=4', 'purchase-group'],
    ['/sales?tab=invoice', 'sales-group'],
    ['/inventory/items', '/inventory'],
    ['/master/customers', '/master/customers'],
    ['/master/accounts', '/master/accounts'],
    ['/settings/company', undefined],
    ['/settings/account-catalogues', '/master/accounts'],
    ['/invoices-management', '/invoices-management'],
  ])('maps %s to %s', (pathname, expected) => {
    expect(resolveActiveMenuKey(pathname)).toBe(expected);
  });

  it('does not select a removed sidebar item for the legacy company settings route', () => {
    expect(resolveActiveMenuKey('/settings/company')).toBeUndefined();
  });
});
