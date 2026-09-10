// @ts-expect-error Node filesystem access is intentional for static CSS evidence.
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const compactStyles = readFileSync('src/styles/ui-compact.css', 'utf8');

describe('workspace pane overflow contract', () => {
  it('keeps the workspace body as the only vertical scroll owner for native tab panes', () => {
    expect(compactStyles).toMatch(
      /\.ui-workspace-frame > \.ui-workspace-body\s*\{[^}]*overflow:\s*auto/s,
    );
    expect(compactStyles).not.toMatch(
      /\.misa-tab-panel-container > \.misa-tab-pane:not\(\.misa-tab-pane-hidden\)\s*\{[^}]*overflow-y:\s*auto\s*!important/s,
    );
  });
});
