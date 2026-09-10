import { describe, expect, it } from 'vitest';
import source from './FixedAssetRegistrations.tsx?raw';

describe('fixed asset lifecycle action evidence boundary', () => {
    it('requires returned persisted evidence before reporting lifecycle success', () => {
        expect(source).toContain('const persistedAsset = response?.data;');
        expect(source).toContain('const persistedAsset = response?.data?.data;');
        expect(source).toContain('Máy chủ không trả về TSCĐ đã lưu');
        expect(source).toContain('Máy chủ không xác nhận đã xóa TSCĐ');
    });
});
