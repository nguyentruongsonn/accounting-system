export interface BankAccountOption {
  id: number;
  account_number: string;
  bank_name: string;
  [key: string]: unknown;
}

/** Bank-account data must come from the active tenant's API response. */
export const BANK_ACCOUNTS_ENDPOINT = '/bank/accounts';

export function readBankAccountsResponse(payload: unknown): BankAccountOption[] {
  if (Array.isArray(payload)) return payload as BankAccountOption[];
  if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
    return (payload as { data: BankAccountOption[] }).data;
  }
  return [];
}
