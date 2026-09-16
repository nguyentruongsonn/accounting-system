import { multiplyOpeningBalanceValue, summarizeOpeningBalanceLines } from './openingBalanceMath';
import * as XLSX from 'xlsx';

export const OPENING_BALANCE_COLUMNS = [
  'Loại dòng',
  'Tài khoản',
  'Mã đối tượng',
  'Loại đối tượng',
  'Mã hàng',
  'Mã kho',
  'Dư Nợ',
  'Dư Có',
  'Số lượng',
  'Đơn giá',
] as const;

export type OpeningBalanceImportRow =
  | { kind: 'account'; account_code: string; debit_amount: string; credit_amount: string }
  | { kind: 'party'; account_code: string; party_code: string; party_type: 'customer' | 'supplier' | 'employee'; debit_amount: string; credit_amount: string }
  | { kind: 'inventory'; account_code: string; item_code: string; warehouse_code: string; debit_amount: string; credit_amount: string; quantity: string; unit_cost: string };

export type OpeningBalanceImportError = { row: number; message: string };
export type OpeningBalanceImportResult = {
  validRows: OpeningBalanceImportRow[];
  errors: OpeningBalanceImportError[];
  totals: { debit: string; credit: string; inventory_value: string };
};

export type OpeningBalanceImportReferences = {
  accountCodes: Set<string>;
  partyCodes: Set<string>;
  itemCodes: Set<string>;
  warehouseCodes: Set<string>;
};

const text = (value: unknown) => String(value ?? '').trim();
const decimal = (value: unknown, scale: number) => {
  const candidate = text(value);
  return new RegExp(`^\\d+(?:\\.\\d{1,${scale}})?$`).test(candidate) ? candidate : null;
};

export const parseOpeningBalanceRows = (
  rows: ReadonlyArray<ReadonlyArray<unknown>>,
  references: OpeningBalanceImportReferences,
): OpeningBalanceImportResult => {
  const validRows: OpeningBalanceImportRow[] = [];
  const errors: OpeningBalanceImportError[] = [];

  rows.slice(1).forEach((raw, index) => {
    const rowNumber = index + 2;
    if (!raw.some(value => text(value))) return;

    const kind = text(raw[0]).toLowerCase();
    const accountCode = text(raw[1]);
    const debit = decimal(raw[6], 2);
    const credit = decimal(raw[7], 2);
    const rowErrors: string[] = [];

    if (!['account', 'party', 'inventory'].includes(kind)) rowErrors.push('Loại dòng không hợp lệ.');
    if (!accountCode || !references.accountCodes.has(accountCode)) rowErrors.push(`Tài khoản ${accountCode || '(trống)'} không tồn tại.`);
    if (!debit) rowErrors.push('Dư Nợ không hợp lệ.');
    if (!credit) rowErrors.push('Dư Có không hợp lệ.');

    if (kind === 'party') {
      const partyCode = text(raw[2]);
      const partyType = text(raw[3]).toLowerCase();
      if (!partyCode || !references.partyCodes.has(partyCode)) rowErrors.push(`Mã đối tượng ${partyCode || '(trống)'} không tồn tại.`);
      if (!['customer', 'supplier', 'employee'].includes(partyType)) rowErrors.push('Loại đối tượng không hợp lệ.');
      if (rowErrors.length === 0) validRows.push({ kind: 'party', account_code: accountCode, party_code: partyCode, party_type: partyType as 'customer' | 'supplier' | 'employee', debit_amount: debit!, credit_amount: credit! });
    } else if (kind === 'inventory') {
      const itemCode = text(raw[4]);
      const warehouseCode = text(raw[5]);
      const quantity = decimal(raw[8], 4);
      const unitCost = decimal(raw[9], 4);
      if (!itemCode || !references.itemCodes.has(itemCode)) rowErrors.push(`Mã hàng ${itemCode || '(trống)'} không tồn tại.`);
      if (!warehouseCode || !references.warehouseCodes.has(warehouseCode)) rowErrors.push(`Mã kho ${warehouseCode || '(trống)'} không tồn tại.`);
      if (!quantity) rowErrors.push('Số lượng không hợp lệ.');
      if (!unitCost) rowErrors.push('Đơn giá không hợp lệ.');
      if (rowErrors.length === 0) validRows.push({ kind: 'inventory', account_code: accountCode, item_code: itemCode, warehouse_code: warehouseCode, debit_amount: debit!, credit_amount: credit!, quantity: quantity!, unit_cost: unitCost! });
    } else if (rowErrors.length === 0) {
      validRows.push({ kind: 'account', account_code: accountCode, debit_amount: debit!, credit_amount: credit! });
    }

    if (rowErrors.length > 0) errors.push({ row: rowNumber, message: rowErrors.join(' ') });
  });

  const totals = summarizeOpeningBalanceLines(validRows);
  const inventoryValue = summarizeOpeningBalanceLines(validRows.filter(row => row.kind === 'inventory').map(row => ({ debit_amount: multiplyOpeningBalanceValue(row.quantity, row.unit_cost), credit_amount: '0.00' })));
  return { validRows, errors, totals: { debit: totals.totalDebit, credit: totals.totalCredit, inventory_value: inventoryValue.totalDebit } };
};

export const openingBalanceRowsForExport = (rows: OpeningBalanceImportRow[]): unknown[][] => [
  [...OPENING_BALANCE_COLUMNS],
  ...rows.map(row => row.kind === 'account'
    ? [row.kind, row.account_code, '', '', '', '', row.debit_amount, row.credit_amount, '', '']
    : row.kind === 'party'
      ? [row.kind, row.account_code, row.party_code, row.party_type, '', '', row.debit_amount, row.credit_amount, '', '']
      : [row.kind, row.account_code, '', '', row.item_code, row.warehouse_code, row.debit_amount, row.credit_amount, row.quantity, row.unit_cost]),
];

export const exportOpeningBalanceWorkbook = (rows: OpeningBalanceImportRow[], filename = 'So_du_dau_ky.xlsx') => {
  const sheet = XLSX.utils.aoa_to_sheet(openingBalanceRowsForExport(rows));
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, sheet, 'So du dau ky');
  XLSX.writeFile(workbook, filename);
};
