import { describe, expect, it } from 'vitest';
import source from './ExcelImportModal.tsx?raw';

describe('ExcelImportModal availability contract', () => {
    it('does not report local Excel import as completed', () => {
        expect(source).toContain('Nhập Excel chưa khả dụng');
        expect(source).toContain('Backend chưa công bố API nhập Excel');
        expect(source).toMatch(/Tải tệp mẫu chuẩn MISA[\s\S]*disabled/);
        expect(source).toMatch(/Tiếp tục[\s\S]*disabled/);
    });
});
