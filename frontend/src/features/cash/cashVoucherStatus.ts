export interface CashVoucherStatusRecord {
    status?: unknown;
    is_posted?: unknown;
}

const VOIDED_STATUSES = new Set(['voided', 'void', 'cancelled', 'canceled']);

export function isVoidedCashVoucher(record: CashVoucherStatusRecord | null | undefined): boolean {
    const status = typeof record?.status === 'string' ? record.status.toLowerCase() : '';
    return VOIDED_STATUSES.has(status);
}

export function cashVoucherStatusLabel(record: CashVoucherStatusRecord | null | undefined): string {
    if (isVoidedCashVoucher(record)) return 'Đã hủy';
    if (record?.is_posted === true) return 'Đã ghi sổ';
    if (record?.is_posted === false) return 'Bản nháp';
    return '—';
}

export function cashVoucherStatusTone(record: CashVoucherStatusRecord | null | undefined): string {
    if (isVoidedCashVoucher(record)) return 'misa-apple-pill-red';
    if (record?.is_posted === true) return 'misa-apple-pill-green';
    if (record?.is_posted === false) return 'misa-apple-pill-orange';
    return 'misa-apple-pill-blue';
}
