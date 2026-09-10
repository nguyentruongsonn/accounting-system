import { describe, expect, it } from 'vitest';
import source from './PurchaseDashboardTab.tsx?raw';

describe('legacy purchase dashboard truthfulness boundary', () => {
    it('does not ship sample suppliers, amounts, or KPI values', () => {
        expect(source).not.toContain('<Alert');
        expect(source).toContain('misa-page-layout-gray');
        expect(source).not.toContain('Công ty Cổ phần Thép Hòa Phát');
        expect(source).not.toContain('3.820.000.000');
        expect(source).not.toContain('1450000000');
        expect(source).not.toContain('890000000');
    });
});
