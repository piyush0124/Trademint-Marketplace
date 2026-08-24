import type { ApiQuote } from '../../../types/api';
import { formatMoney } from '../../../lib/money';
import StatusBadge from '../../../components/ui/StatusBadge';

/**
 * The professional quote document, shared by the builder's preview step, the
 * business's own quote view and the customer's.
 *
 * Every figure rendered here comes from the API — this component never
 * calculates a total. That is what guarantees the customer sees exactly what the
 * backend computed.
 *
 * Presentation adapts to what the quote actually contains rather than to any
 * industry: a delivery date row appears only if there is one, a completion date
 * only if there is one, and tax/discount columns only when they are non-zero.
 */
interface QuotePreviewProps {
  quote: ApiQuote;
}

function isNonZero(amount: string | null | undefined): boolean {
  return amount !== null && amount !== undefined && Number(amount) !== 0;
}

export default function QuotePreview({ quote }: QuotePreviewProps) {
  const a = quote.attributes;
  const currency = a.currency;
  const items = quote.relationships.items ?? [];
  const business = quote.relationships.business;

  const showTax = items.some((item) => isNonZero(item.attributes.tax_amount)) || isNonZero(a.tax_amount);
  const showDiscount = items.some((item) => isNonZero(item.attributes.discount_amount));

  return (
    <div className="mp-quote-doc">
      <div className="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
          <div className="mp-eyebrow">Quotation</div>
          <h2 className="h4 mb-1">{a.title}</h2>
          <div className="text-muted small">{a.quote_number}</div>
        </div>
        <div className="text-lg-end">
          <StatusBadge status={a.status} />
          {business && (
            <div className="small text-muted mt-2">
              <div className="fw-semibold text-dark">{business.attributes.name}</div>
              {business.relationships?.location && (
                <div>
                  {[business.relationships.location.attributes.city, business.relationships.location.attributes.state]
                    .filter(Boolean)
                    .join(', ')}
                </div>
              )}
              {business.attributes.phone && <div>{business.attributes.phone}</div>}
            </div>
          )}
        </div>
      </div>

      {a.description && <p className="mb-4">{a.description}</p>}

      <div className="table-responsive mb-3">
        <table className="table table-sm mp-quote-table align-middle mb-0">
          <thead>
            <tr>
              <th scope="col" style={{ width: '40%' }}>Item</th>
              <th scope="col" className="text-end">Qty</th>
              <th scope="col" className="text-end">Unit price</th>
              {showDiscount && <th scope="col" className="text-end">Discount</th>}
              {showTax && <th scope="col" className="text-end">Tax</th>}
              <th scope="col" className="text-end">Total</th>
            </tr>
          </thead>
          <tbody>
            {items.map((item) => (
              <tr key={item.id}>
                <td>
                  <div className="fw-semibold">{item.attributes.description}</div>
                  <div className="small text-muted">{item.attributes.item_type_label}</div>
                </td>
                <td className="text-end">
                  {Number(item.attributes.quantity).toLocaleString('en-IN')}
                  {item.attributes.unit ? ` ${item.attributes.unit}` : ''}
                </td>
                <td className="text-end">{formatMoney(item.attributes.unit_price, currency)}</td>
                {showDiscount && <td className="text-end">{formatMoney(item.attributes.discount_amount, currency)}</td>}
                {showTax && (
                  <td className="text-end">
                    {formatMoney(item.attributes.tax_amount, currency)}
                    {isNonZero(item.attributes.tax_rate) && (
                      <div className="small text-muted">{Number(item.attributes.tax_rate)}%</div>
                    )}
                  </td>
                )}
                <td className="text-end fw-semibold">{formatMoney(item.attributes.total, currency)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="row justify-content-end">
        <div className="col-12 col-md-6">
          <dl className="mp-quote-totals mb-0">
            <div>
              <dt>Subtotal</dt>
              <dd>{formatMoney(a.subtotal, currency)}</dd>
            </div>
            {isNonZero(a.tax_amount) && (
              <div>
                <dt>Tax</dt>
                <dd>{formatMoney(a.tax_amount, currency)}</dd>
              </div>
            )}
            {isNonZero(a.delivery_fee) && (
              <div>
                <dt>Delivery</dt>
                <dd>{formatMoney(a.delivery_fee, currency)}</dd>
              </div>
            )}
            {isNonZero(a.other_charges) && (
              <div>
                <dt>Other charges</dt>
                <dd>{formatMoney(a.other_charges, currency)}</dd>
              </div>
            )}
            {isNonZero(a.discount_amount) && (
              <div>
                <dt>Discount</dt>
                <dd>- {formatMoney(a.discount_amount, currency)}</dd>
              </div>
            )}
            <div className="mp-quote-totals__grand">
              <dt>Total</dt>
              <dd>{formatMoney(a.total_amount, currency)}</dd>
            </div>
          </dl>
        </div>
      </div>

      <hr className="my-4" />

      <div className="row g-3 small">
        {a.validity_date && (
          <div className="col-6 col-md-4">
            <div className="text-muted">Valid until</div>
            <div className="fw-semibold">{a.validity_date}</div>
          </div>
        )}
        {/* A product quote usually has a delivery date; a service or project has
            a completion date. Neither is assumed. */}
        {a.estimated_delivery_date && (
          <div className="col-6 col-md-4">
            <div className="text-muted">Estimated delivery</div>
            <div className="fw-semibold">{a.estimated_delivery_date}</div>
          </div>
        )}
        {a.estimated_completion_date && (
          <div className="col-6 col-md-4">
            <div className="text-muted">Estimated completion</div>
            <div className="fw-semibold">{a.estimated_completion_date}</div>
          </div>
        )}
      </div>

      {(a.payment_terms || a.warranty_terms || a.cancellation_terms || a.notes) && (
        <div className="row g-3 mt-3 small">
          {a.payment_terms && (
            <div className="col-12 col-md-6">
              <div className="text-muted fw-semibold">Payment terms</div>
              <div>{a.payment_terms}</div>
            </div>
          )}
          {a.warranty_terms && (
            <div className="col-12 col-md-6">
              <div className="text-muted fw-semibold">Warranty</div>
              <div>{a.warranty_terms}</div>
            </div>
          )}
          {a.cancellation_terms && (
            <div className="col-12 col-md-6">
              <div className="text-muted fw-semibold">Cancellation</div>
              <div>{a.cancellation_terms}</div>
            </div>
          )}
          {a.notes && (
            <div className="col-12 col-md-6">
              <div className="text-muted fw-semibold">Notes</div>
              <div>{a.notes}</div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
