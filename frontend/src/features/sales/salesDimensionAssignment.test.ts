import { describe, expect, it } from 'vitest';
import { canEditSalesDimensions, shortSalesPolicyHash } from './salesDimensionAssignment';

describe('sales invoice dimension assignment presentation', () => {
  it('never makes a posted invoice editable from the browser', () => {
    expect(canEditSalesDimensions({
      isPosted: true,
      context: { status: 'available' },
      contextLoading: false,
      contextError: false,
      saveForbidden: false,
    })).toBe(false);
  });

  it('requires a server-provided available context and update access', () => {
    expect(canEditSalesDimensions({
      isPosted: false,
      context: { status: 'unavailable' },
      contextLoading: false,
      contextError: false,
      saveForbidden: false,
    })).toBe(false);
    expect(canEditSalesDimensions({
      isPosted: false,
      context: { status: 'available' },
      contextLoading: false,
      contextError: false,
      saveForbidden: true,
    })).toBe(false);
  });

  it('only renders a short policy hash, not policy contents or a full hash', () => {
    expect(shortSalesPolicyHash('0123456789abcdef')).toBe('0123456789ab…');
  });
});
