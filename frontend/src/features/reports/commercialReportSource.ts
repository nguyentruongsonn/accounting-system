export type CommercialReportSourceType =
  | 'purchase_invoice'
  | 'purchase_return'
  | 'purchase_discount'
  | 'sales_invoice'
  | 'sales_return'
  | 'sales_discount';

const sourceRoutes: Record<CommercialReportSourceType, string> = {
  purchase_invoice: '/purchase/invoices',
  purchase_return: '/purchase?tab=6',
  purchase_discount: '/purchase?tab=7',
  sales_invoice: '/sales/invoices',
  sales_return: '/sales?tab=return',
  sales_discount: '/sales?tab=discount',
};

export function parseSourceRecordId(value: unknown): number | null {
  const id = Number(value);
  return Number.isSafeInteger(id) && id > 0 ? id : null;
}

export function commercialReportSourceRoute(sourceType: string, sourceId: number): string {
  const id = parseSourceRecordId(sourceId);
  if (id === null) throw new Error('Invalid source record identity.');
  const route = sourceRoutes[sourceType as CommercialReportSourceType];
  if (!route) throw new Error(`Unsupported commercial report source: ${sourceType}`);
  return `${route}${route.includes('?') ? '&' : '?'}source_id=${id}`;
}
