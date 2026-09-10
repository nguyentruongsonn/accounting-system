import { describe, expect, it } from 'vitest';
import { BANK_ACCOUNTS_ENDPOINT, readBankAccountsResponse } from './bankAccountData';

describe('bank account source contract', () => {
  it('uses the tenant-scoped bank accounts endpoint', () => {
    expect(BANK_ACCOUNTS_ENDPOINT).toBe('/bank/accounts');
  });

  it('keeps an empty API result empty instead of inventing account IDs', () => {
    expect(readBankAccountsResponse({ data: [] })).toEqual([]);
    expect(readBankAccountsResponse(undefined)).toEqual([]);
  });

  it('supports the API envelope without changing the returned account identity', () => {
    const account = { id: 42, account_number: '123456', bank_name: 'Ngân hàng A' };
    expect(readBankAccountsResponse({ data: [account] })).toEqual([account]);
  });
});
