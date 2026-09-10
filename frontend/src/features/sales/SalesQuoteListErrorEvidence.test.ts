import { describe, expect, it } from 'vitest';
import source from './SalesQuotes.tsx?raw';

describe('sales quote list error boundary', () => {
  it('keeps a failed quote request distinct from a valid empty result', () => {
    expect(source).toContain('isError: isQuotesError');
    expect(source).toContain('refetch: refetchQuotes');
    expect(source).toContain('parseSalesQuoteCollection(data)');
    expect(source).toContain('Không thể tải danh sách báo giá');
    expect(source).toContain('Thử lại danh sách báo giá');
    expect(source).toContain('Thử lại danh mục báo giá');
    expect(source).toContain('isError: isCustomersError');
    expect(source).toContain('parseSalesQuoteCollection<any>(data, \'customers\')');
    expect(source).not.toContain('(data?.data || [])');
  });
});
