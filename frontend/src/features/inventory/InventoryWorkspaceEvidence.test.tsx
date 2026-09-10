import { describe, expect, it } from 'vitest';
import source from './InventoryWorkspace.tsx?raw';

describe('inventory workspace release scope', () => {
  it('exposes the approved weighted-average flow without statutory placeholders', () => {
    expect(source).toContain('Bình quân gia quyền cuối kỳ');
    expect(source).not.toContain('Bình quân gia quyền / FIFO');
    expect(source).not.toContain('chưa có definition version được phê duyệt');
    expect(source).not.toContain('Sổ/mẫu kho theo quy định');
  });
});
