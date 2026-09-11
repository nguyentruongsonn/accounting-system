import { describe, expect, it } from 'vitest';
import { multiplyOpeningBalanceValue, summarizeOpeningBalanceLines } from './openingBalanceMath';

describe('opening balance inventory arithmetic', () => {
  it('summarizes debit and credit using the stable money pattern', () => {
    expect(summarizeOpeningBalanceLines([
      { debit_amount: '100.10', credit_amount: '0.00' },
      { debit_amount: '0.00', credit_amount: '40.10' },
    ])).toEqual({ totalDebit: '100.10', totalCredit: '40.10', balanced: false });
  });
  it('multiplies decimal quantity and unit cost without passing through Number', () => {
    expect(multiplyOpeningBalanceValue('1234567890123.4567', '9876543210.1234')).toBe('12193263112635198009755.24');
  });

  it('rounds the product to two money decimals using half up', () => {
    expect(multiplyOpeningBalanceValue('0.0001', '0.0050')).toBe('0.00');
    expect(multiplyOpeningBalanceValue('0.0001', '0.0150')).toBe('0.00');
    expect(multiplyOpeningBalanceValue('1.2345', '2.3456')).toBe('2.90');
  });

  it('keeps an editable row safe while either value is empty', () => {
    expect(multiplyOpeningBalanceValue('', '10')).toBe('0.00');
    expect(multiplyOpeningBalanceValue('10', undefined)).toBe('0.00');
  });
});
