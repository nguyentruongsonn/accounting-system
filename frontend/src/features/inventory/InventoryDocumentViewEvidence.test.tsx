import { describe, expect, it } from 'vitest';
import receiptsSource from './InventoryReceipts.tsx?raw';
import issuesSource from './InventoryIssues.tsx?raw';

describe('inventory document view action', () => {
  it('opens persisted receipt and issue details instead of showing a fake toast', () => {
    for (const source of [receiptsSource, issuesSource]) {
      expect(source).toContain('const handleViewModal');
      expect(source).toContain('isViewMode');
      expect(source).toContain('onClick={() => void handleViewModal(record)}');
      expect(source).not.toContain('message.info(`Xem chứng từ ${record.voucher_number}`)');
    }
  });
});
