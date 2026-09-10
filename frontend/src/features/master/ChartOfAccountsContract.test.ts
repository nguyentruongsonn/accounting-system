import { describe, expect, it } from 'vitest';
import { parseAccounts } from './ChartOfAccounts';

const row = {
    id: 1,
    code: '111',
    name: 'Tiền mặt',
    description: null,
    type: 'asset',
    nature: 'debit',
    level: 1,
    parent_code: null,
    is_parent: false,
    is_active: true,
};

describe('chart-of-accounts response contract', () => {
    it('accepts the bare array returned by the API', () => {
        expect(parseAccounts([row])).toEqual([row]);
    });

    it('accepts a Laravel collection data envelope', () => {
        expect(parseAccounts({ data: [row] })).toEqual([row]);
    });

    it('rejects malformed envelopes and rows instead of rendering an empty list', () => {
        expect(() => parseAccounts({ data: [] })).not.toThrow();
        expect(() => parseAccounts({ data: [{ ...row, id: '1' }] })).toThrow(/Invalid chart-of-accounts row/);
        expect(() => parseAccounts({ items: [row] })).toThrow(/Invalid chart-of-accounts response/);
    });
});
