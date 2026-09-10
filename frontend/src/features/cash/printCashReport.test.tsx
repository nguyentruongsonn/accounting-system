import { render } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CashReportPrint } from './CashReportPrint';
import { printCashReport } from './printCashReport';
import { cashReportTestFixture } from './cashReportTestFixture';
import reportStyles from './cash-reports.css?raw';

// Vitest stubs CSS imports, including ?raw; load the actual asset for this
// iframe integration test (never substitute expected CSS declarations).
vi.mock('./cash-reports.css?raw', async () => {
  // @ts-expect-error Node is available in the test runner, not the app build.
  const { readFileSync } = await import('node:fs');
  return { default: readFileSync('src/features/cash/cash-reports.css', 'utf8') };
});

afterEach(() => { vi.restoreAllMocks(); vi.useRealTimers(); document.querySelectorAll('iframe').forEach(frame => frame.remove()); });

function flattenedRules(sheet: CSSStyleSheet | CSSGroupingRule): CSSRule[] {
  return Array.from(sheet.cssRules).flatMap(rule => [rule, ...('cssRules' in rule ? flattenedRules(rule as CSSGroupingRule) : [])]);
}

describe('cash report print isolation', () => {
  it('does not install an unnamed page rule when the shared report stylesheet loads', () => {
    const style = document.createElement('style');
    style.textContent = reportStyles;
    document.head.appendChild(style);
    try {
      // Inspect the installed stylesheet's parsed rules, including @media rules,
      // rather than merely grepping TypeScript/CSS source.
      const unscopedPageRules = flattenedRules(style.sheet!).filter(rule => /^@page\s*\{/.test(rule.cssText));
      expect(unscopedPageRules).toHaveLength(0);
    } finally { style.remove(); }
  });
  it('clones only the full report into the sandboxed frame', () => {
    vi.useFakeTimers();
    const { container } = render(<CashReportPrint report={cashReportTestFixture()}/>);
    printCashReport(container.firstElementChild as HTMLElement, 'CA-03');
    const frame = document.querySelector('iframe')!;
    expect(frame.getAttribute('sandbox')).toBe('allow-same-origin allow-modals');
    expect(frame.contentDocument?.querySelectorAll('tbody tr')).toHaveLength(101);
    expect(frame.contentDocument?.body).toHaveTextContent('PT-LAST');
    expect(frame.contentDocument?.querySelector('button, nav')).toBeNull();
    expect(frame.contentDocument?.head.textContent).toContain('table-header-group');
    const frameStyles = frame.contentDocument!.querySelector('style')!;
    const pageRules = flattenedRules(frameStyles.sheet!).filter(rule => /^@page\s*\{/.test(rule.cssText));
    // jsdom's page-rule CSSOM drops the valid size descriptor; verify it in
    // the actual injected iframe stylesheet, and margin in its parsed rule.
    expect(frameStyles.textContent).toMatch(/@page\s*\{[^}]*size:\s*A4 landscape/);
    expect(pageRules.at(-1)?.cssText).toContain('margin: 12mm');
  });
  it('fails closed without printing the whole app when a frame document is unavailable', () => {
    const source = document.createElement('article');
    vi.spyOn(HTMLIFrameElement.prototype, 'contentDocument', 'get').mockReturnValue(null);
    const wholeAppPrint = vi.spyOn(window, 'print').mockImplementation(() => {});
    expect(() => printCashReport(source, 'CA-03')).toThrow('Không thể tạo bản in báo cáo');
    expect(wholeAppPrint).not.toHaveBeenCalled();
    expect(document.querySelector('iframe')).toBeNull();
  });
});
