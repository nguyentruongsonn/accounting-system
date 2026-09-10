import { describe, expect, it } from 'vitest';
import source from './CashAudit.tsx?raw';

describe('cash audit identifier evidence boundary', () => {
    it('does not invent a local audit number while server allocation is unavailable', () => {
        expect(source).not.toContain('Math.random()');
        expect(source).not.toContain("|| 'KKQ00001'");
        expect(source).toContain("api.get('/cash-inventories/next-code')");
        expect(source).toContain("|| '—'");
        expect(source).not.toContain('Nguyễn Văn A - Giám đốc');
        expect(source).not.toContain('Trần Thị B - Thủ quỹ');
        expect(source).not.toContain('Lê Văn C - Kế toán tiền mặt');
        expect(source).toContain('Máy chủ chưa trả về bảng kiểm kê quỹ đã lưu.');
        expect(source).toContain('persisted.id === undefined || persisted.id === null');
        expect(source).toContain("api.get('/cash/book-balance'");
        expect(source).toContain("placeholder=\"Chọn tài khoản chi tiết từ máy chủ\"");
        expect(source).toContain("status === 'available'");
        expect(source).not.toContain("cashAccountCode || '1111'");
        expect(source).toContain("if (!Array.isArray(data))");
        expect(source).toContain('auditListQuery.isError');
        expect(source).toContain('Không thể tải danh sách kiểm kê quỹ');
        expect(source).not.toContain('const { data: auditList = [] }');
    });
});
