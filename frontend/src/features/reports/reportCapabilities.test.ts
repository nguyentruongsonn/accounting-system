import { describe, expect, it } from 'vitest';
import { displayInternalReportLabel, parseReportCapabilityManifest, resolveInternalReportRoute } from './reportCapabilities';

const capability = {
  key: 'trial_balance',
  label: 'Bảng cân đối tài khoản',
  category: 'ledger',
  status: 'available',
  implementation_status: 'operational_draft',
  available: true,
  appendix_iv_certified: false,
  definition_version: null,
  route: '/reports/trial-balance',
  reason: null,
};

describe('parseReportCapabilityManifest', () => {
  it('normalizes server report routes for internal navigation', () => {
    expect(resolveInternalReportRoute({ route: 'reports/trial-balance' })).toBe('/reports/trial-balance');
    expect(resolveInternalReportRoute({ route: '/reports/general-ledger' })).toBe('/reports/general-ledger');
    expect(resolveInternalReportRoute({ route: null })).toBeNull();
  });

  it('strips legacy statutory suffixes from internal labels', () => {
    expect(displayInternalReportLabel({ label: 'Bảng Cân đối kế toán (B01-DN)' })).toBe('Bảng Cân đối kế toán');
  });

  it('accepts a server manifest with typed capability rows', () => {
    const parsed = parseReportCapabilityManifest({
      meta: { disclaimer: 'Nội bộ', manifest_version: 'report-capabilities.v1' },
      capabilities: [capability],
    });

    expect(parsed.capabilities[0]).toEqual(capability);
  });

  it('fails closed when the capability collection or a row is malformed', () => {
    expect(() => parseReportCapabilityManifest({
      meta: { disclaimer: 'Nội bộ' },
      capabilities: { data: [] },
    })).toThrow(/capabilit/i);
    expect(() => parseReportCapabilityManifest({
      meta: { disclaimer: 'Nội bộ' },
      capabilities: [{ ...capability, available: 'yes' }],
    })).toThrow(/capabilit/i);
  });
});
