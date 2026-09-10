import { describe, expect, it } from 'vitest';
import source from './PurchaseReturnModal.tsx?raw';

describe('PurchaseReturnModal API contract', () => {
    it('loads warehouse master data from the registered master route', () => {
        expect(source).toContain("api.get('/master/warehouses')");
        expect(source).not.toContain("api.get('/inventory/warehouses')");
    });
});
