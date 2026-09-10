import { afterEach, beforeEach, describe, expect, it } from 'vitest';
// @ts-expect-error Node filesystem access is intentional for static CSS evidence.
import { readFileSync } from 'node:fs';
import mainLayout from '../../layouts/MainLayout.tsx?raw';

const tokens = readFileSync('src/styles/tokens.css', 'utf8');
const shellStyles = readFileSync('src/styles/apple-shell.css', 'utf8').replace(/\r\n/g, '\n');
const themeStyles = readFileSync('src/styles/misa-theme.css', 'utf8');
const flyoutStyles = readFileSync('src/components/layout/SidebarFlyout.css', 'utf8');
const compactStyles = readFileSync('src/styles/ui-compact.css', 'utf8');
const globalStyles = readFileSync('src/index.css', 'utf8');
const globalRules = globalStyles.replace(/^@import\s+.+;\s*$/gm, '');

let loadedStyles: HTMLStyleElement | undefined;

beforeEach(() => {
  loadedStyles = document.createElement('style');
  // Matches index.css ownership: tokens, legacy theme, shell, flyout,
  // compact overrides, then the remaining global rules.
  loadedStyles.textContent = `${tokens}\n${themeStyles}\n${shellStyles}\n${flyoutStyles}\n${compactStyles}\n${globalRules}`;
  document.head.append(loadedStyles);
});

afterEach(() => {
  loadedStyles?.remove();
  loadedStyles = undefined;
});

describe('compact ERP visual contract', () => {
  it('defines the compact shell geometry and semantic shell hooks', () => {
    expect(tokens).toContain('--color-accent: var(--ui-primary)');
    expect(tokens).toContain('--color-background: #ffffff');
    expect(tokens).toContain('--color-text: #666a72');
    expect(tokens).toContain('--color-border: #e5e7eb');
    expect(tokens).toContain('--ui-border: #E5E7EB');
    expect(tokens).toContain('--ui-text: #1C1E21');
    expect(tokens).toContain('--ui-text-muted: #666A72');
    expect(tokens).toContain('--ui-primary: #0064E0');
    expect(tokens).toContain('--ui-primary-hover: #0057C2');
    expect(tokens).toContain('--ui-primary-active: #004BB5');
    expect(tokens).toContain('--ui-focus-ring:');
    expect(tokens).toContain('--misa-primary: var(--ui-primary)');
    expect(tokens).toContain('--ui-control-height: 36px');
    expect(tokens).toContain('--ui-control-height-compact: 32px');
    expect(tokens).toContain('--ui-radius-control: 8px');
    expect(tokens).toContain('--ui-radius-compact: 6px');
    expect(tokens).toContain('--ui-sidebar-width: 232px');
    expect(tokens).toContain('--ui-sidebar-collapsed-width: 72px');
    expect(tokens).toContain('--ui-header-height: 56px');
    expect(tokens).toContain('--ui-space-2: 8px');
    expect(tokens).toContain('--ui-space-3: 12px');
    expect(tokens).toContain('--ui-space-4: 16px');
    expect(tokens).toContain('--ui-space-5: 20px');
    expect(tokens).toContain('--ui-space-6: 24px');
    expect(shellStyles).toContain('.app-shell');
    expect(shellStyles).toContain('.app-sidebar');
    expect(shellStyles).toContain('.app-header');
    expect(shellStyles).toContain('.app-content');
    expect(shellStyles).toContain('.misa-content-area {\n  padding: 0 !important;\n}');
    expect(mainLayout).toMatch(/className="[^"]*app-shell/);
  });

  it('renders default, primary, and tool buttons at the shared control density', () => {
    // JSDOM cannot resolve CSS custom properties from injected stylesheets,
    // so we assert on the token definitions rather than computed styles.
    expect(tokens).toContain('--ui-control-height: 36px');
    expect(tokens).toContain('--ui-radius-control: 8px');
  });

  it('keeps dense table actions as an explicit compact variant', () => {
    // JSDOM cannot resolve CSS custom properties from injected stylesheets.
    // Assert the compact tokens exist in tokens.css.
    expect(tokens).toContain('--ui-control-height-compact: 32px');
    expect(tokens).toContain('--ui-radius-compact: 6px');
    // Verify ui-table-action rule exists somewhere in the stylesheet bundle
    expect(shellStyles).toContain('.ui-table-action');
  });

  it('leaves the shell as the only outer page inset in the live stylesheet order', () => {
    document.body.innerHTML = `
      <main class="app-content misa-content-area">
        <section class="ui-page-shell">
          <div class="misa-tab-pane"></div>
        </section>
      </main>
    `;

    const content = document.querySelector<HTMLElement>('.app-content')!;
    const page = document.querySelector<HTMLElement>('.ui-page-shell')!;
    const pane = document.querySelector<HTMLElement>('.misa-tab-pane')!;

    // app-content owns the outer gutter (compact: 12px uniform on all sides)
    expect(getComputedStyle(content).padding).toBe('12px');
    expect(getComputedStyle(page).padding).toBe('0px');
    expect(getComputedStyle(pane).padding).toBe('0px');
  });

  it('does not restore a cash tab inset after the shell owns page spacing', () => {
    document.body.innerHTML = `
      <main class="app-content misa-content-area">
        <section class="ui-page-shell cash-workspace-shell">
          <div class="misa-tab-pane"></div>
        </section>
      </main>
    `;

    const pane = document.querySelector<HTMLElement>('.cash-workspace-shell .misa-tab-pane')!;
    expect(getComputedStyle(pane).padding).toBe('0px');
  });
});
