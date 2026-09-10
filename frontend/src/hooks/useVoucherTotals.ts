import { useMemo } from 'react';

export interface VoucherLineCalculable {
    amount?: number | null;
    quantity?: number;
    unit_price?: number;
    discount_amount?: number;
    tax_amount?: number;
    import_tax_amount?: number;
    purchase_expense?: number;
}

export interface VoucherTotalsOptions {
    includeTax?: boolean;
    includeImportTax?: boolean;
    includePurchaseExpense?: boolean;
}

export const useVoucherTotals = <T extends VoucherLineCalculable>(
    lines: T[] = [],
    options: VoucherTotalsOptions = { includeTax: true }
) => {
    return useMemo(() => {
        const safeLines = Array.isArray(lines) ? lines.filter((l): l is T => Boolean(l)) : [];
        const lineCount = safeLines.length;
        const totalQuantity = safeLines.reduce((acc, l) => acc + (Number(l?.quantity) || 0), 0);
        const subTotal = safeLines.reduce((acc, l) => acc + (Number(l?.amount) || ((Number(l?.quantity) || 0) * (Number(l?.unit_price) || 0))), 0);
        const totalDiscount = safeLines.reduce((acc, l) => acc + (Number(l?.discount_amount) || 0), 0);
        const totalTax = safeLines.reduce((acc, l) => acc + (Number(l?.tax_amount) || 0), 0);
        const totalImportTax = safeLines.reduce((acc, l) => acc + (Number(l?.import_tax_amount) || 0), 0);
        const totalPurchaseExpense = safeLines.reduce((acc, l) => acc + (Number(l?.purchase_expense) || 0), 0);

        let grandTotal = subTotal - totalDiscount;
        if (options.includeTax) {
            grandTotal += totalTax;
        }
        if (options.includeImportTax) {
            grandTotal += totalImportTax;
        }

        return {
            lineCount,
            totalQuantity,
            subTotal,
            totalDiscount,
            totalTax,
            totalImportTax,
            totalPurchaseExpense,
            grandTotal
        };
    }, [lines, options.includeTax, options.includeImportTax, options.includePurchaseExpense]);
};

export default useVoucherTotals;
