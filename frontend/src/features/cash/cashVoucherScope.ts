/**
 * Payment workflows that are retained only for reading legacy data.
 *
 * Payroll and insurance are deliberately outside the two-role internal cash
 * release. Keeping the rule in one place prevents a stale server catalogue
 * from re-introducing those choices into a create form.
 */
export function isOutOfScopeCashPaymentType(value: unknown): boolean {
    const normalized = String(value ?? '').trim().toLowerCase();
    return normalized.includes('trả lương') || normalized.includes('bảo hiểm');
}

export function isLegacyOutOfScopeCashPaymentRecord(record: {
    voucher_type?: unknown;
    reason?: unknown;
    lines?: Array<{ operation?: unknown }> | null;
} | null | undefined): boolean {
    return isOutOfScopeCashPaymentType(record?.voucher_type)
        || isOutOfScopeCashPaymentType(record?.reason)
        || (record?.lines ?? []).some((line) => isOutOfScopeCashPaymentType(line?.operation));
}
