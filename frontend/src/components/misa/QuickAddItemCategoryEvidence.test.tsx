import { describe, expect, it } from 'vitest';
import source from './QuickAddItemCategoryModal.tsx?raw';

describe('quick-add item category source evidence', () => {
  it('keeps parent-category lookup fail-visible and portal-safe', () => {
    expect(source).toContain('Không thể tải danh mục nhóm hàng hóa');
    expect(source).toContain('Thử lại');
    expect(source).toContain('getPopupContainer={() => document.body}');
    expect(source).not.toContain('} catch (e) {\n                return [];');
    expect(source).toContain('Phản hồi danh mục nhóm hàng hóa không hợp lệ');
  });
});
