import { describe, expect, it } from 'vitest';
import source from './MainLayout.tsx?raw';

describe('local application branding', () => {
  it('uses local identity while retaining the shared accounting shell', () => {
    expect(source).toContain('Kế toán');
    expect(source).not.toContain('Chuẩn MISA AMIS');
  });
});
