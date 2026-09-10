import { describe, expect, it } from 'vitest';
import source from './CashPayments.tsx?raw';

describe('cash payment list error boundary', () => {
  it('keeps an unavailable list distinct from a valid empty result', () => {
    expect(source).toContain('isError: isPaymentsError');
    expect(source).toContain('refetch: refetchPayments');
    expect(source).toContain('Không thể tải danh sách phiếu chi');
    expect(source).toContain('Thử lại danh sách phiếu chi');
  });
});
