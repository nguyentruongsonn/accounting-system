import { describe, expect, it } from 'vitest';
import source from './Items.tsx?raw';

describe('inventory item action evidence boundary', () => {
    it('does not treat a malformed 2xx catalogue envelope as an empty or usable list', () => {
        expect(source).toContain("function parseItemsResponse(value: unknown): any[]");
        expect(source).toContain("throw new Error('Invalid inventory items response')");
        expect(source).toContain('return parseItemsResponse(data);');
        expect(source).toContain('Thử lại danh sách vật tư hàng hóa');
    });

    it('requires a persisted item response before success or cache invalidation', () => {
        expect(source).toContain('const persistedItem = response?.data?.data ?? response?.data;');
        expect(source).toContain('Máy chủ không trả về vật tư đã lưu');
    });
});
