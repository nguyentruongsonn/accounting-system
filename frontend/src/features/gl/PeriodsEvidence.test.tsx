import { describe, expect, it } from 'vitest';
import source from './Periods.tsx?raw';

describe('accounting-period response boundary', () => {
    it('does not render a malformed 2xx period catalogue as a valid empty list', () => {
        expect(source).toContain('Invalid accounting-periods response');
        expect(source).toContain('return parsePeriodsResponse(data);');
        expect(source).not.toContain('(data?.data || [])');
    });

    it('exposes reopen only through the admin permission and requires a persisted reason', () => {
        expect(source).toContain("permissions?.includes('gl.periods.close')");
        expect(source).toContain('Mở lại kỳ');
        expect(source).toContain('Lý do mở lại kỳ');
        expect(source).toContain("api.post(`/gl/periods/${period.id}/reopen`, { reason:");
        expect(source).toContain("invalidateQueries({ queryKey: ['periods'] })");
    });
});
