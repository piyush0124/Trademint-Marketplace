import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { api, getErrorMessage } from '../../../lib/api/client';
import { formatMoney, previewQuoteTotals } from '../../../lib/money';
import type { ApiEnquiry, ApiEnvelope, ApiQuote, QuoteInput, QuoteItemInput } from '../../../types/api';
import LoadingState from '../../../components/ui/LoadingState';
import ErrorState from '../../../components/ui/ErrorState';
import QuoteItemsEditor, { emptyQuoteItem } from '../components/QuoteItemsEditor';
import QuotePreview from '../components/QuotePreview';

/**
 * The business's quote builder. Handles both creating a new draft against an
 * enquiry (`?enquiry=<uuid>`) and editing an existing draft (`/:id/edit`).
 *
 * Two things worth knowing about the design:
 *
 *  1. The totals shown while editing are a *preview*. The builder posts only
 *     inputs — quantities, prices, rates, charges — and then renders the figures
 *     the API returns. It never sends a total, because the backend would ignore
 *     it anyway.
 *  2. It is entirely industry-neutral. The same form produces a 500-unit steel
 *     order, a monthly marketing retainer, a construction project or a one-off
 *     consultation; only the words the business types differ.
 */
type Mode = 'create' | 'edit';

interface FormState {
  title: string;
  description: string;
  currency: string;
  validity_date: string;
  estimated_delivery_date: string;
  estimated_completion_date: string;
  payment_terms: string;
  warranty_terms: string;
  cancellation_terms: string;
  notes: string;
  delivery_fee: string;
  other_charges: string;
  discount_amount: string;
}

const emptyForm = (currency: string): FormState => ({
  title: '',
  description: '',
  currency,
  validity_date: '',
  estimated_delivery_date: '',
  estimated_completion_date: '',
  payment_terms: '',
  warranty_terms: '',
  cancellation_terms: '',
  notes: '',
  delivery_fee: '',
  other_charges: '',
  discount_amount: '',
});

/** Empty strings must be sent as null, not "", so the API clears the field. */
const orNull = (value: string): string | null => (value.trim() === '' ? null : value);

export default function QuoteBuilderPage() {
  const { id } = useParams<{ id: string }>();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();

  const mode: Mode = id ? 'edit' : 'create';
  const enquiryUuid = searchParams.get('enquiry');

  const [form, setForm] = useState<FormState>(emptyForm('INR'));
  const [items, setItems] = useState<QuoteItemInput[]>([emptyQuoteItem()]);
  const [enquiry, setEnquiry] = useState<ApiEnquiry | null>(null);
  const [quote, setQuote] = useState<ApiQuote | null>(null);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const [showPreview, setShowPreview] = useState(false);

  const load = () => {
    setStatus('loading');
    setError('');

    if (mode === 'edit' && id) {
      api
        .get<ApiEnvelope<ApiQuote>>(`/quotes/${id}`)
        .then((res) => {
          const loaded = res.data.data;
          setQuote(loaded);
          setForm({
            title: loaded.attributes.title ?? '',
            description: loaded.attributes.description ?? '',
            currency: loaded.attributes.currency,
            validity_date: loaded.attributes.validity_date ?? '',
            estimated_delivery_date: loaded.attributes.estimated_delivery_date ?? '',
            estimated_completion_date: loaded.attributes.estimated_completion_date ?? '',
            payment_terms: loaded.attributes.payment_terms ?? '',
            warranty_terms: loaded.attributes.warranty_terms ?? '',
            cancellation_terms: loaded.attributes.cancellation_terms ?? '',
            notes: loaded.attributes.notes ?? '',
            delivery_fee: Number(loaded.attributes.delivery_fee) ? loaded.attributes.delivery_fee : '',
            other_charges: Number(loaded.attributes.other_charges) ? loaded.attributes.other_charges : '',
            // Deliberately blank: the stored discount includes line discounts, so
            // re-sending it would double-count. The business re-enters any
            // quote-level discount explicitly.
            discount_amount: '',
          });
          setItems(
            (loaded.relationships.items ?? []).map((item) => ({
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
          setEnquiry(loaded.relationships.enquiry ?? null);
          setStatus('ready');
        })
        .catch((err) => {
          setError(getErrorMessage(err));
          setStatus('error');
        });

      return;
    }

    if (!enquiryUuid) {
      setError('No enquiry selected. Open an accepted enquiry and choose "Create quote".');
      setStatus('error');

      return;
    }

    api
      .get<ApiEnvelope<ApiEnquiry>>(`/enquiries/${enquiryUuid}`)
      .then((res) => {
        setEnquiry(res.data.data);
        const requirement = res.data.data.relationships.requirement;
        setForm((current) => ({
          ...current,
          // A sensible starting title taken from the requirement, fully editable.
          title: requirement?.attributes.title ? `Quotation for ${requirement.attributes.title}` : '',
        }));
        if (requirement?.attributes.unit) {
          setItems([{ ...emptyQuoteItem(), unit: requirement.attributes.unit, quantity: requirement.attributes.quantity ?? '1' }]);
        }
        setStatus('ready');
      })
      .catch((err) => {
        setError(getErrorMessage(err));
        setStatus('error');
      });
  };

  useEffect(load, [id, enquiryUuid]);

  const totals = useMemo(() => previewQuoteTotals(items, form), [items, form]);

  const payload = (): QuoteInput => ({
    title: form.title,
    description: orNull(form.description),
    currency: form.currency,
    validity_date: orNull(form.validity_date),
    estimated_delivery_date: orNull(form.estimated_delivery_date),
    estimated_completion_date: orNull(form.estimated_completion_date),
    payment_terms: orNull(form.payment_terms),
    warranty_terms: orNull(form.warranty_terms),
    cancellation_terms: orNull(form.cancellation_terms),
    notes: orNull(form.notes),
    delivery_fee: form.delivery_fee === '' ? '0' : form.delivery_fee,
    other_charges: form.other_charges === '' ? '0' : form.other_charges,
    discount_amount: form.discount_amount === '' ? '0' : form.discount_amount,
    items: items.map((item, index) => ({
      description: item.description,
      item_type: item.item_type,
      reference_id: item.reference_id ?? null,
      quantity: item.quantity === '' ? '0' : item.quantity,
      unit: orNull(item.unit ?? ''),
      unit_price: item.unit_price === '' ? '0' : item.unit_price,
      tax_rate: item.tax_rate === '' ? '0' : item.tax_rate,
      discount_amount: item.discount_amount === '' ? '0' : item.discount_amount,
      sort_order: index,
    })),
  });

  const saveDraft = async (): Promise<ApiQuote | null> => {
    setSaving(true);
    setError('');

    try {
      if (mode === 'edit' && quote) {
        const res = await api.patch<ApiEnvelope<ApiQuote>>(`/quotes/${quote.id}`, payload());
        setQuote(res.data.data);

        return res.data.data;
      }

      const res = await api.post<ApiEnvelope<ApiQuote>>(`/enquiries/${enquiryUuid}/quotes`, payload());
      setQuote(res.data.data);
      // Move to the edit URL so a refresh does not create a second draft.
      navigate(`/dashboard/quotes/${res.data.data.id}/edit`, { replace: true });

      return res.data.data;
    } catch (err) {
      setError(getErrorMessage(err));

      return null;
    } finally {
      setSaving(false);
    }
  };

  const saveAndPreview = async () => {
    const saved = await saveDraft();
    if (saved) setShowPreview(true);
  };

  const send = async () => {
    // Always persist first: the quote that gets sent must be the one on screen.
    const saved = await saveDraft();
    if (!saved) return;

    setSaving(true);
    try {
      const res = await api.post<ApiEnvelope<ApiQuote>>(`/quotes/${saved.id}/send`);
      navigate(`/dashboard/quotes/${res.data.data.id}`);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  if (status === 'loading') return <LoadingState variant="list" rows={5} />;
  if (status === 'error') {
    return (
      <div>
        <ErrorState message={error || 'Could not open the quote builder.'} onRetry={load} />
        <div className="mt-3">
          <Link to="/dashboard/enquiries" className="btn btn-mp-outline btn-sm">Back to enquiries</Link>
        </div>
      </div>
    );
  }

  const requirement = enquiry?.relationships.requirement;
  const isLocked = mode === 'edit' && quote !== null && !quote.attributes.can_edit;

  return (
    <div>
      <div className="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
          <h1 className="h5 mb-1">{mode === 'edit' ? 'Edit quote' : 'Create quote'}</h1>
          <div className="small text-muted">
            {quote ? `${quote.attributes.quote_number} · ` : ''}
            {requirement?.attributes.title ?? 'Requirement'}
            {requirement?.relationships.category && ` · ${requirement.relationships.category.attributes.name}`}
          </div>
        </div>
        <Link to={enquiry ? `/dashboard/enquiries/${enquiry.id}` : '/dashboard/enquiries'} className="btn btn-sm btn-mp-ghost">
          Back to enquiry
        </Link>
      </div>

      {isLocked && (
        <div className="alert alert-warning py-2 small">
          This quote has already been sent, so its contents are locked. Withdraw it and issue a new quote if the price needs to change.
        </div>
      )}

      {error && <div className="alert alert-danger">{error}</div>}

      {/* What the customer asked for, so the business can quote against it. */}
      {requirement && (
        <div className="mp-card p-3 mb-3 small">
          <div className="fw-semibold mb-1">Requirement</div>
          <div className="row g-2">
            <div className="col-6 col-md-3">
              <div className="text-muted">Quantity</div>
              <div>{requirement.attributes.quantity ?? '—'} {requirement.attributes.unit}</div>
            </div>
            <div className="col-6 col-md-3">
              <div className="text-muted">Budget</div>
              <div>
                {requirement.attributes.budget_min || requirement.attributes.budget_max
                  ? `${formatMoney(requirement.attributes.budget_min ?? requirement.attributes.budget_max, form.currency)}${
                      requirement.attributes.budget_min && requirement.attributes.budget_max
                        ? ` – ${formatMoney(requirement.attributes.budget_max, form.currency)}`
                        : ''
                    }`
                  : 'Not specified'}
              </div>
            </div>
            <div className="col-6 col-md-3">
              <div className="text-muted">Required by</div>
              <div>{requirement.attributes.required_date ?? 'No fixed date'}</div>
            </div>
            <div className="col-6 col-md-3">
              <div className="text-muted">Location</div>
              <div>{requirement.relationships.location?.attributes.city ?? '—'}</div>
            </div>
          </div>
          {requirement.attributes.description && <div className="mt-2 text-muted">{requirement.attributes.description}</div>}
        </div>
      )}

      <div className="mp-card p-4 mb-3">
        <div className="row g-3">
          <div className="col-12">
            <label className="form-label" htmlFor="quote-title">Quote title</label>
            <input
              id="quote-title"
              className="form-control"
              value={form.title}
              maxLength={255}
              disabled={isLocked}
              onChange={(e) => setForm({ ...form, title: e.target.value })}
            />
          </div>
          <div className="col-12">
            <label className="form-label" htmlFor="quote-description">Description</label>
            <textarea
              id="quote-description"
              className="form-control"
              rows={2}
              value={form.description}
              maxLength={5000}
              disabled={isLocked}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
            />
          </div>
        </div>
      </div>

      <div className="mp-card p-4 mb-3">
        <QuoteItemsEditor items={items} currency={form.currency} disabled={isLocked} onChange={setItems} />
      </div>

      <div className="row g-3 mb-3">
        <div className="col-12 col-lg-7">
          <div className="mp-card p-4 h-100">
            <div className="fw-semibold mb-3">Charges &amp; validity</div>
            <div className="row g-3">
              <div className="col-6 col-md-4">
                <label className="form-label small" htmlFor="delivery-fee">Delivery fee</label>
                <input
                  id="delivery-fee"
                  type="number"
                  min="0"
                  step="0.01"
                  className="form-control form-control-sm"
                  value={form.delivery_fee}
                  disabled={isLocked}
                  onChange={(e) => setForm({ ...form, delivery_fee: e.target.value })}
                />
              </div>
              <div className="col-6 col-md-4">
                <label className="form-label small" htmlFor="other-charges">Other charges</label>
                <input
                  id="other-charges"
                  type="number"
                  min="0"
                  step="0.01"
                  className="form-control form-control-sm"
                  value={form.other_charges}
                  disabled={isLocked}
                  onChange={(e) => setForm({ ...form, other_charges: e.target.value })}
                />
              </div>
              <div className="col-6 col-md-4">
                <label className="form-label small" htmlFor="quote-discount">Quote discount</label>
                <input
                  id="quote-discount"
                  type="number"
                  min="0"
                  step="0.01"
                  className={`form-control form-control-sm ${totals.discountExceedsTotal ? 'is-invalid' : ''}`}
                  value={form.discount_amount}
                  disabled={isLocked}
                  onChange={(e) => setForm({ ...form, discount_amount: e.target.value })}
                />
                {totals.discountExceedsTotal && (
                  <div className="invalid-feedback d-block small">Cannot exceed the quote total.</div>
                )}
              </div>

              <div className="col-6 col-md-4">
                <label className="form-label small" htmlFor="validity-date">Valid until</label>
                <input
                  id="validity-date"
                  type="date"
                  className="form-control form-control-sm"
                  value={form.validity_date}
                  disabled={isLocked}
                  onChange={(e) => setForm({ ...form, validity_date: e.target.value })}
                />
              </div>
              {/* Delivery vs completion: products tend to use one, services and
                  projects the other. Both optional, neither assumed. */}
              <div className="col-6 col-md-4">
                <label className="form-label small" htmlFor="delivery-date">Est. delivery</label>
                <input
                  id="delivery-date"
                  type="date"
                  className="form-control form-control-sm"
                  value={form.estimated_delivery_date}
                  disabled={isLocked}
                  onChange={(e) => setForm({ ...form, estimated_delivery_date: e.target.value })}
                />
              </div>
              <div className="col-6 col-md-4">
                <label className="form-label small" htmlFor="completion-date">Est. completion</label>
                <input
                  id="completion-date"
                  type="date"
                  className="form-control form-control-sm"
                  value={form.estimated_completion_date}
                  disabled={isLocked}
                  onChange={(e) => setForm({ ...form, estimated_completion_date: e.target.value })}
                />
              </div>
            </div>
          </div>
        </div>

        <div className="col-12 col-lg-5">
          <div className="mp-card p-4 h-100">
            <div className="fw-semibold mb-1">Totals</div>
            <p className="small text-muted mb-3">
              A live preview. The final figures are calculated and stored by the server when you save.
            </p>
            <dl className="mp-quote-totals mb-0">
              <div>
                <dt>Subtotal</dt>
                <dd>{formatMoney(totals.subtotal, form.currency)}</dd>
              </div>
              {Number(totals.itemDiscount) > 0 && (
                <div>
                  <dt>Line discounts</dt>
                  <dd>- {formatMoney(totals.itemDiscount, form.currency)}</dd>
                </div>
              )}
              <div>
                <dt>Tax</dt>
                <dd>{formatMoney(totals.tax, form.currency)}</dd>
              </div>
              {Number(totals.deliveryFee) > 0 && (
                <div>
                  <dt>Delivery</dt>
                  <dd>{formatMoney(totals.deliveryFee, form.currency)}</dd>
                </div>
              )}
              {Number(totals.otherCharges) > 0 && (
                <div>
                  <dt>Other charges</dt>
                  <dd>{formatMoney(totals.otherCharges, form.currency)}</dd>
                </div>
              )}
              {Number(totals.quoteDiscount) > 0 && (
                <div>
                  <dt>Quote discount</dt>
                  <dd>- {formatMoney(totals.quoteDiscount, form.currency)}</dd>
                </div>
              )}
              <div className="mp-quote-totals__grand">
                <dt>Total</dt>
                <dd>{formatMoney(totals.total, form.currency)}</dd>
              </div>
            </dl>
          </div>
        </div>
      </div>

      <div className="mp-card p-4 mb-3">
        <div className="fw-semibold mb-3">Terms</div>
        <div className="row g-3">
          <div className="col-12 col-md-6">
            <label className="form-label small" htmlFor="payment-terms">Payment terms</label>
            <textarea
              id="payment-terms"
              className="form-control form-control-sm"
              rows={2}
              value={form.payment_terms}
              maxLength={2000}
              disabled={isLocked}
              onChange={(e) => setForm({ ...form, payment_terms: e.target.value })}
            />
          </div>
          <div className="col-12 col-md-6">
            <label className="form-label small" htmlFor="warranty-terms">Warranty / support</label>
            <textarea
              id="warranty-terms"
              className="form-control form-control-sm"
              rows={2}
              value={form.warranty_terms}
              maxLength={2000}
              disabled={isLocked}
              onChange={(e) => setForm({ ...form, warranty_terms: e.target.value })}
            />
          </div>
          <div className="col-12 col-md-6">
            <label className="form-label small" htmlFor="cancellation-terms">Cancellation</label>
            <textarea
              id="cancellation-terms"
              className="form-control form-control-sm"
              rows={2}
              value={form.cancellation_terms}
              maxLength={2000}
              disabled={isLocked}
              onChange={(e) => setForm({ ...form, cancellation_terms: e.target.value })}
            />
          </div>
          <div className="col-12 col-md-6">
            <label className="form-label small" htmlFor="quote-notes">Notes</label>
            <textarea
              id="quote-notes"
              className="form-control form-control-sm"
              rows={2}
              value={form.notes}
              maxLength={5000}
              disabled={isLocked}
              onChange={(e) => setForm({ ...form, notes: e.target.value })}
            />
          </div>
        </div>
      </div>

      {!isLocked && (
        <div className="d-flex gap-2 flex-wrap mb-4">
          <button type="button" className="btn btn-mp-outline" disabled={saving} onClick={saveDraft}>
            {saving ? 'Saving…' : 'Save draft'}
          </button>
          <button type="button" className="btn btn-mp-outline" disabled={saving} onClick={saveAndPreview}>
            Save &amp; preview
          </button>
          <button type="button" className="btn btn-mp-primary" disabled={saving} onClick={send}>
            Send to customer
          </button>
          {quote && (
            <Link to={`/dashboard/quotes/${quote.id}`} className="btn btn-mp-ghost">
              Open quote
            </Link>
          )}
        </div>
      )}

      {/* The preview renders the saved quote from the API, not the form state —
          so what is shown is exactly what the customer would receive. */}
      {showPreview && quote && (
        <div className="mp-card p-4">
          <div className="d-flex justify-content-between align-items-center mb-3">
            <h2 className="h6 mb-0">Preview</h2>
            <button type="button" className="btn btn-sm btn-mp-ghost" onClick={() => setShowPreview(false)}>
              Hide
            </button>
          </div>
          <QuotePreview quote={quote} />
        </div>
      )}
    </div>
  );
}
