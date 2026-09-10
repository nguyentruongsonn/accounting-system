import { useState, useCallback } from 'react';

export interface BaseVoucherLine {
    key?: string;
    id?: number;
    quantity?: number;
    unit_price?: number;
    amount?: number;
    tax_rate?: number;
    tax_amount?: number;
    cogs_unit_price?: number;
    cogs_amount?: number;
    [key: string]: any;
}

export interface UseVoucherLinesOptions<T extends BaseVoucherLine> {
    initialLines?: T[];
    defaultLineFactory?: () => T;
    autoCalculate?: boolean;
}

export function useVoucherLines<T extends BaseVoucherLine>(options: UseVoucherLinesOptions<T> = {}) {
    const { initialLines = [], defaultLineFactory, autoCalculate = true } = options;

    const [lines, setLines] = useState<T[]>(() => {
        if (Array.isArray(initialLines) && initialLines.length > 0) {
            return initialLines.map((l, idx) => ({
                ...l,
                key: l.key || `line-${Date.now()}-${idx}`
            }));
        }
        return defaultLineFactory ? [defaultLineFactory()] : [];
    });

    const calculateLineAmounts = useCallback((line: Partial<T>): Partial<T> => {
        if (!autoCalculate) return line;

        const qty = Number(line.quantity) || 0;
        const price = Number(line.unit_price) || 0;
        const taxRate = typeof line.tax_rate === 'number' ? line.tax_rate : 0;
        const cogsPrice = Number(line.cogs_unit_price) || 0;

        const amount = qty * price;
        const taxAmount = (amount * taxRate) / 100;
        const cogsAmount = qty * cogsPrice;

        return {
            ...line,
            amount: line.amount !== undefined && line.amount !== null && !('unit_price' in line || 'quantity' in line) 
                ? line.amount 
                : amount,
            tax_amount: line.tax_amount !== undefined && !('tax_rate' in line || 'amount' in line || 'unit_price' in line || 'quantity' in line)
                ? line.tax_amount
                : taxAmount,
            cogs_amount: line.cogs_amount !== undefined && !('cogs_unit_price' in line || 'quantity' in line)
                ? line.cogs_amount
                : cogsAmount
        };
    }, [autoCalculate]);

    const addLine = useCallback((defaultValues?: Partial<T>) => {
        setLines(prev => {
            const safePrev = Array.isArray(prev) ? prev : [];
            const baseLine = defaultLineFactory ? defaultLineFactory() : ({} as T);
            const newLine = {
                ...baseLine,
                ...defaultValues,
                key: `line-${Date.now()}-${safePrev.length + 1}`
            } as T;
            return [...safePrev, calculateLineAmounts(newLine) as T];
        });
    }, [defaultLineFactory, calculateLineAmounts]);

    const updateLine = useCallback((indexOrKey: number | string, updates: Partial<T>) => {
        setLines(prev => {
            const safePrev = Array.isArray(prev) ? prev : [];
            return safePrev.map((line, idx) => {
                const isMatch = typeof indexOrKey === 'number' ? idx === indexOrKey : line.key === indexOrKey;
                if (!isMatch) return line;

                const merged = { ...line, ...updates };
                return calculateLineAmounts(merged) as T;
            });
        });
    }, [calculateLineAmounts]);

    const removeLine = useCallback((indexOrKey: number | string) => {
        setLines(prev => {
            const safePrev = Array.isArray(prev) ? prev : [];
            return safePrev.filter((line, idx) => {
                return typeof indexOrKey === 'number' ? idx !== indexOrKey : line.key !== indexOrKey;
            });
        });
    }, []);

    const removeAllLines = useCallback(() => {
        setLines([]);
    }, []);

    const duplicateLine = useCallback((indexOrKey: number | string) => {
        setLines(prev => {
            const safePrev = Array.isArray(prev) ? prev : [];
            const targetLine = typeof indexOrKey === 'number' 
                ? safePrev[indexOrKey] 
                : safePrev.find(l => l.key === indexOrKey);
            
            if (!targetLine) return safePrev;

            const cloned = {
                ...targetLine,
                key: `line-${Date.now()}-${safePrev.length + 1}`,
                id: undefined
            };
            return [...safePrev, cloned];
        });
    }, []);

    const resetLines = useCallback((newLines: T[]) => {
        const safeLines = Array.isArray(newLines) ? newLines : [];
        setLines(safeLines.map((l, idx) => ({
            ...l,
            key: l.key || `line-${Date.now()}-${idx}`
        })));
    }, []);

    return {
        lines,
        setLines,
        addLine,
        updateLine,
        removeLine,
        removeAllLines,
        duplicateLine,
        resetLines
    };
}

export default useVoucherLines;
