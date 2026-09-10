import { describe, expect, it } from 'vitest';
import optionsSource from './SystemOptions.tsx?raw';

describe('settings unavailable boundaries', () => {
  it('does not expose unbound system-option save actions', () => {
    expect(optionsSource).toContain('Lưu tùy chọn (chưa khả dụng)');
    expect(optionsSource).toContain('disabled');
    expect(optionsSource).toContain('Không lưu thay đổi cục bộ');
  });
});
