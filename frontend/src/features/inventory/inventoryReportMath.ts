type QuantityInput = string | number | null | undefined;

type ParsedQuantity = {
  digits: bigint;
  scale: number;
  negative: boolean;
};

const TARGET_SCALE = 4;

const parseQuantity = (value: QuantityInput): ParsedQuantity | null => {
  if (value === null || value === undefined || value === '') return null;
  const raw = typeof value === 'number' ? value.toString() : value.trim();
  const match = raw.match(/^([+-]?)(\d+)(?:\.(\d+))?$/);
  if (!match) return null;
  const fraction = match[3] ?? '';
  return { digits: BigInt(`${match[2]}${fraction}`), scale: fraction.length, negative: match[1] === '-' };
};

const formatQuantity = (value: bigint): string => {
  const negative = value < 0n;
  const absolute = negative ? -value : value;
  const unit = 10n ** BigInt(TARGET_SCALE);
  const integer = absolute / unit;
  const fraction = (absolute % unit).toString().padStart(TARGET_SCALE, '0');
  return `${negative ? '-' : ''}${integer}.${fraction}`;
};

/** Adds report quantities exactly, returning the server's four-decimal scale. */
export function addDecimalQuantity(...values: QuantityInput[]): string {
  const parsed = values.map(parseQuantity).filter((value): value is ParsedQuantity => value !== null);
  if (parsed.length === 0) return '0.0000';

  const scale = Math.max(...parsed.map(value => value.scale));
  const signedTotal = parsed.reduce((total, value) => {
    const magnitude = value.digits * 10n ** BigInt(scale - value.scale);
    return total + (value.negative ? -magnitude : magnitude);
  }, 0n);

  const negative = signedTotal < 0n;
  const absolute = negative ? -signedTotal : signedTotal;
  let targetUnits: bigint;
  if (scale <= TARGET_SCALE) {
    targetUnits = absolute * 10n ** BigInt(TARGET_SCALE - scale);
  } else {
    const divisor = 10n ** BigInt(scale - TARGET_SCALE);
    const quotient = absolute / divisor;
    const remainder = absolute % divisor;
    targetUnits = quotient + (remainder * 2n >= divisor ? 1n : 0n);
  }

  return formatQuantity(negative ? -targetUnits : targetUnits);
}
