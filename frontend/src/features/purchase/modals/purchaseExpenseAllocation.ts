export type PurchaseExpenseAllocationMethod = 'value' | 'quantity' | 'manual';

export interface PurchaseExpenseAllocationSelection {
    target_purchase_invoice_id: number;
    target_purchase_invoice_line_id: number;
    allocated_amount: string;
    allocation_method: PurchaseExpenseAllocationMethod;
    voucher_number?: string;
    voucher_date?: string;
    supplier_name?: string;
    item_code?: string;
    item_name?: string;
    remaining_allocatable_amount?: string;
}

export interface PurchaseExpenseCandidateLine {
    id: number;
    item_id?: number;
    item_code?: string;
    item_name?: string;
    quantity?: string | number;
    stock_value?: string | number;
    allocated_amount?: string | number;
    remaining_allocatable_amount?: string | number;
}

export interface PurchaseExpenseCandidate {
    id: number;
    voucher_number?: string;
    voucher_date?: string;
    supplier_id?: number;
    supplier_name?: string;
    sub_total?: string | number;
    already_allocated_amount?: string | number;
    remaining_allocatable_amount?: string | number;
    lines: PurchaseExpenseCandidateLine[];
}

export const toAmountString = (value: string | number | null | undefined): string => {
    const amount = Number(value);
    return Number.isFinite(amount) ? amount.toFixed(2) : '0.00';
};

export const flattenPurchaseExpenseCandidates = (
    candidates: PurchaseExpenseCandidate[],
): Array<PurchaseExpenseCandidateLine & {
    target_purchase_invoice_id: number;
    voucher_number?: string;
    voucher_date?: string;
    supplier_name?: string;
    allocated_amount: string;
}> => candidates.flatMap((candidate) => candidate.lines.map((line) => ({
    ...line,
    target_purchase_invoice_id: candidate.id,
    voucher_number: candidate.voucher_number,
    voucher_date: candidate.voucher_date,
    supplier_name: candidate.supplier_name,
    allocated_amount: toAmountString(line.remaining_allocatable_amount),
})));
