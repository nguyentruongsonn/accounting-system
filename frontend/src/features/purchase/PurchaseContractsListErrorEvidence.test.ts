import { describe, expect, it } from 'vitest';
import source from './PurchaseContracts.tsx?raw';

describe('purchase contract list loading boundary', () => {
  it('keeps contract failures distinct from a valid empty list and retryable', () => {
    expect(source).toContain('isError: isContractsError');
    expect(source).toContain('refetch: refetchContracts');
    expect(source).toContain('Không thể tải danh sách hợp đồng mua hàng');
    expect(source).toContain('Thử lại danh sách hợp đồng mua hàng');
    expect(source).toContain('parsePurchaseCollection');
    expect(source).not.toContain('} catch (e) {\n                return [];');
  });
});
