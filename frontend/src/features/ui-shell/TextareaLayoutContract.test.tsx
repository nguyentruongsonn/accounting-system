import { describe, expect, it } from 'vitest';
// @ts-expect-error Node filesystem access is intentional for static CSS evidence.
import { readFileSync } from 'node:fs';

const themeStyles = readFileSync('src/styles/misa-theme.css', 'utf8');

describe('textarea field layout contract', () => {
  it('keeps the Ant Design textarea child from drawing a second input frame', () => {
    const wrapperSelector = '.ant-input-affix-wrapper.ant-input-textarea-affix-wrapper';
    const wrapperStart = themeStyles.indexOf(wrapperSelector);
    expect(wrapperStart).toBeGreaterThanOrEqual(0);
    const wrapperBlock = themeStyles.slice(wrapperStart, themeStyles.indexOf('}', wrapperStart) + 1);
    expect(wrapperBlock).toMatch(/height:\s*auto\s*!important/);
    expect(wrapperBlock).toMatch(/max-height:\s*none\s*!important/);

    const selector = '.ant-input-textarea-affix-wrapper > textarea.ant-input';
    const selectorStart = themeStyles.indexOf(selector);
    expect(selectorStart).toBeGreaterThanOrEqual(0);

    const block = themeStyles.slice(selectorStart, themeStyles.indexOf('}', selectorStart) + 1);
    expect(block).toMatch(/height:\s*auto\s*!important/);
    expect(block).toMatch(/max-height:\s*none\s*!important/);
    expect(block).toMatch(/border:\s*0\s*!important/);
  });
});
