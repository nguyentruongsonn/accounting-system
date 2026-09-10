import { describe, expect, it } from 'vitest';
import source from './CashPaymentRequests.tsx?raw';

describe('cash payment request draft workflow evidence', () => {
    it('uses the server draft API and requires persisted evidence', () => {
        expect(source).toContain("api.get('/cash/payment-requests')");
        expect(source).toContain("api.post('/cash/payment-requests'");
        expect(source).toContain("api.put(`/cash/payment-requests/${editingId}`");
        expect(source).toContain('Sửa Đề nghị chi tiền');
        expect(source).toContain('Server did not return a persisted payment request id');
        expect(source).toContain("entity.status !== 'submitted'");
        expect(source).toContain('/submit');
        expect(source).not.toContain('Ban Giám đốc');
        expect(source).not.toContain('ĐNCT00001');
    });
});
