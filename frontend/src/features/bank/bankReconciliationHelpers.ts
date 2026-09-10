export type ReconciliationStatus = 'unmatched' | 'proposed' | 'confirmed' | 'exception_open';

/** Formats the immutable decimal representation without converting it to JS Number. */
export function formatExactAmount(raw: string | null, scale: number | null, currency = 'VND'): string {
  if (raw === null || raw === undefined || scale === null || scale === undefined || !/^\d+(?:\.\d+)?$/.test(raw) || scale < 0) return '—';
  const [wholePart, fractionPart = ''] = raw.split('.');
  const whole = wholePart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const fraction = scale > 0 ? `,${fractionPart.padEnd(scale, '0').slice(0, scale)}` : '';
  return `${whole}${fraction} ${currency}`;
}

export function reconciliationStatusLabel(status: ReconciliationStatus): string {
  return ({ unmatched: 'Chưa ghép', proposed: 'Chờ kiểm tra', confirmed: 'Đã xác nhận', exception_open: 'Ngoại lệ đang mở' })[status];
}

export function statusColor(status: ReconciliationStatus): string {
  return ({ unmatched: 'default', proposed: 'processing', confirmed: 'success', exception_open: 'error' })[status];
}
