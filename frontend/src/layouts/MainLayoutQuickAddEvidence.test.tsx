import { describe, expect, it } from 'vitest';
import source from './MainLayout.tsx?raw';

describe('main layout quick-add navigation boundary', () => {
  it('connects quick-add to existing internal routes without local data creation', () => {
    expect(source).toContain('const quickAddItems = [');
    expect(source).toContain("navigate('/cash/receipts?action=create')");
    expect(source).toContain("navigate('/purchase/invoices?action=create')");
    expect(source).toContain("navigate('/sales/invoices?action=create')");
    expect(source).toContain("navigate('/inventory/items')");
    expect(source).toContain("navigate('/master/customers')");
    expect(source).toContain("navigate('/master/suppliers')");
    expect(source).toContain("trigger={['click']}");
    expect(source).toContain('app-breadcrumb');
    expect(source).not.toContain('PILOT NỘI BỘ');
    expect(source).toContain("authUser?.name?.trim() || authUser?.email?.trim() || 'Kế toán viên'");
    expect(source).not.toContain('ENTERPRISE SYSTEM');
    expect(source).not.toContain('>ABC<');
    expect(source).not.toContain('>Kế toán trưởng<');
    expect(source).not.toContain('Dữ liệu năm 2026');
    expect(source).not.toContain('count={3}');
    expect(source).not.toContain('Tạo dữ liệu mẫu');
  });
});
