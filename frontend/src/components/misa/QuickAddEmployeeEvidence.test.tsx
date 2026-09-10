import { describe, expect, it } from 'vitest';
import source from './QuickAddEmployeeModal.tsx?raw';

describe('quick employee master-data evidence boundary', () => {
    it('does not invent employee/department codes and requires a persisted response', () => {
        expect(source).toContain("code: values.code?.trim() || undefined");
        expect(source).toContain('const persistedEmployee = res?.data?.data ?? res?.data;');
        expect(source).toContain('Máy chủ không trả về nhân viên đã lưu');
        expect(source).not.toContain('Math.random');
        expect(source).not.toContain('NV${String');
        expect(source).not.toContain('PB${String');
    });
});
