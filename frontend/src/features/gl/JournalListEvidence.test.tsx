import { describe, expect, it } from 'vitest';
import journalEntriesSource from './JournalEntries.tsx?raw';
import generalJournalsSource from './GeneralJournals.tsx?raw';

describe('GL journal list response evidence boundary', () => {
    it('rejects malformed 2xx list envelopes instead of treating them as valid empty lists', () => {
        expect(journalEntriesSource).toContain("throw new Error('Invalid journal entry list response.')");
        expect(generalJournalsSource).toContain("throw new Error('Invalid general journal list response.')");
        expect(generalJournalsSource).toContain("throw new Error('Invalid chart-of-accounts catalogue response.')");
        expect(journalEntriesSource).toContain('return parseJournalEntryList(data);');
        expect(generalJournalsSource).toContain("return (data as { data: unknown[] }).data;");
    });

    it('keeps journal list failures distinct from valid empty results', () => {
        expect(journalEntriesSource).toContain('isError: isJournalEntriesError');
        expect(journalEntriesSource).toContain('refetch: refetchJournalEntries');
        expect(journalEntriesSource).toContain('Không thể tải danh sách chứng từ kế toán');
        expect(journalEntriesSource).toContain('Thử lại danh sách chứng từ kế toán');
        expect(generalJournalsSource).toContain('isError: isJournalsError');
        expect(generalJournalsSource).toContain('refetch: refetchJournals');
        expect(generalJournalsSource).toContain('Không thể tải nhật ký chung');
        expect(generalJournalsSource).toContain('Thử lại nhật ký chung');
    });

    it('resolves report drilldown links through the tenant-scoped journal endpoint', () => {
        expect(generalJournalsSource).toContain("searchParams.get('source_id')");
        expect(generalJournalsSource).toContain("api.get('/gl/journal-entries/' + sourceId)");
        expect(generalJournalsSource).toContain("handleOpenModal('view', record)");
    });
});
