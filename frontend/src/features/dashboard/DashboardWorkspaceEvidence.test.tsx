import { describe, expect, it } from 'vitest';
import source from './Dashboard.tsx?raw';

describe('dashboard internal workspace navigation boundary', () => {
  it('links only to implemented internal workflows and keeps unsupported scope explicit', () => {
    expect(source).toContain("route: '/cash'");
    expect(source).toContain("route: '/purchase'");
    expect(source).toContain("route: '/sales'");
    expect(source).toContain("route: '/inventory'");
    expect(source).toContain("route: '/gl'");
    expect(source).toContain("route: '/settings/account-catalogues'");
    expect(source).toContain("route: '/settings/onboarding'");
    expect(source).toContain('Bàn làm việc nội bộ');
    expect(source).toContain('Phạm vi đang khóa');
    expect(source).not.toContain('Quy trình Bàn làm việc đang được cập nhật');
    expect(source).not.toContain('Tạo dữ liệu mẫu');
  });
});
