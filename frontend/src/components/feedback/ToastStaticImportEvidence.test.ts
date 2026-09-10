import { describe, expect, it } from 'vitest';

const sourceModules = import.meta.glob('../../**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>;

describe('toast static import boundary', () => {
  it('does not let application screens import Ant Design static message APIs', () => {
    const offenders = Object.entries(sourceModules)
      .filter(([path]) => !path.includes('.test.') && !path.endsWith('/toast.ts') && !path.endsWith('/ToastProvider.tsx'))
      .filter(([, source]) => /import\s*\{[^}]*\bmessage\b[^}]*\}\s*from\s*['"]antd['"]/.test(source))
      .map(([path]) => path)
      .sort();

    expect(offenders).toEqual([]);
  });
});
