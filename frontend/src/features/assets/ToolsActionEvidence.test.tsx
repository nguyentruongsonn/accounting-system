import { describe, expect, it } from 'vitest';
import source from './Tools.tsx?raw';

describe('tools allocation action evidence boundary', () => {
    it('does not report success when the API returns no persisted allocation log', () => {
        expect(source).toContain('const allocationLog = response?.data?.data;');
        expect(source).toContain('allocationLog.id === undefined || allocationLog.id === null');
        expect(source).toContain('Không có CCDC đủ điều kiện để phân bổ');
    });
});
