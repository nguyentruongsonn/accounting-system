import { describe, expect, it } from 'vitest';
import { canRequestCapture, inputBoundaryStatusLabel, ledgerLabel } from './apArInputBoundaryHelpers';

describe('AP/AR input-boundary display helpers', () => {
  it('labels server ledgers and unavailable status without inventing a result', () => {
    expect(ledgerLabel('ap')).toBe('Phải trả (AP)');
    expect(ledgerLabel('ar')).toBe('Phải thu (AR)');
    expect(inputBoundaryStatusLabel('not_available')).toBe('Chưa khả dụng');
  });

  it('does not treat view permission as capture permission', () => {
    expect(canRequestCapture(['apar.reconciliations.view'])).toBe(false);
    expect(canRequestCapture(['apar.reconciliations.capture'])).toBe(true);
  });
});
