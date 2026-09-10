import { describe, expect, it } from 'vitest';
import source from './InventoryWorkspace.tsx?raw';

describe('inventory report navigation copy', () => {
  it('lists only the implemented stock report and does not expose simulation labels', () => {
    expect(source).toContain('Tổng hợp nhập - xuất - tồn');
    expect(source).not.toContain('(bản vận hành)');
    expect(source).not.toContain('Bảng kê chi tiết nhập - xuất - tồn');
    expect(source).not.toContain('Báo cáo kiểm kê và chênh lệch kho');
  });
});
