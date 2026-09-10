import { describe, expect, it } from 'vitest';
import source from './PeriodCloseWorkbench.tsx?raw';

describe('period-close workbench data boundary', () => {
  it('rejects malformed period/readiness/report envelopes instead of treating them as empty', () => {
    expect(source).toContain('function parseCollection<T>(value: unknown, resource: string): T[]');
    expect(source).toContain('function parseRecord<T>(value: unknown, resource: string, requiredKeys: readonly string[] = []): T');
    expect(source).toContain("parseCollection<PeriodOption>(response.data, 'kỳ kế toán')");
    expect(source).toContain("parseRecord<CloseReadiness>(");
    expect(source).toContain("'kết quả đối chiếu'");
    expect(source).toContain("'hồ sơ sign-off'");
    expect(source).not.toContain('response.data?.data ?? []');
  });

  it('validates successful mutation envelopes before refreshing close evidence', () => {
    expect(source).toContain("parseRecord<Record<string, unknown>>(");
    expect(source).toContain("'bằng chứng readiness mới'");
    expect(source).toContain("'kết quả đối chiếu mới'");
    expect(source).not.toContain('return (await api.post');
  });

  it('exposes a retry for every server-backed period-close source', () => {
    expect(source).toContain('Thử lại kỳ kế toán');
    expect(source).toContain('Thử lại readiness');
    expect(source).toContain('Thử lại kết quả đối chiếu');
    expect(source).toContain('Thử lại hồ sơ sign-off');
    expect(source).toContain('periodsQuery.refetch()');
    expect(source).toContain('readinessQuery.refetch()');
    expect(source).toContain('resultsQuery.refetch()');
    expect(source).toContain('packagesQuery.refetch()');
  });
});
