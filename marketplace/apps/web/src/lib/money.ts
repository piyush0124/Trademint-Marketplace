/**
 * Money helpers for the quote builder.
 *
 * These exist for one purpose: showing the user a live preview as they type.
 * They are NOT the source of truth. Every figure that gets stored is calculated
 * by the backend (App\Services\Quotes\QuoteCalculator) from the line inputs, and
 * the API ignores any total sent from here.
 *
 * The arithmetic still runs in integer minor units, mirroring the backend, so
 * the preview matches the saved quote to the paisa instead of drifting through
 * JavaScript floating point.
 */

const MONEY_SCALE = 2;
const QUANTITY_SCALE = 3;
const RATE_SCALE = 2;

/** Parse a decimal string into integer minor units, rounding half-up. */
export function toMinor(value: string | number | null | undefined, scale = MONEY_SCALE): number {
  if (value === null || value === undefined || value === '') return 0;

  const text = typeof value === 'number' ? value.toFixed(scale + 1) : value.trim();
  if (!/^[+-]?\d*(\.\d+)?$/.test(text)) return 0;

  const negative = text.startsWith('-');
  const unsigned = text.replace(/^[+-]/, '');
  const [whole = '0', fraction = ''] = unsigned.split('.');

  const kept = fraction.slice(0, scale).padEnd(scale, '0');
  let minor = Number(whole || '0') * 10 ** scale + Number(kept || '0');

  if (Number(fraction.charAt(scale) || '0') >= 5) minor += 1;

  return negative ? -minor : minor;
}

/** Render integer minor units back as a fixed-point decimal string. */
export function fromMinor(minor: number, scale = MONEY_SCALE): string {
  const divisor = 10 ** scale;
  const sign = minor < 0 ? '-' : '';
  const absolute = Math.abs(minor);

  return `${sign}${Math.floor(absolute / divisor)}.${String(absolute % divisor).padStart(scale, '0')}`;
}

function divideRoundHalfUp(numerator: number, denominator: number): number {
  const negative = numerator < 0 !== denominator < 0;
  const absNumerator = Math.abs(numerator);
  const absDenominator = Math.abs(denominator);

  let quotient = Math.floor(absNumerator / absDenominator);
  const remainder = absNumerator % absDenominator;
  if (remainder * 2 >= absDenominator) quotient += 1;

  return negative ? -quotient : quotient;
}

/** Format an amount for display. Currency comes from the quote, never hardcoded. */
export function formatMoney(amount: string | number | null | undefined, currency: string): string {
  const value = typeof amount === 'string' ? Number(amount) : (amount ?? 0);
  const safe = Number.isFinite(value) ? value : 0;

  try {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 2 }).format(safe);
  } catch {
    // An unknown currency code should degrade, not throw.
    return `${currency} ${safe.toFixed(2)}`;
  }
}

export interface LineInput {
  quantity: string;
  unit_price: string;
  tax_rate?: string;
  discount_amount?: string;
}

export interface LineTotals {
  subtotal: string;
  discount: string;
  tax: string;
  total: string;
}

/** One line's figures, using the same rules as the backend. */
export function previewLine(line: LineInput): LineTotals {
  const unitPriceMinor = Math.max(0, toMinor(line.unit_price));
  const quantityScaled = Math.max(0, toMinor(line.quantity, QUANTITY_SCALE));
  const grossMinor = divideRoundHalfUp(unitPriceMinor * quantityScaled, 10 ** QUANTITY_SCALE);

  const requestedDiscount = Math.max(0, toMinor(line.discount_amount));
  // Clamped rather than errored: the builder shows a warning of its own, and a
  // preview should never display a negative line.
  const discountMinor = Math.min(requestedDiscount, grossMinor);

  const taxableMinor = grossMinor - discountMinor;
  const taxMinor = divideRoundHalfUp(taxableMinor * Math.max(0, toMinor(line.tax_rate, RATE_SCALE)), 100 * 10 ** RATE_SCALE);

  return {
    subtotal: fromMinor(grossMinor),
    discount: fromMinor(discountMinor),
    tax: fromMinor(taxMinor),
    total: fromMinor(taxableMinor + taxMinor),
  };
}

export interface QuoteTotalsPreview {
  grossSubtotal: string;
  itemDiscount: string;
  subtotal: string;
  tax: string;
  deliveryFee: string;
  otherCharges: string;
  quoteDiscount: string;
  total: string;
  /** True when the quote-level discount exceeds what is owed — backend rejects it. */
  discountExceedsTotal: boolean;
}

/** Whole-quote preview: mirrors QuoteCalculator's ordering exactly. */
export function previewQuoteTotals(
  lines: LineInput[],
  charges: { delivery_fee?: string; other_charges?: string; discount_amount?: string } = {}
): QuoteTotalsPreview {
  let grossMinor = 0;
  let itemDiscountMinor = 0;
  let subtotalMinor = 0;
  let taxMinor = 0;

  lines.forEach((line) => {
    const totals = previewLine(line);
    grossMinor += toMinor(totals.subtotal);
    itemDiscountMinor += toMinor(totals.discount);
    subtotalMinor += toMinor(totals.subtotal) - toMinor(totals.discount);
    taxMinor += toMinor(totals.tax);
  });

  const deliveryMinor = Math.max(0, toMinor(charges.delivery_fee));
  const otherMinor = Math.max(0, toMinor(charges.other_charges));
  const quoteDiscountMinor = Math.max(0, toMinor(charges.discount_amount));

  const beforeDiscount = subtotalMinor + taxMinor + deliveryMinor + otherMinor;
  const discountExceedsTotal = quoteDiscountMinor > beforeDiscount;
  const totalMinor = Math.max(0, beforeDiscount - quoteDiscountMinor);

  return {
    grossSubtotal: fromMinor(grossMinor),
    itemDiscount: fromMinor(itemDiscountMinor),
    subtotal: fromMinor(subtotalMinor),
    tax: fromMinor(taxMinor),
    deliveryFee: fromMinor(deliveryMinor),
    otherCharges: fromMinor(otherMinor),
    quoteDiscount: fromMinor(quoteDiscountMinor),
    total: fromMinor(totalMinor),
    discountExceedsTotal,
  };
}
