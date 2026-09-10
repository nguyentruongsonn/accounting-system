import { describe, expect, it } from 'vitest';
import source from './JournalEntries.tsx?raw';

describe('journal-entry account catalogue boundary', () => {
  it('loads server leaf accounts and does not expose free-text or posted-status shortcuts', () => {
    expect(source).toContain("api.get('/master/accounts')");
    expect(source).toContain('parseAccountCatalogue');
    expect(source).toContain('<AccountSelect accounts={accounts}');
    expect(source).not.toContain('placeholder="TK Nợ"');
    expect(source).not.toContain('placeholder="TK Có"');
    expect(source).not.toContain('<Select.Option value="posted">');
  });
});
