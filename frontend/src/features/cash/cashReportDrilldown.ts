import type { CashReportSourceType } from './cashReportContract';

type CashReportSourceRow = { source_type?: unknown; source_id?: unknown };

/** Returns a route only when the server supplied a validated cash-voucher identity. */
export function cashReportSourceRoute(row: CashReportSourceRow): string | null {
  const sourceType = row.source_type;
  const sourceId = row.source_id;
  if ((sourceType !== 'receipt' && sourceType !== 'payment') || typeof sourceId !== 'number' || !Number.isSafeInteger(sourceId) || sourceId <= 0) {
    return null;
  }
  const routeByType: Record<CashReportSourceType, string> = { receipt: '/cash/receipts', payment: '/cash/payments' };
  return `${routeByType[sourceType]}?source_id=${sourceId}`;
}
