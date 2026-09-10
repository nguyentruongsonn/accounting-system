// @ts-expect-error Node filesystem access is intentional for static CSS evidence.
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

const tokens = readFileSync('src/styles/tokens.css', 'utf8');
const themeStyles = readFileSync('src/styles/misa-theme.css', 'utf8');
const shellStyles = readFileSync('src/styles/apple-shell.css', 'utf8');
const compactStyles = readFileSync('src/styles/ui-compact.css', 'utf8');

let loadedStyles: HTMLStyleElement | undefined;

beforeEach(() => {
  loadedStyles = document.createElement('style');
  // JSDOM applies selector precedence but does not resolve custom properties.
  // Resolve the semantic values here so these assertions exercise the actual
  // cascade between the representative Ant/legacy class combinations.
  loadedStyles.textContent = `${tokens}\n${themeStyles}\n${shellStyles}\n${compactStyles}`
    .replaceAll('var(--ui-button-primary-background)', '#0064e0')
    .replaceAll('var(--ui-button-primary-background-hover)', '#0057c2')
    .replaceAll('var(--ui-button-primary-background-active)', '#004bb5')
    .replaceAll('var(--ui-button-primary-border)', '#0064e0')
    .replaceAll('var(--ui-button-primary-text)', '#ffffff')
    .replaceAll('var(--ui-button-secondary-background)', '#ffffff')
    .replaceAll('var(--ui-button-secondary-background-hover)', '#f9fafb')
    .replaceAll('var(--ui-button-secondary-border)', '#e5e7eb')
    .replaceAll('var(--ui-button-secondary-border-hover)', '#0064e0')
    .replaceAll('var(--ui-button-secondary-text)', '#4b5563')
    .replaceAll('var(--ui-button-danger-background)', '#ef4444')
    .replaceAll('var(--ui-button-danger-background-hover)', '#dc2626')
    .replaceAll('var(--ui-button-danger-border)', '#ef4444')
    .replaceAll('var(--ui-button-danger-text)', '#ffffff')
    .replaceAll('var(--ui-button-disabled-background)', '#f3f4f6')
    .replaceAll('var(--ui-button-disabled-border)', '#e5e7eb')
    .replaceAll('var(--ui-button-disabled-text)', '#9ca3af')
    .replaceAll('var(--ui-focus-ring)', '0 0 0 3px rgb(0, 100, 224)')
    .replaceAll('var(--shell-blue)', '#007aff')
    .replaceAll(':focus-visible', '.__theme-test-focus-visible');
  document.head.append(loadedStyles);
});

afterEach(() => {
  loadedStyles?.remove();
  loadedStyles = undefined;
  document.body.replaceChildren();
});

describe('theme primitive contract', () => {
  it('derives button state and shared-surface aliases from the semantic token palette', () => {
    expect(tokens).toContain('--ui-button-primary-background: var(--ui-primary);');
    expect(tokens).toContain('--ui-button-secondary-background: var(--ui-surface);');
    expect(tokens).toContain('--ui-button-danger-background: var(--ui-danger);');
    expect(tokens).toContain('--ui-button-disabled-background: var(--ui-surface-muted);');
    expect(tokens).toContain('--ui-table-surface-background: var(--ui-surface);');
    expect(tokens).toContain('--ui-modal-footer-background: var(--ui-surface-subtle);');
  });

  it('keeps compatibility accent and reference confirmation states derived from the semantic primary palette', () => {
    expect(tokens).toContain('--color-accent: var(--ui-primary);');
    expect(tokens).not.toContain('--color-accent: #0064e0;');
    expect(themeStyles).toMatch(/\.misa-ref-btn-confirm\s*\{[\s\S]*?background-color:\s*var\(--ui-button-primary-background\)\s*!important/);
    expect(themeStyles).toMatch(/\.misa-ref-btn-confirm:hover:not\(:disabled\)\s*\{[\s\S]*?background-color:\s*var\(--ui-button-primary-background-hover\)\s*!important/);
  });

  it('keeps one authoritative muted-text declaration in the shell layer', () => {
    expect(shellStyles.match(/\.apple-muted-text\s*\{/g)).toHaveLength(1);
    expect(shellStyles).toMatch(/\.apple-muted-text\s*\{[\s\S]*?color:\s*var\(--ui-text-muted\)\s*!important[\s\S]*?font-size:\s*12px\s*!important/);
  });

  it('does not reintroduce the removed legacy page-header selector', () => {
    expect(tokens).not.toContain('ui-page-header');
    expect(compactStyles).not.toContain('ui-page-header');
    expect(compactStyles).toMatch(/\.page-title-content\s*\{\s*display:\s*block\s*!important/);
  });

  it('maps primary, secondary, danger, disabled, and focus controls to shared tokens', () => {
    expect(shellStyles).toContain('--ui-button-primary-background');
    expect(shellStyles).toContain('--ui-button-secondary-border');
    expect(shellStyles).toContain('--ui-button-danger-background');
    expect(shellStyles).toContain('--ui-button-disabled-text');
    expect(shellStyles).toContain('box-shadow: var(--ui-focus-ring) !important;');
  });

  it('styles table and modal regions with the shared surface tokens', () => {
    expect(compactStyles).toContain('.ui-table-surface__scroll');
    expect(compactStyles).toContain('.ui-table-surface__summary');
    expect(compactStyles).toContain('var(--ui-table-header-background)');
    expect(compactStyles).toContain('.ui-modal-frame__body');
    expect(compactStyles).toContain('.ui-modal-frame__footer');
    expect(compactStyles).toContain('var(--ui-modal-footer-background)');
  });

  it('keeps semantic Ant button variants in their token state after the full stylesheet cascade', () => {
    document.body.innerHTML = `
      <button class="ant-btn ant-btn-primary">Primary</button>
      <button class="ant-btn ant-btn-default ant-btn-dangerous">Danger</button>
      <button class="ant-btn ant-btn-primary" disabled>Disabled</button>
      <button class="misa-btn-pill-primary">Pill primary</button>
    `;

    const [primary, danger, disabled, pill] = Array.from(document.querySelectorAll<HTMLButtonElement>('button'));
    expect(getComputedStyle(primary).backgroundColor).toBe('rgb(0, 100, 224)');
    expect(getComputedStyle(danger).backgroundColor).toBe('rgb(239, 68, 68)');
    expect(getComputedStyle(disabled).backgroundColor).toBe('rgb(243, 244, 246)');
    expect(getComputedStyle(pill).backgroundColor).toBe('rgb(0, 100, 224)');
  });

  it.each(['misa-btn-secondary', 'misa-btn-danger'])('keeps a standalone %s button on the shared focus ring', (className) => {
    document.body.innerHTML = `<button class="${className} __theme-test-focus-visible">Action</button>`;

    const button = document.querySelector<HTMLButtonElement>('button')!;
    expect(getComputedStyle(button).boxShadow).toBe('0 0 0 3px rgb(0, 100, 224)');
  });

  it('keeps informational alerts visible after the full stylesheet cascade', () => {
    document.body.innerHTML = '<div class="ant-alert ant-alert-info">Information</div>';

    expect(getComputedStyle(document.querySelector<HTMLElement>('.ant-alert-info')!).display).not.toBe('none');
  });
});
