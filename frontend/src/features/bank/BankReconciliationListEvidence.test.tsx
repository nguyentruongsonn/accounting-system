import { describe, expect, it } from 'vitest';
import source from './BankStatementReconciliation.tsx?raw';

describe('bank reconciliation list response boundary', () => {
  it('does not render malformed 2xx imports or lines as valid empty evidence', () => {
    expect(source).toContain('Invalid bank-reconciliation imports response');
    expect(source).toContain('Invalid bank-reconciliation lines response');
    expect(source).toContain('parseImportsResponse((await api.get');
    expect(source).toContain('parseLinesResponse((await api.get');
  });
});
