export type DecimalInput = string | number | null | undefined;

const SCALE = 2;
const FACTOR = 100n;

function toMinorUnits(value: DecimalInput): bigint {
    if (value === null || value === undefined || value === '') return 0n;
    const raw = typeof value === 'number' ? value.toFixed(SCALE) : value.trim();
    const match = raw.match(/^([+-]?)(\d+)(?:\.(\d*))?$/);
    if (!match) throw new Error(`Invalid decimal money value: ${raw}`);

    const fraction = match[3] ?? '';
    if (fraction.length > SCALE && /[1-9]/.test(fraction.slice(SCALE))) {
        throw new Error(`Money value exceeds scale ${SCALE}: ${raw}`);
    }

    const units = BigInt(match[2]) * FACTOR + BigInt((fraction.slice(0, SCALE) + '00').slice(0, SCALE));
    return match[1] === '-' ? -units : units;
}

function fromMinorUnits(value: bigint): string {
    const negative = value < 0n;
    const absolute = negative ? -value : value;
    const integer = absolute / FACTOR;
    const fraction = (absolute % FACTOR).toString().padStart(SCALE, '0');
    return `${negative ? '-' : ''}${integer}.${fraction}`;
}

export function normalizeDecimalMoney(value: DecimalInput): string {
    return fromMinorUnits(toMinorUnits(value));
}

export function addDecimalMoney(...values: DecimalInput[]): string {
    return fromMinorUnits(values.reduce<bigint>((total, value) => total + toMinorUnits(value), 0n));
}

export function subtractDecimalMoney(left: DecimalInput, right: DecimalInput): string {
    return fromMinorUnits(toMinorUnits(left) - toMinorUnits(right));
}

export function compareDecimalMoney(left: DecimalInput, right: DecimalInput): number {
    const difference = toMinorUnits(left) - toMinorUnits(right);
    return difference < 0n ? -1 : difference > 0n ? 1 : 0;
}

export function absDecimalMoney(value: DecimalInput): string {
    const units = toMinorUnits(value);
    return fromMinorUnits(units < 0n ? -units : units);
}

export function decimalRatio(numerator: DecimalInput, denominator: DecimalInput, precision = 2): string | null {
    const divisor = toMinorUnits(denominator);
    if (divisor === 0n) return null;

    const dividend = toMinorUnits(numerator);
    const ratioScale = 10n ** BigInt(precision);
    const negative = (dividend < 0n) !== (divisor < 0n);
    const absoluteDividend = dividend < 0n ? -dividend : dividend;
    const absoluteDivisor = divisor < 0n ? -divisor : divisor;
    const scaled = absoluteDividend * ratioScale;
    const rounded = (scaled + absoluteDivisor / 2n) / absoluteDivisor;
    const digits = rounded.toString().padStart(precision + 1, '0');
    const integer = precision === 0 ? digits : digits.slice(0, -precision);
    const fraction = precision === 0 ? '' : `.${digits.slice(-precision)}`;
    return `${negative ? '-' : ''}${integer}${fraction}`;
}

export function formatDecimalMoney(
    value: DecimalInput,
    options: { currency?: boolean; emptyZero?: boolean } = {},
): string {
    const canonical = normalizeDecimalMoney(value);
    if (options.emptyZero && canonical === '0.00') return '';

    const negative = canonical.startsWith('-');
    const unsigned = negative ? canonical.slice(1) : canonical;
    const [integer, fraction] = unsigned.split('.');
    const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const visibleFraction = fraction === '00' ? '' : `,${fraction.replace(/0$/, '')}`;
    const formatted = `${negative ? '-' : ''}${grouped}${visibleFraction}`;
    return options.currency ? `${formatted}\u00a0₫` : formatted;
}

/**
 * Keeps an InputNumber in stringMode from coercing a DECIMAL value through
 * JavaScript Number. This is deliberately less strict than
 * normalizeDecimalMoney: the user may still be typing a partial value.
 */
export function parseDecimalMoneyInput(value: string | undefined): string {
    return (value ?? '').replace(/,/g, '').trim();
}

export function formatDecimalMoneyInput(value: DecimalInput): string {
    if (value === null || value === undefined || value === '') return '';

    const raw = typeof value === 'number' ? value.toString() : value;
    const match = raw.match(/^([+-]?)(\d*)(?:\.(\d*))?$/);
    if (!match) return raw;

    const integer = match[2] || '0';
    const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return `${match[1]}${grouped}${raw.includes('.') ? `.${match[3] ?? ''}` : ''}`;
}
