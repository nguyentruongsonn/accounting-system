import { describe, expect, it } from 'vitest';
import { approvalRequestPresentation, canSubmitPurchaseApproval, isApprovalDecisionAvailableInPurchaseUi } from './purchaseInvoiceApproval';

describe('purchase invoice approval presentation', () => {
  const request = {
    id: 1, status: 'approved', requested_by: 9, separation_of_duties_required: true,
    evidence: { snapshot_hash: 'abc', is_current: true }, steps: [],
  } as const;

  it('never exposes an approval decision from the purchase maker screen', () => {
    expect(isApprovalDecisionAvailableInPurchaseUi()).toBe(false);
  });

  it('explains that the requester cannot self-post under maker-checker', () => {
    expect(approvalRequestPresentation(request, 9).detail).toContain('không thể tự ghi sổ');
  });

  it('does not allow duplicate submission while a current request is pending', () => {
    expect(canSubmitPurchaseApproval({ isPosted: false, canRequest: true, requestLoading: false, requestError: false, hasCurrentPending: true })).toBe(false);
  });

  it('makes stale evidence visibly unsafe', () => {
    expect(approvalRequestPresentation({ ...request, evidence: { snapshot_hash: 'old', is_current: false } }, 2).label).toBe('Chứng cứ đã cũ');
  });
});
