import { describe, expect, it } from 'vitest';
import source from './SalesWorkspace.tsx?raw';

describe('sales report navigation copy', () => {
  it('keeps report links aligned with implemented report tabs', () => {
    expect(source).toContain('Tổng hợp doanh thu bán hàng');
    expect(source).toContain('Báo cáo công nợ phải thu theo KH');
    expect(source).not.toContain('Nhật ký bán hàng');
    expect(source).not.toContain('Báo cáo tiến độ đơn đặt hàng');
  });
});
