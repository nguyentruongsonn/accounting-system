import { describe, expect, it } from 'vitest';
import source from './PurchaseWorkspace.tsx?raw';

describe('purchase report navigation copy', () => {
  it('does not advertise report views that the purchase report tab does not implement', () => {
    expect(source).toContain('Tổng hợp mua hàng');
    expect(source).not.toContain('Nhật ký mua hàng');
    expect(source).not.toContain('Báo cáo công nợ phải trả theo NCC');
    expect(source).not.toContain('Báo cáo tiến độ đơn mua hàng');
  });
});
