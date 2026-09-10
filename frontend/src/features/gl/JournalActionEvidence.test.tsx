import { describe, expect, it } from 'vitest';
import journalEntriesSource from './JournalEntries.tsx?raw';
import generalJournalsSource from './GeneralJournals.tsx?raw';

describe('journal action evidence boundary', () => {
    it('requires persisted journal resources or the documented delete response', () => {
        expect(journalEntriesSource).toContain('const persistedEntry = response?.data?.data ?? response?.data;');
        expect(journalEntriesSource).toContain('Máy chủ không trả về chứng từ kế toán đã lưu');
        expect(journalEntriesSource).toContain('response?.status !== 204');
        expect(generalJournalsSource).toContain('Máy chủ không trả về chứng từ nghiệp vụ khác đã lưu');
        expect(generalJournalsSource).toContain('Máy chủ không trả về chứng từ nhân bản');
        expect(generalJournalsSource).toContain('response?.status !== 204');
    });
});
