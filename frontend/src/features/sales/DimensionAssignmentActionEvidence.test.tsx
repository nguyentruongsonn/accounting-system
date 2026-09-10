import { describe, expect, it } from 'vitest';
import purchaseSource from '../purchase/PurchaseInvoiceDimensionAssignments.tsx?raw';
import salesSource from './SalesInvoiceDimensionAssignments.tsx?raw';

describe('invoice dimension assignment action evidence', () => {
  it('requires the server assignment revision and rows before claiming save success', () => {
    for (const source of [salesSource, purchaseSource]) {
      expect(source).toContain('hasPersistedDimensionEvidence(response)');
      expect(source).toContain('Number.isInteger(payload?.assignment_revision)');
      expect(source).toContain('payload.assignments.length > 0');
      expect(source).toContain('Máy chủ chưa trả về snapshot chiều hạch toán đã lưu. Không thể xác nhận thành công.');
      expect(source).not.toContain("onSuccess: async () => {");
    }
  });
});
