import { afterAll, beforeAll, describe, expect, it } from 'vitest';
// @ts-expect-error Vitest executes this evidence test in Node; app TS config intentionally excludes Node globals.
import { readFileSync } from 'node:fs';

const themeStyles = readFileSync('src/styles/misa-theme.css', 'utf8');
const shellStyles = readFileSync('src/styles/apple-shell.css', 'utf8');
let styleElement: HTMLStyleElement;

beforeAll(() => {
  styleElement = document.createElement('style');
  styleElement.textContent = `${themeStyles}\n${shellStyles}`;
  document.head.append(styleElement);
});

afterAll(() => {
  styleElement.remove();
  document.body.innerHTML = '';
});

describe('compact workspace padding', () => {
  it('does not stack outer padding around process canvases', () => {
    document.body.innerHTML = `
      <main class="ant-layout-content misa-content-area">
        <section class="ui-page-shell cash-workspace-shell">
          <div class="misa-tab-pane">
            <div class="misa-ca-process-container"></div>
          </div>
        </section>
      </main>
    `;

    for (const selector of ['.misa-content-area', '.cash-workspace-shell', '.misa-tab-pane', '.misa-ca-process-container']) {
      const element = document.querySelector<HTMLElement>(selector);
      expect(element).not.toBeNull();
      expect(parseFloat(getComputedStyle(element!).paddingTop) || 0).toBe(0);
      expect(parseFloat(getComputedStyle(element!).paddingLeft) || 0).toBe(0);
    }
  });

  it('does not add a second page gutter to list pages mounted inside a tab', () => {
    document.body.innerHTML = `
      <main class="misa-content-area">
        <section class="ui-page-shell cash-workspace-shell">
          <div class="misa-tab-pane">
            <main class="ui-page-shell cash-list-page"></main>
          </div>
        </section>
      </main>
    `;

    const nestedPage = document.querySelector<HTMLElement>('.misa-tab-pane > .ui-page-shell');
    expect(nestedPage).not.toBeNull();
    expect(parseFloat(getComputedStyle(nestedPage!).paddingTop) || 0).toBe(0);
    expect(parseFloat(getComputedStyle(nestedPage!).paddingLeft) || 0).toBe(0);
  });
});
