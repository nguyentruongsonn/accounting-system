export interface GeneralReportDrilldownRow {
  journal_entry_id?: unknown;
  source_document_type?: unknown;
  source_document_id?: unknown;
}

function parsePositiveInteger(value: unknown): number | null {
  const normalized = typeof value === 'number'
    ? value
    : typeof value === 'string' && value.trim() !== ''
      ? Number(value)
      : NaN;

  return Number.isSafeInteger(normalized) && normalized > 0 ? normalized : null;
}

/**
 * Report rows are read-only projections. The journal entry id is the
 * canonical tenant-scoped identity that the GL screen can resolve again
 * through its authenticated API. We deliberately do not trust a voucher
 * number (it is not globally unique) or a client-provided company id.
 */
export function generalReportJournalRoute(row: GeneralReportDrilldownRow): string | null {
  const journalEntryId = parsePositiveInteger(row.journal_entry_id);
  return journalEntryId === null
    ? null
    : '/gl?tab=tab-journals&source_id=' + journalEntryId;
}

const commercialSourceRoutes: Record<string, string> = {
  'App\\Models\\PurchaseInvoice': '/purchase/invoices',
  'App\\Models\\PurchaseReturn': '/purchase?tab=6',
  'App\\Models\\PurchaseDiscount': '/purchase?tab=7',
  'App\\Models\\SalesInvoice': '/sales/invoices',
  'App\\Models\\SalesReturn': '/sales?tab=return',
  'App\\Models\\SalesDiscount': '/sales?tab=discount',
};

/**
 * Prefer an existing source-document deep link when that screen implements
 * source_id consumption. Other source families still open the exact
 * authenticated journal entry instead of pretending a source screen exists.
 */
export function generalReportSourceRoute(row: GeneralReportDrilldownRow): string | null {
  const sourceType = typeof row.source_document_type === 'string'
    ? row.source_document_type.trim()
    : '';
  const sourceId = parsePositiveInteger(row.source_document_id);
  const sourceRoute = commercialSourceRoutes[sourceType];
  if (sourceRoute && sourceId !== null) {
    return sourceRoute + (sourceRoute.includes('?') ? '&' : '?') + 'source_id=' + sourceId;
  }

  return generalReportJournalRoute(row);
}
