import { describe, expect, it } from 'vitest';
import { generalReportSourceRoute, generalReportJournalRoute } from './generalReportDrilldown';

describe('general report drilldown', () => {
  it('opens the tenant-scoped journal entry for a report row', () => {
    expect(generalReportJournalRoute({ journal_entry_id: 42 })).toBe('/gl?tab=tab-journals&source_id=42');
  });

  it('rejects missing or unsafe journal identities', () => {
    expect(generalReportJournalRoute({ journal_entry_id: null })).toBeNull();
    expect(generalReportJournalRoute({ journal_entry_id: '0' })).toBeNull();
    expect(generalReportJournalRoute({ journal_entry_id: '42.5' })).toBeNull();
  });

  it('opens supported commercial sources with their existing deep-link contracts', () => {
    expect(generalReportSourceRoute({
      journal_entry_id: 42,
      source_document_type: 'App\\Models\\SalesInvoice',
      source_document_id: 7,
    })).toBe('/sales/invoices?source_id=7');
    expect(generalReportSourceRoute({
      journal_entry_id: 42,
      source_document_type: 'App\\Models\\PurchaseReturn',
      source_document_id: 8,
    })).toBe('/purchase?tab=6&source_id=8');
  });

  it('falls back to the exact journal when a source screen is not available', () => {
    expect(generalReportSourceRoute({
      journal_entry_id: 42,
      source_document_type: 'App\\Models\\CashReceipt',
      source_document_id: 7,
    })).toBe('/gl?tab=tab-journals&source_id=42');
  });
});
