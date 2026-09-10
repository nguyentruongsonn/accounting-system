import { describe, expect, it } from 'vitest';
import source from './OnboardingReadiness.tsx?raw';
import { parseApprovedMappingEvidence, parseRegimeEvidence } from './OnboardingReadiness';
import routes from '../../routes/index.tsx?raw';
import layout from '../../layouts/MainLayout.tsx?raw';

describe('onboarding readiness evidence boundary', () => {
  it('uses server company evidence and keeps open owner contracts unavailable', () => {
    expect(source).toContain("api.get('/master/company')");
    expect(source).toContain("api.get('/gl/periods')");
    expect(source).toContain("api.get('/approved-account-mappings?status=approved");
    expect(source).toContain("api.get('/accounting-policies/profiles')");
    expect(source).toContain('parseRegimeEvidence');
    expect(source).toContain('parseApprovedMappingEvidence');
    expect(source).toContain('parsePeriodsEvidence');
    expect(source).toContain('Đã nhận');
    expect(source).toContain('parseCompanyEvidence');
    expect(source).toContain('Chưa khả dụng');
    expect(source).not.toContain('Không mặc định TT99 hay TT133');
    expect(source).toContain('Không có dữ liệu thay thế');
    expect(source).not.toContain('company_id: 1');
    expect(source).not.toContain('account_code');
    expect(source).not.toContain('non-certifying');
    expect(source).not.toContain('message=');
    expect(source).not.toContain('tip=');
    expect(source).not.toContain('direction=');
  });

  it('shows mapping readiness from server pagination without inventing account pairs', () => {
    expect(source).toContain('approved-account-mappings?status=approved');
    expect(source).toContain('Đã duyệt');
    expect(source).toContain('Chưa có mapping hạch toán được duyệt');
    expect(source).not.toContain('911');
    expect(source).not.toContain('4212');
  });

  it('parses the server pagination total and rejects an invalid envelope', () => {
    expect(parseApprovedMappingEvidence({ data: [{}], meta: { total: 7 } })).toBe(7);
    expect(parseApprovedMappingEvidence({ data: [{}], meta: { total: 1 } })).toBe(1);
    expect(() => parseApprovedMappingEvidence({ meta: { total: 7 } })).toThrow();
  });

  it('parses tenant regime profiles without filling missing values', () => {
    expect(parseRegimeEvidence({ data: [{ id: 2, regime: 'tt99', regime_label: 'Chế độ nội bộ', effective_from: '2026-01-01', effective_to: '2026-12-31' }] })).toEqual([
      { id: 2, regime: 'tt99', regime_label: 'Chế độ nội bộ', effective_from: '2026-01-01', effective_to: '2026-12-31' },
    ]);
    expect(() => parseRegimeEvidence({ data: [{ regime: 'tt99' }] })).toThrow();
  });

  it('exposes the checklist through the settings navigation without creating an action', () => {
    expect(routes).toContain("path=\"settings/onboarding\"");
    expect(routes).toContain("OnboardingReadiness");
    expect(layout).toContain("navigate('/settings/onboarding')");
    expect(source).toContain('to="/settings/company"');
  });
});
