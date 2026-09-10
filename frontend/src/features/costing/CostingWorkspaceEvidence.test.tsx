import { describe, expect, it } from 'vitest';
import source from './CostingWorkspace.tsx?raw';

describe('costing workspace evidence boundary', () => {
    it('does not present static costing rows or unbound calculation controls', () => {
        expect(source).toContain('Tổng hợp chi phí theo mẫu cũ chưa khả dụng');
        expect(source).toContain('Mở kỳ tính giá thành');
        expect(source).toContain('to="/costing/allocations"');
        expect(source).not.toContain('Phân xưởng 1');
        expect(source).not.toContain('Sản phẩm A');
        expect(source).not.toContain('dataSource={[]}');
        expect(source).not.toContain('>Tổng hợp chi phí<');
    });
});
