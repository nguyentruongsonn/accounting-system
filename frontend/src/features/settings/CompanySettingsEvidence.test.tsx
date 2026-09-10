import { describe, expect, it } from 'vitest';
import source from './CompanySettings.tsx?raw';

describe('Company settings evidence boundary', () => {
  it('does not expose save action without a persisted company id', () => {
    expect(source).toContain('disabled={!company?.id || mutation.isPending}');
    expect(source).toContain('Chưa có bằng chứng công ty từ máy chủ');
    expect(source).toContain('response?.data?.data?.id');
    expect(source).toContain('Thử lại thông tin công ty');
    expect(source).toContain('isCompanyError');
  });
});
