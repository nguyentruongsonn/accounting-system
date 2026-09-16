export type SalesDimensionSelectionContext = {
  status?: 'available' | 'not_required' | 'unavailable';
  posting_date?: string;
  policy_id?: number;
  policy_contract_hash?: string;
  reason_code?: string;
  reason?: string;
  required_dimensions?: Array<{
    code: string;
    name?: string;
    definition_id?: number;
    values?: Array<{ id: number; code: string; name: string; effective_from?: string; effective_to?: string }>;
  }>;
};

/** The browser never decides which accounting dimensions are valid. */
export function canEditSalesDimensions(input: {
  isPosted: boolean;
  context?: SalesDimensionSelectionContext;
  contextLoading: boolean;
  contextError: boolean;
  saveForbidden: boolean;
}): boolean {
  return !input.isPosted
    && !input.contextLoading
    && !input.contextError
    && !input.saveForbidden
    && input.context?.status === 'available';
}

export function shortSalesPolicyHash(hash?: string): string {
  if (!hash) return 'Chưa xác định';
  return `${hash.slice(0, 12)}…`;
}
