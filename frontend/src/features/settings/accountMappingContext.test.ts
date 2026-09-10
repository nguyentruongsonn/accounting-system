import { describe, expect, it } from 'vitest';
import { mappingContextFields } from './accountMappingContext';

describe('account mapping context templates', () => {
  it('matches cash and bank voucher context used by the posting gate', () => {
    expect(mappingContextFields('cash_bank.voucher', 'debit')).toEqual([
      'voucher_family',
      'voucher_type',
      'voucher_reason',
      'source_account_code',
    ]);
  });

  it('uses role-specific purchase invoice fields', () => {
    expect(mappingContextFields('purchase.invoice', 'settlement_credit')).toEqual([
      'entry',
      'payment_method',
      'payment_status',
      'source_account_code',
    ]);
    expect(mappingContextFields('purchase.invoice', 'purchase_debit')).toEqual([
      'entry',
      'voucher_type',
      'source_account_code',
    ]);
  });

  it('includes line identity for sales adjustment roles', () => {
    expect(mappingContextFields('sales.return', 'revenue_reduction')).toEqual([
      'voucher_family',
      'voucher_type',
      'entry',
      'payment_method',
      'item_id',
      'warehouse_id',
      'source_account_code',
    ]);
  });

  it('keeps period close mappings context-free', () => {
    expect(mappingContextFields('period_close.result', 'result_clearing')).toEqual([]);
  });
});
