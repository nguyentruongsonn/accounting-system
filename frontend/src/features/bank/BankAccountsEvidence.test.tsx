import { describe, expect, it } from 'vitest';
import source from './BankAccounts.tsx?raw';

describe('bank-account catalogue response boundary', () => {
    it('does not turn a malformed 2xx catalogue into a valid empty list', () => {
        expect(source).toContain('Invalid bank-account list response');
        expect(source).toContain('return parseBankAccountList(data);');
        expect(source).not.toContain('(data?.data || [])');
    });
});
