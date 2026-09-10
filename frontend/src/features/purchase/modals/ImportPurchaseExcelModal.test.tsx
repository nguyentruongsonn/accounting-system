import { describe, expect, it } from 'vitest';
import source from './ImportPurchaseExcelModal.tsx?raw';

describe('ImportPurchaseExcelModal availability contract', () => {
    it('does not report local purchase import as completed', () => {
        expect(source).not.toContain('<Alert');
        expect(source).toContain('Thực hiện nhập khẩu');
        expect(source).toMatch(/disabled>[\s\S]*Thực hiện nhập khẩu/);
        expect(source).not.toContain('setTimeout');
        expect(source).not.toContain('message.success');
        expect(source).not.toContain('invalidateQueries');
    });
});
