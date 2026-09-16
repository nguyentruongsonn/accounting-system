import { isVoidedCashVoucher } from './cashVoucherStatus';

function readCashAmount(value: unknown): number | null {
    if (typeof value === 'number' && Number.isFinite(value)) return value;
    if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) return Number(value);
    return null;
}

export function sumPostedCashTransactionAmounts(rows: any[], loading: boolean, error: boolean): number | null {
    if (loading || error) return null;
    const postedRows = rows.filter((row) => row?.is_posted === true && !isVoidedCashVoucher(row));
    if (postedRows.length === 0) return 0;
    const amounts = postedRows.map((row) => readCashAmount(row?.total_amount));
    if (amounts.some((amount) => amount === null)) return null;
    return (amounts as number[]).reduce((sum, amount) => sum + amount, 0);
}
