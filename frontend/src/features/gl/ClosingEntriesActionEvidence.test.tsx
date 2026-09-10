import { describe, expect, it } from 'vitest';
import source from './ClosingEntries.tsx?raw';

describe('period-close action evidence boundary', () => {
    it('does not report success when the execute response lacks a persisted journal identity', () => {
        expect(source).toContain('const persistedId = data && (data.id ?? data.uuid);');
        expect(source).toContain('Máy chủ không trả về chứng từ kết chuyển đã lưu');
        expect(source).toContain('if (!persistedId)');
    });

    it('keeps malformed or legacy preview data unavailable until the server publishes execution capability', () => {
        expect(source).toContain('function parseClosingPreview');
        expect(source).toContain('const executionAvailable = previewData?.execution_available === true;');
        expect(source).toContain('Chưa có evidence preview từ máy chủ.');
        expect(source).toContain('Bản xem dòng kết chuyển');
        expect(source).not.toContain('previewData?.total_revenue || 0');
        expect(source).not.toContain('previewData?.total_expense || 0');
        expect(source).not.toContain('TK 911 $\\rightarrow$ 4212');
    });

    it('keeps the internal preview free of statutory or certification wording', () => {
        expect(source).not.toContain('chứng nhận TT99');
        expect(source).not.toContain('Appendix IV');
        expect(source).not.toContain('CHƯA CERTIFY');
        expect(source).not.toContain('chưa certifying');
    });
});
