import { describe, expect, it } from 'vitest';

describe('ApprovedAccountMappings response evidence boundary', () => {
  it('requires persisted mapping evidence before success or invalidation', async () => {
    const source = await (await import('./ApprovedAccountMappings?raw')).default;
    expect(source).toContain('hasPersistedMapping');
    expect(source).toContain("Máy chủ chưa trả về mapping đã lưu. Không thể xác nhận thành công.");
    expect(source).toContain("Máy chủ chưa trả về mapping đã phê duyệt. Không thể xác nhận thành công.");
  });

  it('offers the controlled period-close mapping without requiring JSON context', async () => {
    const source = await (await import('./ApprovedAccountMappings?raw')).default;
    const contextSource = await (await import('./accountMappingContext?raw')).default;
    expect(source).toContain("{ value: 'period_close.result', label: 'Tổng hợp · Kết chuyển cuối kỳ' }");
    expect(source).toContain("'result_clearing', 'retained_earnings'");
    expect(contextSource).toContain("'period_close.result': []");
    expect(source).toContain('onMappingKeyChange');
  });

  it('keeps mapping and policy load failures retryable', async () => {
    const source = await (await import('./ApprovedAccountMappings?raw')).default;
    expect(source).toContain('action={<Button size="small" onClick={() => void mappingsQuery.refetch()}>Thử lại mapping</Button>}');
    expect(source).toContain('action={<Button size="small" onClick={() => void policyListQuery.refetch()}>Thử lại chính sách</Button>}');
  });

  it('lets the draft editors retry their required policy, profile, and account catalogues', async () => {
    const source = await (await import('./ApprovedAccountMappings?raw')).default;
    expect(source).toContain('policiesQuery.refetch()');
    expect(source).toContain('profilesQuery.refetch()');
    expect(source).toContain('accountsQuery.refetch()');
    expect(source).toContain('Không tải được policy đã duyệt');
    expect(source).toContain('Không tải được năm tài chính');
    expect(source).toContain('Không tải được danh mục tài khoản');
  });
});
