import { describe, expect, it } from 'vitest';
import salesReturns from './SalesReturns.tsx?raw';
import salesDiscounts from './SalesDiscounts.tsx?raw';
import purchaseReturns from '../purchase/PurchaseReturns.tsx?raw';
import purchaseDiscounts from '../purchase/PurchaseDiscounts.tsx?raw';
import purchaseReturnModal from '../purchase/modals/PurchaseReturnModal.tsx?raw';
import purchaseDiscountModal from '../purchase/modals/PurchaseDiscountModal.tsx?raw';
import salesReturnModal from './modals/SalesReturnModal.tsx?raw';
import salesDiscountModal from './modals/SalesDiscountModal.tsx?raw';

describe('sales/purchase return-discount action evidence boundary', () => {
    it('requires persisted action or delete evidence before reporting success', () => {
        for (const source of [salesReturns, salesDiscounts, purchaseReturns, purchaseDiscounts]) {
            expect(source).toContain('const persistedActionId =');
            expect(source).toContain('const hasDeleteEvidence =');
            expect(source).toContain('if (persistedActionId(response) === undefined || persistedActionId(response) === null)');
            expect(source).toContain('if (!hasDeleteEvidence(response))');
        }
        expect(purchaseReturnModal).toContain('Máy chủ không trả về chứng từ trả lại đã lưu');
        expect(purchaseDiscountModal).toContain('Máy chủ không trả về chứng từ giảm giá đã lưu');
        expect(salesReturnModal).toContain('Máy chủ không trả về chứng từ hàng bán trả lại đã lưu');
        expect(salesDiscountModal).toContain('Máy chủ không trả về chứng từ giảm giá hàng bán đã lưu');
    });
});
