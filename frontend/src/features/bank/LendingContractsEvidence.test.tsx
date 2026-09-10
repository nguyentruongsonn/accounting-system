import { describe, expect, it } from 'vitest';
import source from './LendingContracts.tsx?raw';

describe('lending contract unavailable-workflow boundary', () => {
    it('does not ship borrower/account samples while the lending workflow is unavailable', () => {
        expect(source).toContain('Workflow cho vay chưa khả dụng');
        expect(source).toContain('options={[]}');
        expect(source).toContain('Chưa có đối tượng vay từ máy chủ');
        expect(source).toContain('Chưa có tài khoản từ máy chủ');
        expect(source).not.toContain('KUCV00001');
        expect(source).not.toContain('KH00001');
        expect(source).not.toContain('Công ty Cổ phần Xây dựng Thăng Long');
        expect(source).not.toContain('defaultValue="1288"');
        expect(source).not.toContain('defaultValue="515"');
        expect(source).not.toContain("message.success('Đã xóa khế ước cho vay')");
        expect(source).not.toContain('<Popconfirm');
    });
});
