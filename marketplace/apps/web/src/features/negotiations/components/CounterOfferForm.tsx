import { useMemo, useState } from 'react';
import type { ApiQuoteRevision, QuoteItemInput } from '../../../types/api';
import { formatMoney, previewQuoteTotals } from '../../../lib/money';
import QuoteItemsEditor from '../../quotes/components/QuoteItemsEditor';

/**
 * Counter offer form, used identically by both parties.
 *
 * It opens pre-filled with the offer being responded to, so a counter is an edit
 * of concrete terms rather than a blank page — which is how negotiation actually
 * works ("same order, 850 a unit, two weeks later").
 *
 * The totals shown are a preview computed with the same rules as the backend; the
 * request carries only inputs, and the stored figures come back from the API.
 */
interface CounterOfferFormProps {
  revision: ApiQuoteRevision;
  submitting: boolean;
  onCancel: () => void;
  onSubmit: (payload: {
    revision_id: string;
    message: string | null;
    is_final_offer: boolean;
    validity_date: string | null;
    estimated_delivery_date: string | null;
    estimated_completion_date: string | null;
    payment_terms: string | null;
    warranty_terms: string | null;
    cancellation_terms: string | null;
    delivery_fee: string;
    other_charges: string;
    discount_amount: string;
    items: QuoteItemInput[];
  }) => void;
}

const orNull = (value: string): string | null => (value.trim() === '' ? null : value);

export default function CounterOfferForm({ revision, submitting, onCancel, onSubmit }: CounterOfferFormProps) {
  const currency = revision.attributes.currency;

  const [items, setItems] = useState<QuoteItemInput[]>(
    (revision.relationships.items ?? []).map((item) => ({
      description: item.attributes.description,
      item_type: item.attributes.item_type,
      reference_id: item.attributes.reference_id,
      quantity: item.attributes.quantity,
      unit: item.attributes.unit ?? '',
      unit_price: item.attributes.unit_price,
      tax_rate: Number(item.attributes.tax_rate) ? item.attributes.tax_rate : '',
      discount_amount: Number(item.attributes.discount_amount) ? item.attributes.discount_amount : '',
    }))
  );

  const [message, setMessage] = useState('');
  const [isFinal, setIsFinal] = useState(false);
  const [deliveryFee, setDeliveryFee] = useState(
    Number(revision.attributes.delivery_fee) ? revision.attributes.delivery_fee : ''
  );
  const [otherCharges, setOtherCharges] = useState(
    Number(revision.attributes.other_charges) ? revision.attributes.other_charges : ''
  );
  const [discount, setDiscount] = useState('');
  const [validity, setValidity] = useState(revision.attributes.validity_date ?? '');
  const [deliveryDate, setDeliveryDate] = useState(revision.attributes.estimated_delivery_date ?? '');
  const [completionDate, setCompletionDate] = useState(revision.attributes.estimated_completion_date ?? '');
  const [paymentTerms, setPaymentTerms] = useState(revision.attributes.payment_terms ?? '');
  const [warrantyTerms, setWarrantyTerms] = useState(revision.attributes.warranty_terms ?? '');
  const [cancellationTerms, setCancellationTerms] = useState(revision.attributes.cancellation_terms ?? '');

  const totals = useMemo(
    () => previewQuoteTotals(items, { delivery_fee: deliveryFee, other_charges: otherCharges, discount_amount: discount }),
    [items, deliveryFee, otherCharges, discount]
  );

  const currentTotal = Number(revision.attributes.total_amount);
  const proposedTotal = Number(totals.total);
  const difference = proposedTotal - currentTotal;

  const submit = () => {
    onSubmit({
      revision_id: revision.id,
      message: orNull(message),
      is_final_offer: isFinal,
      validity_date: orNull(validity),
      estimated_delivery_date: orNull(deliveryDate),
      estimated_completion_date: orNull(completionDate),
      payment_terms: orNull(paymentTerms),
      warranty_terms: orNull(warrantyTerms),
      cancellation_terms: orNull(cancellationTerms),
      delivery_fee: deliveryFee === '' ? '0' : deliveryFee,
      other_charges: otherCharges === '' ? '0' : otherCharges,
      discount_amount: discount === '' ? '0' : discount,
      items: items.map((item, index) => ({ ...item, sort_order: index })),
    });
  };

  return (
    <form
      className="mp-card p-4"
      onSubmit={(event) => {
        event.preventDefault();
        if (!submitting) submit();
      }}
      aria-label="Counter offer"
    >
      <h3 className="h6 mb-1">Make a counter offer</h3>
      <p className="small text-muted">
        Adjust the terms below. The current offer stays in the history — nothing is overwritten.
      </p>

      <QuoteItemsEditor items={items} currency={currency} onChange={setItems} />

      <div className="row g-3 mt-3">
        <div className="col-6 col-md-4">
          <label className="form-label small" htmlFor="counter-delivery-fee">Delivery fee</label>
          <input id="counter-delivery-fee" type="number" min="0" step="0.01" className="form-control form-control-sm"
            value={deliveryFee} onChange={(e) => setDeliveryFee(e.target.value)} />
        </div>
        <div className="col-6 col-md-4">
          <label className="form-label small" htmlFor="counter-other-charges">Other charges</label>
          <input id="counter-other-charges" type="number" min="0" step="0.01" className="form-control form-control-sm"
            value={otherCharges} onChange={(e) => setOtherCharges(e.target.value)} />
        </div>
        <div className="col-6 col-md-4">
          <label className="form-label small" htmlFor="counter-discount">Discount</label>
          <input id="counter-discount" type="number" min="0" step="0.01"
            className={`form-control form-control-sm ${totals.discountExceedsTotal ? 'is-invalid' : ''}`}
            value={discount} onChange={(e) => setDiscount(e.target.value)} />
          {totals.discountExceedsTotal && <div className="invalid-feedback d-block small">Cannot exceed the total.</div>}
        </div>

        <div className="col-6 col-md-4">
          <label className="form-label small" htmlFor="counter-validity">Valid until</label>
          <input id="counter-validity" type="date" className="form-control form-control-sm"
            value={validity} onChange={(e) => setValidity(e.target.value)} />
        </div>
        <div className="col-6 col-md-4">
          <label className="form-label small" htmlFor="counter-delivery-date">Est. delivery</label>
          <input id="counter-delivery-date" type="date" className="form-control form-control-sm"
            value={deliveryDate} onChange={(e) => setDeliveryDate(e.target.value)} />
        </div>
        <div className="col-6 col-md-4">
          <label className="form-label small" htmlFor="counter-completion-date">Est. completion</label>
          <input id="counter-completion-date" type="date" className="form-control form-control-sm"
            value={completionDate} onChange={(e) => setCompletionDate(e.target.value)} />
        </div>

        <div className="col-12 col-md-4">
          <label className="form-label small" htmlFor="counter-payment-terms">Payment terms</label>
          <textarea id="counter-payment-terms" className="form-control form-control-sm" rows={2} maxLength={2000}
            value={paymentTerms} onChange={(e) => setPaymentTerms(e.target.value)} />
        </div>
        <div className="col-12 col-md-4">
          <label className="form-label small" htmlFor="counter-warranty-terms">Warranty</label>
          <textarea id="counter-warranty-terms" className="form-control form-control-sm" rows={2} maxLength={2000}
            value={warrantyTerms} onChange={(e) => setWarrantyTerms(e.target.value)} />
        </div>
        <div className="col-12 col-md-4">
          <label className="form-label small" htmlFor="counter-cancellation-terms">Cancellation</label>
          <textarea id="counter-cancellation-terms" className="form-control form-control-sm" rows={2} maxLength={2000}
            value={cancellationTerms} onChange={(e) => setCancellationTerms(e.target.value)} />
        </div>

        <div className="col-12">
          <label className="form-label small" htmlFor="counter-message">Message</label>
          <textarea id="counter-message" className="form-control form-control-sm" rows={2} maxLength={2000}
            value={message} onChange={(e) => setMessage(e.target.value)}
            placeholder="Explain your counter — it helps the other side say yes." />
        </div>
      </div>

      {/* The comparison that matters: what changes if this is accepted. */}
      <div className="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-3 p-3 mp-counter-summary">
        <div className="small">
          <div className="text-muted">Current offer</div>
          <div className="fw-semibold">{formatMoney(revision.attributes.total_amount, currency)}</div>
        </div>
        <div className="small" aria-hidden="true">→</div>
        <div className="small">
          <div className="text-muted">Your counter</div>
          <div className="fw-semibold">{formatMoney(totals.total, currency)}</div>
        </div>
        <div className="small">
          <div className="text-muted">Difference</div>
          <div className={`fw-semibold ${difference === 0 ? '' : difference < 0 ? 'text-success' : 'text-danger'}`}>
            {difference === 0 ? 'No change' : `${difference < 0 ? '−' : '+'} ${formatMoney(Math.abs(difference), currency)}`}
          </div>
        </div>
      </div>

      <div className="form-check mt-3">
        <input className="form-check-input" type="checkbox" id="counter-final" checked={isFinal}
          onChange={(e) => setIsFinal(e.target.checked)} />
        <label className="form-check-label small" htmlFor="counter-final">
          Mark this as my final offer
        </label>
      </div>

      <div className="d-flex gap-2 mt-3">
        <button type="submit" className="btn btn-mp-primary" disabled={submitting || items.length === 0}>
          {submitting ? 'Sending…' : 'Send counter offer'}
        </button>
        <button type="button" className="btn btn-mp-ghost" onClick={onCancel} disabled={submitting}>
          Cancel
        </button>
      </div>
    </form>
  );
}
