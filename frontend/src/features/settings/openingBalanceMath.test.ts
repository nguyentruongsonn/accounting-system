import { describe, expect, it } from 'vitest';
import { multiplyOpeningBalanceValue } from './openingBalanceMath';

describe('opening balance inventory arithmetic', () => {
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
