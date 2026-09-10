import { describe, expect, it } from 'vitest';
import source from './CashAdvanceSettlements.tsx?raw';

describe('cash advance settlement workflow evidence', () => {
    it('keeps settlement calculations server-backed and voucher links unavailable', () => {
        expect(source).toContain("api.get('/cash/advance-settlements')");
        expect(source).toContain("api.put(`/cash/advance-settlements/${editingId}`");
        expect(source).toContain('Sửa Đề nghị quyết toán tạm ứng');
        expect(source).toContain('/submit');
        expect(source).not.toContain('cash-inline-note');
        expect(source).toContain("entity.status !== 'submitted'");
        expect(source).toContain("if (value === null || value === undefined || value === '') return '—';");
        expect(source).toContain("if (value === 'draft') return 'Nháp';");
        expect(source).not.toContain('QTTU00001');
        expect(source).not.toContain('NV00001');
    });

    it('keeps the previous rows visible and exposes a retry when the list request fails', () => {
        expect(source).toContain('loadError');
        expect(source).toContain('Thử lại');
        expect(source).not.toContain('catch (error) { setRecords([]);');
    });
});
