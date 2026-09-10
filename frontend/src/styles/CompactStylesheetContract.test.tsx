// @ts-expect-error Node filesystem access is intentional for static CSS evidence.
import { readFileSync } from 'node:fs';
import { afterEach } from 'vitest';
import { describe, expect, it } from 'vitest';

const indexStyles = readFileSync('src/index.css', 'utf8');
const importedGlobalStylesheets = Array.from(
  indexStyles.matchAll(/@import\s+"(?<path>[^";]+\.css)";/g),
  ({ groups }) => {
    const path = groups?.path;
    if (!path) throw new Error('Global stylesheet import must have a path.');

    return {
      path,
      source: readFileSync(`src/${path.replace(/^\.\//, '')}`, 'utf8'),
    };
  },
);

const insertedStyles: HTMLStyleElement[] = [];

const installGlobalStylesheetCascade = () => {
  for (const { source } of importedGlobalStylesheets) {
    const style = document.createElement('style');
    style.textContent = source;
    document.head.append(style);
    insertedStyles.push(style);
  }

  const entryStyle = document.createElement('style');
  entryStyle.textContent = indexStyles.replace(/@import\s+[^;]+;/g, '');
  document.head.append(entryStyle);
  insertedStyles.push(entryStyle);
};

afterEach(() => {
  for (const style of insertedStyles.splice(0)) style.remove();
  document.querySelectorAll('.stylesheet-contract-warning').forEach((alert) => alert.remove());
});

describe('global alert visibility contract', () => {
  it('keeps warning alerts displayed, visible, and opaque after the imported global CSS cascade', () => {
    expect(importedGlobalStylesheets).not.toHaveLength(0);
    installGlobalStylesheetCascade();

    const warning = document.createElement('div');
    warning.className = 'ant-alert ant-alert-warning stylesheet-contract-warning';
    document.body.append(warning);

    const computed = getComputedStyle(warning);

    expect(computed.display).not.toBe('none');
    expect(computed.visibility).not.toBe('hidden');
    expect(Number(computed.opacity || '1')).toBeGreaterThan(0);
  });
});
