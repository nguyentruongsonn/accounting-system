import { describe, expect, it } from 'vitest';
import source from './MainLayout.tsx?raw';

describe('purchase utility availability contract', () => {
  it('does not advertise backend-less utilities as runnable actions', () => {
    for (const label of [
      'Đối trừ chứng từ (chưa khả dụng)',
      'Đối trừ chứng từ nhiều đối tượng (chưa khả dụng)',
      'Bỏ đối trừ (chưa khả dụng)',
      'Bù trừ công nợ (chưa khả dụng)',
      'Đối chiếu công nợ NCC (chưa khả dụng)',
    ]) {
      expect(source).not.toContain(`label: '${label}'`);
    }
  });
});
