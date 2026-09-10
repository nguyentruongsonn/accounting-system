import { describe, expect, it } from 'vitest';
import mainEntry from '../../main.tsx?raw';
import appSource from '../../App.tsx?raw';
import mainLayout from '../../layouts/MainLayout.tsx?raw';
// @ts-expect-error Node filesystem access is intentional for static CSS evidence.
import { readFileSync } from 'node:fs';

const compactStyles = readFileSync('src/styles/ui-compact.css', 'utf8');
const indexStyles = readFileSync('src/index.css', 'utf8');

const productionFeatureSources = import.meta.glob('/src/features/**/*.tsx', {
  eager: true,
  query: '?raw',
  import: 'default',
}) as Record<string, string>;
const productionComponentSources = import.meta.glob('/src/components/**/*.tsx', {
  eager: true,
  query: '?raw',
  import: 'default',
}) as Record<string, string>;

describe('canonical compact stylesheet contract', () => {
  it('is globally owned by the entry point and loads compact overrides last', () => {
    expect(mainEntry).toContain("import './index.css'");
    expect(appSource).not.toContain("import './index.css'");
    expect(mainLayout).not.toMatch(/import ['"].+\.css['"]/);
    expect(indexStyles.indexOf('@import "./styles/misa-theme.css"')).toBeGreaterThanOrEqual(0);
    expect(indexStyles.indexOf('@import "./styles/apple-shell.css"')).toBeGreaterThan(
      indexStyles.indexOf('@import "./styles/misa-theme.css"'),
    );
    expect(indexStyles.indexOf('@import "./styles/ui-compact.css"')).toBeGreaterThan(
      indexStyles.indexOf('@import "./styles/apple-shell.css"'),
    );
    expect(compactStyles).toContain('.ui-page-shell');
    expect(compactStyles).toContain('.ui-table-surface');
    expect(compactStyles).toContain('.ant-modal-content');
    expect(compactStyles).toContain('.ant-message');
  });

  it('does not let lazy feature/component chunks re-import the global theme', () => {
    const directGlobalImports = Object.entries({
      ...productionFeatureSources,
      ...productionComponentSources,
    })
      .filter(([path]) => !path.endsWith('.test.tsx'))
      .filter(([, source]) => source.includes('misa-theme.css'))
      .map(([path]) => path);

    expect(directGlobalImports).toEqual([]);
  });

  it('keeps semantic Ant alerts visible while hiding only decorative legacy banners', () => {
    expect(compactStyles).not.toMatch(/:where\([^)]*\.ant-alert-(?:info|warning)[^)]*\)\s*\{[^}]*display:\s*none\s*!important/);
    expect(compactStyles).toMatch(/:where\([^)]*\.misa-report-notice[^)]*\)\s*\{[^}]*display:\s*none\s*!important/);
  });
});
