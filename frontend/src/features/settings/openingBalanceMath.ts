type OpeningBalanceDecimal = string | null | undefined;

type ParsedDecimal = {
  digits: bigint;
  scale: number;
  negative: boolean;
};

const parseDecimal = (value: OpeningBalanceDecimal): ParsedDecimal | null => {
  if (value === null || value === undefined || value.trim() === '') return null;

  const match = value.trim().match(/^([+-]?)(\d+)(?:\.(\d{0,4}))?$/);
  if (!match) return null;

  const fraction = match[3] ?? '';
  return {
    digits: BigInt(`${match[2]}${fraction}`),
    scale: fraction.length,
    negative: match[1] === '-',
  };
};

const formatMoneyMinorUnits = (value: bigint): string => {
  const negative = value < 0n;
  const absolute = negative ? -value : value;
  const integer = absolute / 100n;
  const fraction = (absolute % 100n).toString().padStart(2, '0');
  return `${negative ? '-' : ''}${integer}.${fraction}`;
};

/**
 * Multiplies quantity and unit cost using integer arithmetic and rounds the
 * result to the two-decimal money scale expected by the opening-balance API.
 * Invalid/partial input returns zero so an editable row never crashes while
 * the user is typing; the server remains the final validation authority.
 */
export function multiplyOpeningBalanceValue(quantity: OpeningBalanceDecimal, unitCost: OpeningBalanceDecimal): string {
  const left = parseDecimal(quantity);
  const right = parseDecimal(unitCost);
  if (!left || !right) return '0.00';

  const negative = left.negative !== right.negative;
  const product = left.digits * right.digits;
  const scale = left.scale + right.scale;
  const moneyScale = 2;
  let minorUnits: bigint;

  if (scale <= moneyScale) {
    minorUnits = product * 10n ** BigInt(moneyScale - scale);
  } else {
    const divisor = 10n ** BigInt(scale - moneyScale);
    const quotient = product / divisor;
    const remainder = product % divisor;
    minorUnits = quotient + (remainder * 2n >= divisor ? 1n : 0n);
  }

  return formatMoneyMinorUnits(negative ? -minorUnits : minorUnits);
}

export function summarizeOpeningBalanceLines(lines: Array<{ debit_amount?: OpeningBalanceDecimal; credit_amount?: OpeningBalanceDecimal }>): { totalDebit: string; totalCredit: string; balanced: boolean } {
  const sum = (key: 'debit_amount' | 'credit_amount') => lines.reduce((total, line) => {
    const value = parseDecimal(line[key]);
    if (!value) return total;
    const scaled = value.scale <= 2
      ? value.digits * 10n ** BigInt(2 - value.scale)
      : (() => {
          const divisor = 10n ** BigInt(value.scale - 2);
          const quotient = value.digits / divisor;
          return quotient + (value.digits % divisor * 2n >= divisor ? 1n : 0n);
        })();
    return total + (value.negative ? -scaled : scaled);
  }, 0n);
  const totalDebit = formatMoneyMinorUnits(sum('debit_amount'));
  const totalCredit = formatMoneyMinorUnits(sum('credit_amount'));
  return { totalDebit, totalCredit, balanced: totalDebit === totalCredit };
}
