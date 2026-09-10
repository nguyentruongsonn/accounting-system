import { describe, expect, it } from 'vitest';
import customersSource from './Customers.tsx?raw';
import suppliersSource from './Suppliers.tsx?raw';

describe('customer/supplier account evidence boundary', () => {
    it('does not invent a receivable/payable account when the API omits it', () => {
        const source = `${customersSource}\n${suppliersSource}`;

        expect(source).toContain("text || '—'");
        expect(customersSource).toContain('response?.status !== 204');
        expect(suppliersSource).toContain('response?.status !== 204');
        expect(customersSource).toContain('Thử lại danh sách khách hàng');
        expect(suppliersSource).toContain('Thử lại danh sách nhà cung cấp');
        expect(source).not.toContain("text || '131'");
        expect(source).not.toContain("text || '331'");
    });
});
