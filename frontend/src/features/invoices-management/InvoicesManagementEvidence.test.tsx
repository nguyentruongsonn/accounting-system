import { describe, expect, it } from 'vitest';
import source from './InvoicesManagement.tsx?raw';

describe('e-invoice management evidence boundary', () => {
    it('does not render sample issued invoices or unbound issuance actions', () => {
        expect(source).not.toContain('Công ty Cổ phần ABC');
        expect(source).not.toContain('Công ty TNHH XYZ');
        expect(source).not.toContain('Phát hành</Button>');
        expect(source).not.toContain('Hủy hóa đơn</Button>');
        expect(source).toContain('NOT IMPLEMENTED');
        expect(source).toContain('Không hiển thị dữ liệu mẫu');
    });
});
