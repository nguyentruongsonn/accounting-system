/**
 * Context keys emitted by the posting gates for each mapping family/role.
 *
 * A mapping is resolved by an exact context hash. Keeping these templates in
 * one small, typed module prevents the editor from suggesting keys that the
 * server will never emit (for example `payment_status` on a cash voucher).
 */
type ContextTemplate = string[] | {
  default: string[];
  roles: Record<string, string[]>;
};

const LINE_CONTEXT = [
  'voucher_family',
  'voucher_type',
  'entry',
  'payment_method',
  'item_id',
  'warehouse_id',
  'source_account_code',
];

const MAPPING_CONTEXT_TEMPLATES: Record<string, ContextTemplate> = {
  'inventory.voucher': ['voucher_family', 'voucher_type', 'item_id', 'warehouse_id', 'source_account_code'],
  'purchase.invoice': {
    default: ['entry', 'source_account_code'],
    roles: {
      settlement_credit: ['entry', 'payment_method', 'payment_status', 'source_account_code'],
      purchase_debit: ['entry', 'voucher_type', 'source_account_code'],
      input_vat: ['entry', 'source_account_code'],
      import_tax_payable: ['entry', 'source_account_code'],
    },
  },
  'sales.invoice': {
    default: ['entry', 'source_account_code'],
    roles: {
      settlement_debit: ['entry', 'payment_method', 'payment_status', 'document_status', 'source_account_code'],
      revenue_credit: ['entry', 'voucher_type', 'source_account_code'],
      output_vat: ['entry', 'source_account_code'],
      cogs_debit: ['entry', 'source_account_code'],
      inventory_credit: ['entry', 'source_account_code'],
    },
  },
  'purchase.return': {
    default: ['voucher_family', 'voucher_type', 'entry', 'payment_method', 'source_account_code'],
    roles: {
      settlement_debit: ['voucher_family', 'voucher_type', 'entry', 'payment_method', 'source_account_code'],
      inventory_credit: [...LINE_CONTEXT],
      input_vat_reduction: [...LINE_CONTEXT],
    },
  },
  'purchase.discount': {
    default: ['voucher_family', 'voucher_type', 'entry', 'payment_method', 'source_account_code'],
    roles: {
      settlement_debit: ['voucher_family', 'voucher_type', 'entry', 'payment_method', 'source_account_code'],
      inventory_credit: [...LINE_CONTEXT],
      input_vat_reduction: [...LINE_CONTEXT],
    },
  },
  'sales.return': {
    default: ['voucher_family', 'voucher_type', 'entry', 'payment_method', 'source_account_code'],
    roles: {
      settlement_credit: ['voucher_family', 'voucher_type', 'entry', 'payment_method', 'source_account_code'],
      revenue_reduction: [...LINE_CONTEXT],
      output_vat_reduction: [...LINE_CONTEXT],
      inventory_debit: [...LINE_CONTEXT],
      cogs_credit: [...LINE_CONTEXT],
    },
  },
  'sales.discount': {
    default: ['voucher_family', 'voucher_type', 'entry', 'payment_method', 'source_account_code'],
    roles: {
      settlement_credit: ['voucher_family', 'voucher_type', 'entry', 'payment_method', 'source_account_code'],
      revenue_reduction: [...LINE_CONTEXT],
      output_vat_reduction: [...LINE_CONTEXT],
    },
  },
  'cash_bank.voucher': ['voucher_family', 'voucher_type', 'voucher_reason', 'source_account_code'],
  'period_close.result': [],
};

/** Return the exact context keys emitted for a mapping family and role. */
export function mappingContextFields(mappingKey: string | undefined, accountRole?: string): string[] {
  const template = mappingKey ? MAPPING_CONTEXT_TEMPLATES[mappingKey] : undefined;
  if (!template) return [];
  if (Array.isArray(template)) return [...template];
  if (accountRole && template.roles[accountRole]) return [...template.roles[accountRole]];
  return [...template.default];
}

export default MAPPING_CONTEXT_TEMPLATES;
