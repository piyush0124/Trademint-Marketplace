import type { QuoteItemInput, QuoteItemType } from '../../../types/api';
import { formatMoney, previewLine } from '../../../lib/money';

/**
 * The line-item editor at the heart of the quote builder.
 *
 * Generic by design: a line is a description, a type, a quantity, a unit and a
 * price. That single shape covers "500 kg of steel", "3 months of digital
 * marketing", "warehouse construction" and "legal consultation" — there are no
 * category-specific fields, and no industry branching.
 *
 * The amounts shown per line are a live preview computed the same way the
 * backend computes them; the saved figures always come back from the API.
 */

const ITEM_TYPES: { value: QuoteItemType; label: string; hint: string }[] = [
  { value: 'product', label: 'Product', hint: 'A countable good' },
  { value: 'service', label: 'Service', hint: 'A repeatable service' },
  { value: 'project', label: 'Project', hint: 'Scoped work, priced as a whole' },
  { value: 'custom', label: 'Custom', hint: 'Fees, materials, anything else' },
];

interface QuoteItemsEditorProps {
  items: QuoteItemInput[];
  currency: string;
  disabled?: boolean;
  onChange: (items: QuoteItemInput[]) => void;
}

export function emptyQuoteItem(): QuoteItemInput {
  return {
    description: '',
    item_type: 'product',
    quantity: '1',
    unit: '',
    unit_price: '',
    tax_rate: '',
    discount_amount: '',
  };
}

export default function QuoteItemsEditor({ items, currency, disabled = false, onChange }: QuoteItemsEditorProps) {
  const update = (index: number, patch: Partial<QuoteItemInput>) => {
    onChange(items.map((item, i) => (i === index ? { ...item, ...patch } : item)));
  };

  const remove = (index: number) => {
    onChange(items.filter((_, i) => i !== index));
  };

  return (
    <div>
      <div className="d-flex justify-content-between align-items-center mb-2">
        <label className="form-label mb-0 fw-semibold">Line items</label>
        <button
          type="button"
          className="btn btn-sm btn-mp-outline"
          disabled={disabled}
          onClick={() => onChange([...items, emptyQuoteItem()])}
        >
          + Add line
        </button>
      </div>

      {items.length === 0 && (
        <div className="alert alert-warning py-2 small">Add at least one line item before saving.</div>
      )}

      <div className="d-flex flex-column gap-3">
        {items.map((item, index) => {
          const totals = previewLine(item);
          const discountTooLarge = Number(item.discount_amount || 0) > Number(totals.subtotal);

          return (
            <div key={index} className="mp-quote-line">
              <div className="row g-2">
                <div className="col-12 col-lg-5">
                  <label className="form-label small" htmlFor={`item-desc-${index}`}>Description</label>
                  <input
                    id={`item-desc-${index}`}
                    className="form-control form-control-sm"
                    value={item.description}
                    maxLength={500}
                    disabled={disabled}
                    placeholder="e.g. Supply of 500 units, or Website development"
                    onChange={(e) => update(index, { description: e.target.value })}
                  />
                </div>

                <div className="col-6 col-lg-3">
                  <label className="form-label small" htmlFor={`item-type-${index}`}>Type</label>
                  <select
                    id={`item-type-${index}`}
                    className="form-select form-select-sm"
                    value={item.item_type}
                    disabled={disabled}
                    onChange={(e) => update(index, { item_type: e.target.value as QuoteItemType })}
                  >
                    {ITEM_TYPES.map((type) => (
                      <option key={type.value} value={type.value}>{type.label}</option>
                    ))}
                  </select>
                </div>

                <div className="col-6 col-lg-2">
                  <label className="form-label small" htmlFor={`item-qty-${index}`}>Quantity</label>
                  <input
                    id={`item-qty-${index}`}
                    type="number"
                    min="0.001"
                    step="0.001"
                    className="form-control form-control-sm"
                    value={item.quantity}
                    disabled={disabled}
                    onChange={(e) => update(index, { quantity: e.target.value })}
                  />
                </div>

                <div className="col-6 col-lg-2">
                  <label className="form-label small" htmlFor={`item-unit-${index}`}>Unit</label>
                  <input
                    id={`item-unit-${index}`}
                    className="form-control form-control-sm"
                    value={item.unit ?? ''}
                    maxLength={30}
                    disabled={disabled}
                    placeholder="kg, hour, month"
                    onChange={(e) => update(index, { unit: e.target.value })}
                  />
                </div>

                <div className="col-6 col-lg-3">
                  <label className="form-label small" htmlFor={`item-price-${index}`}>Unit price</label>
                  <input
                    id={`item-price-${index}`}
                    type="number"
                    min="0"
                    step="0.01"
                    className="form-control form-control-sm"
                    value={item.unit_price}
                    disabled={disabled}
                    onChange={(e) => update(index, { unit_price: e.target.value })}
                  />
                </div>

                <div className="col-6 col-lg-2">
                  <label className="form-label small" htmlFor={`item-tax-${index}`}>Tax %</label>
                  <input
                    id={`item-tax-${index}`}
                    type="number"
                    min="0"
                    max="100"
                    step="0.01"
                    className="form-control form-control-sm"
                    value={item.tax_rate ?? ''}
                    disabled={disabled}
                    onChange={(e) => update(index, { tax_rate: e.target.value })}
                  />
                </div>

                <div className="col-6 col-lg-3">
                  <label className="form-label small" htmlFor={`item-discount-${index}`}>Line discount</label>
                  <input
                    id={`item-discount-${index}`}
                    type="number"
                    min="0"
                    step="0.01"
                    className={`form-control form-control-sm ${discountTooLarge ? 'is-invalid' : ''}`}
                    value={item.discount_amount ?? ''}
                    disabled={disabled}
                    onChange={(e) => update(index, { discount_amount: e.target.value })}
                  />
                  {discountTooLarge && (
                    <div className="invalid-feedback d-block small">Cannot exceed the line value.</div>
                  )}
                </div>

                <div className="col-12 col-lg-4 d-flex align-items-end justify-content-between gap-2">
                  <div className="small text-muted">
                    <div>Line: {formatMoney(totals.subtotal, currency)}</div>
                    <div>Tax: {formatMoney(totals.tax, currency)}</div>
                    <div className="fw-semibold text-dark">Total: {formatMoney(totals.total, currency)}</div>
                  </div>
                  <button
                    type="button"
                    className="btn btn-sm btn-mp-ghost text-danger"
                    disabled={disabled || items.length === 1}
                    onClick={() => remove(index)}
                    aria-label={`Remove line ${index + 1}`}
                  >
                    Remove
                  </button>
                </div>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
