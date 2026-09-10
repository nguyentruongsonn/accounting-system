import { describe, expect, it } from 'vitest';

import contact from './QuickAddContactModal.tsx?raw';
import employee from './QuickAddEmployeeModal.tsx?raw';

describe('quick-add local draft action evidence', () => {
  it('does not present local-only group, department or title edits as persisted successes', () => {
    for (const source of [contact, employee]) {
      expect(source).toContain('chưa lưu danh mục trên máy chủ');
    }
    expect(contact).not.toContain("message.success('Đã thêm nhóm đối tượng mới!')");
    expect(employee).not.toContain("message.success('Đã thêm phòng ban mới!')");
    expect(employee).not.toContain("message.success('Đã thêm chức danh mới!')");
  });
});
