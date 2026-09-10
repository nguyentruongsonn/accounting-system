import { describe, expect, it } from 'vitest';
import { canSubmitSalesApproval, isApprovalDecisionAvailableInSalesUi, salesApprovalPresentation } from './salesInvoiceApproval';

describe('sales invoice approval presentation', () => {
  const request = { id: 1, status: 'approved', requested_by: 9, separation_of_duties_required: true, evidence: { snapshot_hash: 'abc', is_current: true }, steps: [] } as const;
  it('never exposes a decision in a sales maker screen', () => expect(isApprovalDecisionAvailableInSalesUi()).toBe(false));
  it('explains maker-checker self-post restriction', () => expect(salesApprovalPresentation(request, 9).detail).toContain('không thể tự ghi sổ'));
  it('blocks duplicate current pending submission', () => expect(canSubmitSalesApproval({ isPosted: false, canRequest: true, requestLoading: false, requestError: false, hasCurrentPending: true })).toBe(false));
  it('marks stale approval evidence unsafe', () => expect(salesApprovalPresentation({ ...request, evidence: { snapshot_hash: 'old', is_current: false } }, 2).label).toBe('Chứng cứ đã cũ'));
});
