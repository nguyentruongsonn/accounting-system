import { describe, expect, it } from 'vitest';
import routesSource from './index.tsx?raw';

describe('inventory workflow deep links', () => {
    it('exposes direct routes for transfer and stock-count screens', () => {
        expect(routesSource).toContain('<Route path="inventory/transfers" element={<InventoryTransfers />} />');
        expect(routesSource).toContain('<Route path="inventory/stock-counts" element={<InventoryStockCounts />} />');
    });
});
