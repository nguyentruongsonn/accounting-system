import { describe, expect, it } from 'vitest';
import { canEditPurchaseDimensions, shortPolicyHash } from './purchaseDimensionAssignment';

describe('purchase invoice dimension assignment presentation', () => {
  it('never makes a posted invoice editable from the browser', () => {
    expect(canEditPurchaseDimensions({
      isPosted: true,
      context: { status: 'available' },
      contextLoading: false,
      contextError: false,
      saveForbidden: false,
    })).toBe(false);
  });

  it('requires a server-provided available selection context and update access', () => {
    expect(canEditPurchaseDimensions({
      isPosted: false,
      context: { status: 'unavailable' },
      contextLoading: false,
      contextError: false,
      saveForbidden: false,
    })).toBe(false);
    expect(canEditPurchaseDimensions({
      isPosted: false,
      context: { status: 'available' },
      contextLoading: false,
      contextError: false,
      saveForbidden: true,
    })).toBe(false);
  });

  it('does not expose a full policy hash as a UI substitute for policy evidence', () => {
    expect(shortPolicyHash('0123456789abcdef')).toBe('0123456789ab…');
  });
});
