import { describe, expect, it } from 'vitest';
import source from './MainLayout.tsx?raw';

describe('main navigation route contract', () => {
  it('places the canonical chart of accounts in the primary sidebar navigation', () => {
    const menuItems = source.slice(source.indexOf('const menuItems = ['), source.indexOf('const userMenu = ['));
    expect(menuItems).toContain("key: '/master/accounts'");
    expect(menuItems).toContain("label: 'Hệ thống tài khoản'");
    expect(menuItems).not.toContain("key: '/settings/account-catalogues'");
    expect(source).not.toContain("key: 'account-catalogues'");
  });

  it('does not expose the obsolete system settings entry in the primary sidebar', () => {
    const menuItems = source.slice(source.indexOf('const menuItems = ['), source.indexOf('const userMenu = ['));
    expect(menuItems).not.toContain("key: '/settings/company'");
    expect(menuItems).not.toContain("label: 'Thiết lập'");
  });

  it('targets core routes without advertising future e-invoice modules', () => {
    expect(source).toContain("key: '/',");
    expect(source).toContain("navigate('/')");
    expect(source).toContain("navigate('/settings/company')");
    expect(source).toContain("navigate('/settings/roles')");
    expect(source).toContain("authUser?.roles?.includes('admin')");
    expect(source).toContain("key: '/master/accounts'");
    expect(source).not.toContain("route: '/einvoices'");
    expect(source).not.toContain("navigate('/dashboard')");
    expect(source).not.toContain("navigate('/settings')");
    // Bank/deposit is intentionally outside the internal SME pilot menu;
    // compatibility routes remain available for existing deep links.
    expect(source).toContain('Tiền gửi/Ngân hàng được ẩn khỏi menu nội bộ');
    expect(source).not.toContain("key: '/bank'");
    expect(source).toContain("{ label: 'Thu tiền khách hàng hàng loạt', route: '/cash/receipts?action=collect-multi' }");
    expect(source).not.toContain('Thu tiền khách hàng hàng loạt (chưa khả dụng)');
    const flyoutConfig = source.slice(source.indexOf('const FLYOUT_CONFIGS'), source.indexOf('const SEARCH_DIRECTORY'));
    expect(flyoutConfig).not.toContain('chưa khả dụng');
    expect(flyoutConfig).not.toContain('disabled: true');
    expect(source).not.toContain('message.info(op.unavailableMessage');
    expect(source).not.toContain('message.info(ut.unavailableMessage');
  });
});
