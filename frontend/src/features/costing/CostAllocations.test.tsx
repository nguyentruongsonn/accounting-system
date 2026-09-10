import { describe, expect, it } from 'vitest';
import source from './CostAllocations.tsx?raw';

describe('CostAllocations API projection contract', () => {
    it('uses allocation records returned by the costing API instead of sample KPI values', () => {
        expect(source).toContain("api.post('/costing/allocate'");
        expect(source).toContain('Array.isArray(apiData)');
        expect(source).toContain('? apiData');
        expect(source).toContain('direct_material_cost');
        expect(source).toContain('manufacturing_overhead');
        expect(source).not.toContain('700000000');
        expect(source).not.toContain('50000000');
        expect(source).not.toContain('650000000');
        expect(source).not.toContain('hạch toán Nợ 154, 155 thành công');
        expect(source).toContain("if (value === null || value === undefined || value === '') return '—';");
        expect(source).toContain("value == null ? '—' : `#${value}`");
    });
});
