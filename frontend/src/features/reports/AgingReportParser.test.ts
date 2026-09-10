import { describe, expect, it } from 'vitest';
import { parseLegacyResponse as parseApLegacyResponse, parseV2Response as parseApV2Response } from '../purchase/APAgingReport';
import { parseLegacyResponse as parseArLegacyResponse, parseV2Response as parseArV2Response } from '../sales/ARAgingReport';

const apRow = {
    supplier_id: 1,
    supplier_code: 'SUP-001',
    supplier_name: 'Nhà cung cấp',
    total_due: '100.00',
    current: '100.00',
    days_1_30: '0.00',
    days_31_60: '0.00',
    days_over_60: '0.00',
};

const arRow = {
    customer_id: 1,
    customer_code: 'CUS-001',
    customer_name: 'Khách hàng',
    total_due: '100.00',
    current: '100.00',
    days_1_30: '0.00',
    days_31_60: '0.00',
    days_over_60: '0.00',
};

const apV2Row = { ...apRow, credit_balance: '0.00' };
const arV2Row = { ...arRow, credit_balance: '0.00' };

describe('AP/AR aging response parsers', () => {
    it('accepts canonical numeric ids and decimal amount strings', () => {
        expect(parseApLegacyResponse([apRow])).toEqual([apRow]);
        expect(parseArLegacyResponse([arRow])).toEqual([arRow]);
        expect(parseApV2Response({ definition_version: 'v2', as_of_date: '2026-08-31', rows: [apV2Row] }).rows).toEqual([apV2Row]);
        expect(parseArV2Response({ definition_version: 'v2', as_of_date: '2026-08-31', rows: [arV2Row] }).rows).toEqual([arV2Row]);
    });

    it('rejects malformed party ids before a row can be rendered', () => {
        for (const id of [0, -1, 1.5, Number.NaN, '1']) {
            expect(() => parseApLegacyResponse([{ ...apRow, supplier_id: id }])).toThrow(/Invalid AP aging row/);
            expect(() => parseArLegacyResponse([{ ...arRow, customer_id: id }])).toThrow(/Invalid AR aging row/);
        }
    });

    it('rejects malformed amount strings before a row can be rendered', () => {
        for (const amount of ['', 'not-a-number', '1e3', '1.234']) {
            expect(() => parseApLegacyResponse([{ ...apRow, total_due: amount }])).toThrow(/Invalid AP aging row/);
            expect(() => parseArLegacyResponse([{ ...arRow, total_due: amount }])).toThrow(/Invalid AR aging row/);
        }
    });

    it('requires a valid credit balance on v2 rows while allowing legacy rows to omit it', () => {
        expect(parseApLegacyResponse([apRow])).toEqual([apRow]);
        expect(parseArLegacyResponse([arRow])).toEqual([arRow]);

        for (const creditBalance of [undefined, '', 'not-a-number', '1.234']) {
            expect(() => parseApV2Response({ definition_version: 'v2', as_of_date: '2026-08-31', rows: [{ ...apV2Row, credit_balance: creditBalance }] })).toThrow(/Invalid AP v2 aging row/);
            expect(() => parseArV2Response({ definition_version: 'v2', as_of_date: '2026-08-31', rows: [{ ...arV2Row, credit_balance: creditBalance }] })).toThrow(/Invalid AR v2 aging row/);
        }
    });
});
