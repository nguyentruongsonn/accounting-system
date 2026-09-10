import { describe, expect, it } from 'vitest';
import quotesSource from './SalesQuotes.tsx?raw';
import ordersSource from './SalesOrders.tsx?raw';

describe('sales numeric value preservation evidence', () => {
    it('preserves explicit zero values when building quote payloads', () => {
        expect(quotesSource).toContain('quantity: line.quantity ?? 1');
        expect(quotesSource).toContain('unit_price: line.unit_price ?? 0');
        expect(quotesSource).toContain('amount: (line.quantity ?? 1) * (line.unit_price ?? 0)');
        expect(quotesSource).not.toContain('quantity: line.quantity || 1');
    });

    it('preserves explicit zero values when building order payloads', () => {
        expect(ordersSource).toContain('due_days: values.due_days ?? 30');
        expect(ordersSource).toContain('quantity: line.quantity ?? 1');
        expect(ordersSource).toContain('unit_price: line.unit_price ?? 0');
        expect(ordersSource).toContain('amount: (line.quantity ?? 1) * (line.unit_price ?? 0)');
        expect(ordersSource).not.toContain('quantity: line.quantity || 1');
    });

    it('uses nullish defaults when recalculating a selected item', () => {
        expect(quotesSource).toContain('const qty = cur[index]?.quantity ?? 1;');
        expect(quotesSource).toContain('const price = item.selling_price ?? item.purchase_price ?? 0;');
        expect(ordersSource).toContain('const qty = cur[index]?.quantity ?? 1;');
        expect(ordersSource).toContain('const price = item.selling_price ?? item.purchase_price ?? 0;');
    });
});
