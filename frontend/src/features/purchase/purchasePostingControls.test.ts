import { describe, expect, it } from 'vitest';
import { canRequestPosting, dimensionControlPresentation, postingControlErrorPresentation } from './purchasePostingControls';

describe('purchase posting control presentation', () => {
  it('does not infer dimensions from commercial master data when readiness is unavailable', () => {
    const presentation = dimensionControlPresentation({
      status: 'unavailable',
      reason: 'No explicit AccountingDimensionValue selection has been saved for this purchase invoice.',
    });

    expect(presentation.title).toContain('chưa sẵn sàng');
    expect(presentation.action).toContain('không suy diễn');
    expect(canRequestPosting({ status: 'unavailable' })).toBe(false);
  });

  it('keeps posting unavailable when the preflight response is missing or malformed', () => {
    expect(canRequestPosting()).toBe(false);
    expect(canRequestPosting({})).toBe(false);
    expect(canRequestPosting({ status: 'unavailable' })).toBe(false);
  });

  it('keeps the server as final authority even after a ready preflight', () => {
    const presentation = dimensionControlPresentation({ status: 'ready', posting_date: '2026-08-22' });

    expect(presentation.action).toContain('kiểm tra lại');
    expect(canRequestPosting({ status: 'ready' })).toBe(true);
  });

  it('turns a posting approval denial into an actionable business message', () => {
    const presentation = postingControlErrorPresentation({ response: { data: { error: 'Purchase invoice posting requires exactly one completed approval request.' } } });

    expect(presentation.kind).toBe('approval');
    expect(presentation.action).toContain('phê duyệt');
  });
});
