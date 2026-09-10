import { describe, expect, it } from 'vitest';
import source from './PurchaseContracts.tsx?raw';

describe('purchase contract display/action evidence boundary', () => {
    it('does not invent payment progress or report unpersisted actions as success', () => {
        expect(source).toContain('data: contracts = []');
        expect(source).not.toContain('} catch (e) {\n                return [];');
        expect(source).toContain('response?.data?.id === undefined || response?.data?.id === null');
        expect(source).toContain('Máy chủ không trả về hợp đồng mua hàng đã lưu');
        expect(source).toContain("render: (p: any[]) => p == null ? '—' : p.length");
        expect(source).toContain("r.fulfilled_amount == null");
        expect(source).not.toContain('Chức năng in hợp đồng mua hàng chưa khả dụng');
        expect(source).not.toContain('p?.length || 2');
    });
});
