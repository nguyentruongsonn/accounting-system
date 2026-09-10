import { describe, expect, it } from 'vitest';
import { addDecimalQuantity } from './inventoryReportMath';

describe('inventory report quantity arithmetic', () => {
  it('adds large quantities without JavaScript floating-point coercion', () => {
    expect(addDecimalQuantity('9007199254740.1234', '9007199254740.8766')).toBe('18014398509481.0000');
  });

  it('preserves signed quantities and the four-decimal quantity scale', () => {
    expect(addDecimalQuantity('10.1250', '-2.0001', '0.0001')).toBe('8.1250');
  });

  it('treats empty optional report cells as zero', () => {
    expect(addDecimalQuantity(undefined, null, '')).toBe('0.0000');
  });
});
