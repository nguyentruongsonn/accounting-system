import { describe, expect, it } from 'vitest';
import { nextCloseAction, readinessBlockers, readinessCheckSummary, reconciliationBlockers, readinessReasonLabel, signoffLabel, workbenchStatus } from './periodCloseWorkbenchHelpers';

describe('period close workbench presentation', () => {
  it('does not treat a shadow reconciliation run as close permission', () => {
    const readiness = { period: { id: 10, is_closed: false }, close_gate: { enforcement: 'off', close_permitted_by_this_endpoint: false, reason: 'Policy has not been approved.' } };
    expect(workbenchStatus(readiness)).toBe('blocked');
    expect(nextCloseAction(readiness)).toContain('Chưa được phép');
  });

  it('shows every non-passing reconciliation result as a blocker', () => {
    const blockers = reconciliationBlockers([
      { check_code: 'gl.balance', domain: 'general_ledger', status: 'passed' },
      { check_code: 'bank.tieout', domain: 'bank', status: 'not_available' },
      { check_code: 'inventory.tieout', domain: 'inventory', status: 'warning' },
    ]);
    expect(blockers.map((item) => item.domain)).toEqual(['bank', 'inventory']);
  });

  it('keeps unknown data fail-closed', () => {
    expect(workbenchStatus(undefined)).toBe('unknown');
    expect(nextCloseAction(undefined)).toContain('Tải lại');
  });

  it('uses the latest immutable server evaluation when it is available', () => {
    const readiness = {
      period: { id: 10, is_closed: false },
      close_gate: { enforcement: 'off', close_permitted_by_this_endpoint: false },
      latest_evaluation: {
        id: 44,
        uuid: 'readiness-44',
        period_id: 10,
        status: 'blocked',
        eligible_to_close: false,
        snapshot: { checks: [{ code: 'INVENTORY.VALUATION_CURRENT', status: 'fail' }] },
      },
    } as const;

    expect(workbenchStatus(readiness)).toBe('blocked');
    expect(nextCloseAction(readiness)).toContain('INVENTORY.VALUATION_CURRENT');
  });

  it('recognizes the server evidence-only approval state without claiming close authority', () => {
    expect(signoffLabel('approved_evidence_only')).toContain('chưa tự động khóa kỳ');
    const readiness = { period: { id: 10, is_closed: false }, close_gate: { close_permitted_by_this_endpoint: true } };
    expect(nextCloseAction(readiness, [{ uuid: 'pkg-1', state: 'approved_evidence_only', readiness_snapshot_id: 1 }])).toContain('Gửi yêu cầu đóng kỳ');
  });

  it('surfaces controlled readiness blockers and nested domain details', () => {
    const checks = [
      { code: 'AP_SUBLEDGER_GL', status: 'not_available', details: { reason_code: 'missing_same_cutoff_run' } },
      { code: 'RECONCILIATION.NAMED_DOMAINS_SAME_CUTOFF', status: 'fail', details: { checks: [{ code: 'INVENTORY_SUBLEDGER_GL', status: 'pass' }, { code: 'FIXED_ASSET_GL', status: 'not_available' }] } },
      { code: 'RECONCILIATION.COMPLETED_EVIDENCE', status: 'pass' },
    ] as const;

    expect(readinessBlockers(checks).map((check) => check.code)).toEqual(['AP_SUBLEDGER_GL', 'RECONCILIATION.NAMED_DOMAINS_SAME_CUTOFF']);
    expect(readinessCheckSummary(checks[0])).toBe('missing_same_cutoff_run');
    expect(readinessCheckSummary(checks[1])).toContain('FIXED_ASSET_GL');
  });

  it('translates valuation evidence blockers into operator guidance', () => {
    expect(readinessReasonLabel('inventory_valuation_unverified_cost')).toContain('giá xuất kho chưa chốt');
    expect(readinessCheckSummary({ code: 'INVENTORY.VALUATION_CURRENT', status: 'fail', details: { reason: 'inventory_valuation_unverified_cost' } })).toContain('bổ sung tồn/lô giá');
  });
});
