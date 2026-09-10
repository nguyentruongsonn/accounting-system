import { describe, expect, it } from 'vitest';
import source from './BorrowingContracts.tsx?raw';

describe('borrowing contract unsupported workflow boundary', () => {
    it('does not expose unbound Excel import/export actions as usable buttons', () => {
        expect(source).toContain('Nhập từ Excel (chưa khả dụng)');
        expect(source).toContain('Xuất khẩu (chưa khả dụng)');
        expect(source).toContain('icon={<UploadOutlined />} disabled');
        expect(source).toContain('icon={<ExportOutlined />} disabled');
    });
});
