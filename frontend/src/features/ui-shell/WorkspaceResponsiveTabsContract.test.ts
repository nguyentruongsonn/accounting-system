import { describe, expect, it } from 'vitest';
// @ts-expect-error Node filesystem access is intentional for static CSS evidence.
import { readFileSync } from 'node:fs';

const compactStyles = readFileSync('src/styles/ui-compact.css', 'utf8');

describe('responsive workspace tabs', () => {
  it('keeps long tab labels inside the workspace and exposes horizontal scrolling', () => {
    expect(compactStyles).toMatch(/\.ui-workspace-tabs-root \.ant-tabs-nav\s*\{[^}]*width:\s*100%\s*!important/s);
    expect(compactStyles).toMatch(/\.ui-workspace-tabs-root \.ant-tabs-nav\s*\{[^}]*min-width:\s*0/s);
    expect(compactStyles).toMatch(/\.ui-workspace-tabs-root \.ant-tabs-nav\s*\{[^}]*overflow-x:\s*auto/s);
    expect(compactStyles).toMatch(/\.ui-workspace-tabs-root \.ant-tabs-nav\s*\{[^}]*overflow-y:\s*hidden/s);
    expect(compactStyles).toMatch(/\.ui-workspace-tabs-root \.ant-tabs-nav-wrap\s*\{[^}]*overflow-x:\s*auto\s*!important/s);
    expect(compactStyles).toMatch(/\.ui-workspace-tabs-root \.ant-tabs-nav-list\s*\{[^}]*min-width:\s*max-content/s);
  });

  it('stacks workflow canvas sidebars instead of forcing a desktop-width grid', () => {
    expect(compactStyles).toMatch(/@media \(max-width:\s*900px\)[\s\S]*?\.misa-ca-process-container\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\)/s);
    expect(compactStyles).toMatch(/@media \(max-width:\s*900px\)[\s\S]*?\.misa-ca-process-container\s*\{[^}]*min-width:\s*0/s);
    expect(compactStyles).toMatch(/@media \(max-width:\s*900px\)[\s\S]*?\.misa-ca-process-main\s*\{[^}]*min-width:\s*0/s);
  });
});
