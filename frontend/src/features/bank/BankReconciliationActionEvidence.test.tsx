import { describe, expect, it } from 'vitest';
import source from './BankStatementReconciliation.tsx?raw';

describe('bank reconciliation action evidence boundary', () => {
    it('requires a persisted match or exception event before success messaging', () => {
        expect(source).toContain('function hasPersistedEvidence(response: any)');
        expect(source).toContain('Máy chủ không trả về sự kiện đề xuất đã lưu');
        expect(source).toContain('Máy chủ không trả về quyết định đối chiếu đã lưu');
        expect(source).toContain('Máy chủ không trả về sự kiện ngoại lệ đã lưu');
    });
});
