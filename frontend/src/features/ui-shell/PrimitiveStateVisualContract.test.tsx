import { describe, expect, it } from 'vitest';
// @ts-expect-error Node filesystem access is intentional for static CSS evidence.
import { readFileSync } from 'node:fs';

const shellStyles = readFileSync('src/styles/apple-shell.css', 'utf8');

describe('shared primitive state visual contract', () => {
  it('defines compact table and inline state surfaces', () => {
    expect(shellStyles).toContain('.ui-table-empty');
    expect(shellStyles).toContain('.ui-table-loading');
    expect(shellStyles).toContain('.ui-state-inline');
    expect(shellStyles).toContain('.ui-state-unavailable');
  });
});
