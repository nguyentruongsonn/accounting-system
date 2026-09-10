import {
  compareDecimalMoney,
  normalizeDecimalMoney,
  subtractDecimalMoney,
  type DecimalInput,
} from '../../utils/decimalMoney';

export type PersistedJournalEntryLine = {
  account_code?: unknown;
  description?: unknown;
  debit_amount?: DecimalInput;
  credit_amount?: DecimalInput;
  debit_amount_decimal?: DecimalInput;
  credit_amount_decimal?: DecimalInput;
};

export type JournalEntryFormLine = {
  debit_account: string;
  credit_account: string;
  amount: string;
  description: string;
  invalid_reason?: string;
};

type LedgerSide = {
  account: string;
  description: string;
  remaining: string;
};

const sideAmount = (line: PersistedJournalEntryLine, side: 'debit' | 'credit') => normalizeDecimalMoney(
  side === 'debit'
    ? line.debit_amount_decimal ?? line.debit_amount
    : line.credit_amount_decimal ?? line.credit_amount,
);

const descriptionFor = (line: PersistedJournalEntryLine) => typeof line.description === 'string' ? line.description : '';
const accountFor = (line: PersistedJournalEntryLine) => typeof line.account_code === 'string' ? line.account_code : '';

const isPositive = (amount: string) => compareDecimalMoney(amount, '0') > 0;
const isNegative = (amount: string) => compareDecimalMoney(amount, '0') < 0;

const invalidRows = (lines: readonly PersistedJournalEntryLine[]): JournalEntryFormLine[] => lines.flatMap((line) => {
  const debit = sideAmount(line, 'debit');
  const credit = sideAmount(line, 'credit');
  const description = descriptionFor(line);
  const account = accountFor(line);
  const debitPositive = isPositive(debit);
  const creditPositive = isPositive(credit);

  if (isNegative(debit) || isNegative(credit)) {
    return [
      ...(compareDecimalMoney(debit, '0') !== 0
        ? [{ debit_account: account, credit_account: '', amount: debit, description, invalid_reason: 'Số tiền Nợ/Có đã lưu không hợp lệ.' }]
        : []),
      ...(compareDecimalMoney(credit, '0') !== 0
        ? [{ debit_account: '', credit_account: account, amount: credit, description, invalid_reason: 'Số tiền Nợ/Có đã lưu không hợp lệ.' }]
        : []),
    ];
  }

  if (debitPositive && creditPositive) {
    return [
      { debit_account: account, credit_account: '', amount: debit, description, invalid_reason: 'Dòng hạch toán đã lưu đồng thời có Nợ và Có.' },
      { debit_account: '', credit_account: account, amount: credit, description, invalid_reason: 'Dòng hạch toán đã lưu đồng thời có Nợ và Có.' },
    ];
  }
  if (!debitPositive && !creditPositive) {
    const preserveDebitSide = compareDecimalMoney(debit, '0') !== 0 || compareDecimalMoney(credit, '0') === 0;
    return [preserveDebitSide
      ? { debit_account: account, credit_account: '', amount: debit, description, invalid_reason: 'Số tiền Nợ/Có đã lưu không hợp lệ.' }
      : { debit_account: '', credit_account: account, amount: credit, description, invalid_reason: 'Số tiền Nợ/Có đã lưu không hợp lệ.' },
    ];
  }
  return [];
});

const validSideLines = (lines: readonly PersistedJournalEntryLine[]) => lines.filter((line) => {
  const debit = sideAmount(line, 'debit');
  const credit = sideAmount(line, 'credit');
  const debitPositive = isPositive(debit);
  const creditPositive = isPositive(credit);
  return !isNegative(debit) && !isNegative(credit) && debitPositive !== creditPositive;
});

const sideRows = (lines: readonly PersistedJournalEntryLine[], side: 'debit' | 'credit'): LedgerSide[] => lines.flatMap((line) => {
  const amount = sideAmount(line, side);
  return compareDecimalMoney(amount, '0') > 0
    ? [{ account: accountFor(line), description: descriptionFor(line), remaining: amount }]
    : [];
});

/**
 * The API returns one row per ledger side, while the draft editor submits a
 * debit/credit pair. Allocate each persisted debit against the next credit so
 * the editor preserves both totals without duplicating the displayed amount.
 * A malformed unpaired side is retained with an empty counterpart; existing
 * required-account validation then blocks submission instead of inventing one.
 */
export const toJournalEntryFormLines = (lines: readonly PersistedJournalEntryLine[]): JournalEntryFormLine[] => {
  const validLines = validSideLines(lines);
  const debits = sideRows(validLines, 'debit');
  const credits = sideRows(validLines, 'credit');
  const formLines: JournalEntryFormLine[] = invalidRows(lines);
  let debitIndex = 0;
  let creditIndex = 0;

  while (debitIndex < debits.length && creditIndex < credits.length) {
    const debit = debits[debitIndex];
    const credit = credits[creditIndex];
    const amount = compareDecimalMoney(debit.remaining, credit.remaining) <= 0
      ? debit.remaining
      : credit.remaining;

    formLines.push({
      debit_account: debit.account,
      credit_account: credit.account,
      amount,
      description: debit.description || credit.description,
    });

    debit.remaining = subtractDecimalMoney(debit.remaining, amount);
    credit.remaining = subtractDecimalMoney(credit.remaining, amount);
    if (compareDecimalMoney(debit.remaining, '0') === 0) debitIndex += 1;
    if (compareDecimalMoney(credit.remaining, '0') === 0) creditIndex += 1;
  }

  for (; debitIndex < debits.length; debitIndex += 1) {
    const debit = debits[debitIndex];
    formLines.push({ debit_account: debit.account, credit_account: '', amount: debit.remaining, description: debit.description });
  }
  for (; creditIndex < credits.length; creditIndex += 1) {
    const credit = credits[creditIndex];
    formLines.push({ debit_account: '', credit_account: credit.account, amount: credit.remaining, description: credit.description });
  }

  return formLines;
};
