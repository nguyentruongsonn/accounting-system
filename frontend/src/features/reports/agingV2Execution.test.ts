import { describe, expect, it } from 'vitest';
import { formatDecimalMoney } from '../../utils/decimalMoney';
import { executableAgingV2, v2RequestConfig } from './agingV2Execution';
import type { ManagementReportCapability } from './managementReportCapabilities';

const capability = (overrides: Partial<ManagementReportCapability> = {}): ManagementReportCapability => ({
  schema: 'management-report-capability.v1',
  key: 'accounts_payable_aging',
  label: 'AP aging',
  route: 'purchase/ap-aging',
  status: 'available',
  available: true,
  read_only: true,
  required_permission: 'purchase.reports.view',
  authorized_for_current_actor: true,
  statutory_or_appendix_iv_certified: false,
  production_ready: false,
  definition_version: null,
  reason: null,
  v2_execution: {
    available: true,
    route: 'management-reports/ap-aging',
    api_path: '/api/v2/management-reports/ap-aging',
    http_method: 'GET',
    status: 'operational_definition_effective',
    definition_version: 'ap-2026.1',
    production_ready: false,
    completeness_ready: true,
    reason: 'Read-only.',
  },
  ...overrides,
});

describe('executableAgingV2', () => {
  it('uses v2 only when the server affirmatively publishes capability, authorization and executable boundary', () => {
    expect(executableAgingV2(capability())).toEqual({
      apiPath: '/api/v2/management-reports/ap-aging',
      definitionVersion: 'ap-2026.1',
      reason: 'Read-only.',
    });

    expect(executableAgingV2(capability({ authorized_for_current_actor: false }))).toBeNull();
    expect(executableAgingV2(capability({ v2_execution: { ...capability().v2_execution!, available: false } }))).toBeNull();
    expect(executableAgingV2(capability({ v2_execution: { ...capability().v2_execution!, completeness_ready: false } }))).toBeNull();
    expect(executableAgingV2(capability({ v2_execution: { ...capability().v2_execution!, completeness_ready: undefined } }))).toBeNull();
    expect(executableAgingV2(capability({ v2_execution: undefined }))).toBeNull();
  });

  it('never turns a malformed manifest path into a request target', () => {
    expect(executableAgingV2(capability({ v2_execution: { ...capability().v2_execution!, api_path: '/api/v2/elsewhere' } }))).toBeNull();
    expect(v2RequestConfig('/api/v2/management-reports/ar-aging')).toEqual({
      baseURL: 'http://localhost:8000',
      url: 'api/v2/management-reports/ar-aging',
    });
    expect(() => v2RequestConfig('/api/v2/elsewhere')).toThrow('Đường dẫn v2');
  });

  it('formats v2 decimal-string amounts without coercing them through Number', () => {
    expect(formatDecimalMoney('9007199254740992.15', { currency: true }))
      .toBe('9.007.199.254.740.992,15\u00a0₫');
  });
});
