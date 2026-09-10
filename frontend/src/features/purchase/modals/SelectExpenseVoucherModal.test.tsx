import { describe, expect, it } from 'vitest';
import source from './SelectExpenseVoucherModal.tsx?raw';

describe('SelectExpenseVoucherModal availability contract', () => {
    it('does not expose sample expense vouchers or select fake records', () => {
        expect(source).not.toContain('<Alert');
        expect(source).toContain('dataSource={[]}');
        expect(source).toMatch(/<Button type="primary" disabled[\s\S]*>\s*Đồng ý/);
        expect(source).not.toContain('SAMPLE_EXPENSE_VOUCHERS');
        expect(source).not.toContain('onSelect(selected)');
    });
});
