import { describe, expect, it } from 'vitest';
import source from './BankWorkspace.tsx?raw';
import routeSource from '../../routes/index.tsx?raw';
import boundarySource from './BankCompatibilityBoundary.tsx?raw';

describe('bank workflow evidence boundary', () => {
  it('does not route the unsupported bank payment-request node to bank transactions', () => {
    expect(source).toContain('Đề nghị chi tiền (chưa khả dụng)');
    expect(source).toContain('Backend chưa công bố workflow ngân hàng');
    expect(source).toContain('aria-disabled="true"');
    expect(source).not.toContain("onClick={() => handleTabChange('tab-transactions')}");
  });

  it('keeps legacy bank URLs on a non-mutating compatibility boundary', () => {
    expect(routeSource).toContain("const BankCompatibilityBoundary = lazy(() => import('../features/bank/BankCompatibilityBoundary'));");
    expect(routeSource).toContain('<Route path="bank/payments" element={<BankCompatibilityBoundary />} />');
    expect(boundarySource).toContain('không tải dữ liệu và không cho tạo/sửa/ghi sổ');
    expect(boundarySource).not.toContain('api.post');
  });
});
