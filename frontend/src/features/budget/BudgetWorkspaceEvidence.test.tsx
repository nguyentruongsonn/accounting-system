import { describe, expect, it } from 'vitest';
import source from './BudgetWorkspace.tsx?raw';

describe('budget workspace capability boundary', () => {
    it('does not expose static budget rows or an unbound save action', () => {
        expect(source).toContain('Lập dự toán chưa khả dụng');
        expect(source).toContain('Không hiển thị số liệu mẫu');
        expect(source).not.toContain('defaultValue="2024"');
        expect(source).not.toContain('expected_revenue: 0');
        expect(source).not.toContain('Lưu dự toán');
    });
});
