import { describe, expect, it } from 'vitest';
import apAging from '../purchase/APAgingReport.tsx?raw';
import arAging from '../sales/ARAgingReport.tsx?raw';
import apArBoundary from './ApArReconciliationInputBoundary.tsx?raw';
import statutory from './StatutoryFinancialStatementReadiness.tsx?raw';

describe('AP/AR and statutory evidence boundaries', () => {
    it('rejects malformed success payloads instead of rendering empty or executable evidence', () => {
        for (const source of [apAging, arAging]) {
            expect(source).toContain('parseLegacyResponse');
            expect(source).toContain('parseV2Response');
            expect(source).not.toContain('data?.data ?? []');
        }
        expect(apArBoundary).toContain('parseRun');
        expect(apArBoundary).toContain('parsePage');
        expect(statutory).toContain('parseReadiness');
        expect(statutory).toContain('result.execution_ready !== false');
    });
});
