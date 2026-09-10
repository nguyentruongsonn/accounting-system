import { describe, expect, it } from 'vitest';
import { isControlledReportEndpoint } from './reportDownload';
import source from './reportDownload.ts?raw';

describe('controlled report download endpoints', () => {
  it('accepts the published financial-report export paths', () => {
    expect(isControlledReportEndpoint('/reports/trial-balance')).toBe(true);
    expect(isControlledReportEndpoint('/reports/balance-sheet')).toBe(true);
    expect(isControlledReportEndpoint('/reports/income-statement')).toBe(true);
    expect(isControlledReportEndpoint('/reports/general-journal')).toBe(true);
    expect(isControlledReportEndpoint('/reports/general-ledger')).toBe(true);
  });

  it('rejects absolute and unlisted paths before they can be used as an API target', () => {
    expect(isControlledReportEndpoint('https://untrusted.example/export')).toBe(false);
    expect(isControlledReportEndpoint('/reports/statutory-readiness')).toBe(false);
  });

  it('uses a neutral internal-report export confirmation', () => {
    expect(source).toContain("message.success(`Đã tạo ${format === 'excel' ? 'tệp Excel' : 'tệp PDF'} báo cáo.`)");
    expect(source).not.toContain('không phải BCTC, báo cáo thuế hoặc chứng nhận TT99');
  });

  it('does not report success for an empty or non-Blob export response', () => {
    expect(source).toContain("response.data instanceof Blob");
    expect(source).toContain('response.data.size === 0');
    expect(source).toContain('throw new Error(\'Report export returned no non-empty artifact\')');
  });
});
