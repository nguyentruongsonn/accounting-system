import { describe, expect, it } from 'vitest';
import source from './PurchaseContracts.tsx?raw';

describe('PurchaseContracts workflow contract', () => {
    it('does not advertise unsupported conversion or print operations', () => {
        expect(source).not.toContain("key: 'create_invoice'");
        expect(source).not.toContain('Lập CT mua hàng');
        expect(source).not.toContain('Backend chưa công bố API lập chứng từ mua hàng từ hợp đồng');
        expect(source).not.toContain("key: 'print'");
        expect(source).not.toContain('Chức năng in hợp đồng mua hàng chưa khả dụng');
        expect(source).not.toContain('setTimeout(');
        expect(source).not.toContain('onExport=');
        expect(source).not.toContain('Đã chuyển sang Chứng từ mua hàng');
    });
});
