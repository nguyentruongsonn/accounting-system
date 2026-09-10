import { describe, expect, it } from 'vitest';
import source from './AllocateDiscountModal.tsx?raw';

describe('purchase discount allocation source evidence', () => {
    it('does not invent source line identity, quantity, amount or discount totals', () => {
        expect(source).not.toContain('VT0000${idx + 1}');
        expect(source).not.toContain("item_name: it.item_name || 'Hàng hóa'");
        expect(source).not.toContain('Number(it.quantity) || 1');
        expect(source).not.toContain('Number(it.amount) || 0');
        expect(source).not.toContain('currentTotalDiscount > 0 ? currentTotalDiscount : 100000');
        expect(source).toContain('setTotalDiscount(currentTotalDiscount);');
        expect(source).toContain("text ?? '—'");
    });
});
