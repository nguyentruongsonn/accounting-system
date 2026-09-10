export type ApArLedger = 'ap' | 'ar';

export interface ApArPartyRollforward {
  party_type: 'supplier' | 'customer' | string;
  party_id: number;
  opening_balance: string;
  invoice_balance: string;
  settlement_reduction: string;
  settlement_reversal: string;
  ending_balance: string;
}

export interface ApArCalculatedAmounts {
  subledger_balance?: string;
  gl_control_balance?: string;
  difference?: string;
  party_rollforward?: ApArPartyRollforward[];
  [key: string]: unknown;
}

export interface ApArInputBoundaryRun {
  uuid: string;
  ledger: ApArLedger;
  as_of_date: string | null;
  status: string;
  algorithm_version: string;
  input_cutoff_at: string | null;
  input_boundary: Record<string, unknown> | null;
  snapshot_hash: string | null;
  amounts: ApArCalculatedAmounts | null;
  amounts_calculated: boolean;
  tie_out_calculated: boolean;
  close_authority: boolean;
  control?: Record<string, unknown> | null;
  limitation: string;
  exceptions?: Array<{ code: string; severity: string; reason: string }> | null;
}

export function ledgerLabel(ledger: ApArLedger): string {
  return ledger === 'ap' ? 'Phải trả (AP)' : 'Phải thu (AR)';
}

export function inputBoundaryStatusLabel(status: string): string {
  if (status === 'not_available') return 'Chưa khả dụng';
  if (status === 'approved' || status === 'reconciled') return 'Đã đối chiếu';
  if (status === 'blocked') return 'Bị chặn — cần xử lý';
  if (status === 'captured') return 'Đã capture evidence';
  return status || 'Máy chủ chưa công bố';
}

/** A UI guard, not an authorization decision. The server still controls it. */
export function canRequestCapture(permissions: readonly string[] | undefined): boolean {
  return permissions?.includes('apar.reconciliations.capture') ?? false;
}

export function safeJson(value: unknown): string {
  return JSON.stringify(value ?? {}, null, 2);
}
