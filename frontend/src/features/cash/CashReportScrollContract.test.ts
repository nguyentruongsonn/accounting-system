import { describe, expect, it } from 'vitest';
import workspaceSource from './CashWorkspace.tsx?raw';
// @ts-expect-error Node filesystem access is intentional for static CSS evidence.
import { readFileSync } from 'node:fs';

const compactStyles = readFileSync('src/styles/ui-compact.css', 'utf8');

describe('cash report tab scrolling', () => {
  it('uses the workspace body as the only vertical scroll owner for long reports', () => {
    expect(workspaceSource).toMatch(
      /activeTabKey === 'tab-reports' \? 'misa-tab-pane' : 'misa-tab-pane-hidden'/,
    );
    expect(workspaceSource).not.toContain('misa-tab-pane--scroll');
    expect(compactStyles).not.toMatch(/\.misa-tab-pane--scroll\s*\{[^}]*overflow-y:\s*auto/s);
    expect(compactStyles).toMatch(/\.app-content\s*\{[^}]*height:\s*calc\(100vh/s);
    expect(compactStyles).toMatch(/\.ui-workspace-frame > \.ui-workspace-body\s*\{[^}]*overflow:\s*auto/s);
    expect(compactStyles).toMatch(/\.ui-page-shell\.misa-workspace-shell\s*\{[^}]*min-height:\s*0/s);
  });
});
