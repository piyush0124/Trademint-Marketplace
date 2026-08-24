import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, getErrorMessage } from '../../../lib/api/client';
import { formatMoney } from '../../../lib/money';
import type { ApiEnquiry, ApiEnvelope, ApiQuote, SimpleListResponse } from '../../../types/api';
import StatusBadge from '../../../components/ui/StatusBadge';
import LoadingState from '../../../components/ui/LoadingState';
import ErrorState from '../../../components/ui/ErrorState';
import EmptyState from '../../../components/ui/EmptyState';

/**
 * One enquiry: the requirement being discussed, both sides' messages, and every
 * quote raised against it.
 *
 * Shared by customer and business. The difference in what each sees is decided by
 * the API — a business is never sent the customer's identity, and a customer is
 * never sent the business's unsent drafts.
 */
export default function EnquiryDetailPage() {
  const { id } = useParams<{ id: string }>();

  const [enquiry, setEnquiry] = useState<ApiEnquiry | null>(null);
  const [quotes, setQuotes] = useState<ApiQuote[]>([]);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error' | 'forbidden'>('loading');
  const [error, setError] = useState('');
  const [actionError, setActionError] = useState('');
  const [busy, setBusy] = useState(false);
  const [responseMessage, setResponseMessage] = useState('');

  const load = () => {
    if (!id) return;
    setStatus('loading');

    Promise.all([
      api.get<ApiEnvelope<ApiEnquiry>>(`/enquiries/${id}`),
      api.get<SimpleListResponse<ApiQuote>>(`/enquiries/${id}/quotes`),
    ])
      .then(([enquiryRes, quotesRes]) => {
        setEnquiry(enquiryRes.data.data);
        setQuotes(quotesRes.data.data);
        setStatus('ready');
      })
      .catch((err) => {
        setError(getErrorMessage(err));
        setStatus(err?.response?.status === 403 ? 'forbidden' : 'error');
      });
  };

  useEffect(load, [id]);

  const act = async (action: 'accept' | 'decline' | 'cancel' | 'close') => {
    if (!enquiry) return;
    if (action === 'cancel' && !window.confirm('Cancel this enquiry?')) return;
    if (action === 'close' && !window.confirm('Close this enquiry? No further quotes can be raised.')) return;

    setActionError('');
    setBusy(true);
    try {
      const body = ['accept', 'decline'].includes(action) && responseMessage.trim()
        ? { message: responseMessage.trim() }
        : {};
      await api.post(`/enquiries/${enquiry.id}/${action}`, body);
      setResponseMessage('');
      load();
    } catch (err) {
      setActionError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  if (status === 'loading') return <LoadingState variant="list" rows={5} />;
  if (status === 'forbidden') return <ErrorState message="You don't have access to this enquiry." />;
  if (status === 'error' || !enquiry) return <ErrorState message={error || 'Could not load this enquiry.'} onRetry={load} />;

  const a = enquiry.attributes;
  const requirement = enquiry.relationships.requirement;
  const business = enquiry.relationships.business;
  const isBusinessViewer = a.is_viewer_business;

  return (
    <div>
      <div className="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
          <Link to="/dashboard/enquiries" className="btn btn-sm btn-mp-ghost px-0 mb-1">← All enquiries</Link>
          <h1 className="h5 mb-1">{requirement?.attributes.title || 'Untitled requirement'}</h1>
          <div className="small text-muted">
            {requirement?.relationships.category?.attributes.name}
            {requirement?.relationships.location && ` · ${[requirement.relationships.location.attributes.city, requirement.relationships.location.attributes.state].filter(Boolean).join(', ')}`}
          </div>
        </div>
        <StatusBadge status={a.status} />
      </div>

      {actionError && <div className="alert alert-danger">{actionError}</div>}

      {/* What is being asked for. Generic fields only — quantity, budget, dates —
          so this reads correctly for goods, services and projects alike. */}
      <div className="mp-card p-4 mb-3">
        <h2 className="h6 mb-3">Requirement details</h2>
        <dl className="row mb-0 small">
          <dt className="col-5 col-sm-3 text-muted fw-normal">Quantity</dt>
          <dd className="col-7 col-sm-9">{requirement?.attributes.quantity ?? '—'} {requirement?.attributes.unit}</dd>
          <dt className="col-5 col-sm-3 text-muted fw-normal">Budget</dt>
          <dd className="col-7 col-sm-9">
            {requirement?.attributes.budget_min || requirement?.attributes.budget_max
              ? `${formatMoney(requirement?.attributes.budget_min ?? requirement?.attributes.budget_max, 'INR')}${
                  requirement?.attributes.budget_min && requirement?.attributes.budget_max
                    ? ` – ${formatMoney(requirement.attributes.budget_max, 'INR')}`
                    : ''
                }`
              : 'Not specified'}
          </dd>
          <dt className="col-5 col-sm-3 text-muted fw-normal">Required by</dt>
          <dd className="col-7 col-sm-9">{requirement?.attributes.required_date ?? 'No fixed date'}</dd>
          <dt className="col-5 col-sm-3 text-muted fw-normal">Urgency</dt>
          <dd className="col-7 col-sm-9 text-capitalize">{requirement?.attributes.urgency ?? '—'}</dd>
          {!isBusinessViewer && business && (
            <>
              <dt className="col-5 col-sm-3 text-muted fw-normal">Business</dt>
              <dd className="col-7 col-sm-9">
                <Link to={`/business/${business.attributes.slug}`}>{business.attributes.name}</Link>
              </dd>
            </>
          )}
        </dl>
        {requirement?.attributes.description && <p className="small mt-3 mb-0">{requirement.attributes.description}</p>}
      </div>

      <div className="mp-card p-4 mb-3">
        <h2 className="h6 mb-3">Conversation</h2>
        {!a.customer_message && !a.business_message && (
          <p className="small text-muted mb-0">No messages exchanged yet.</p>
        )}
        {a.customer_message && (
          <div className="mb-3">
            <div className="small text-muted fw-semibold">Customer</div>
            <div className="small">{a.customer_message}</div>
          </div>
        )}
        {a.business_message && (
          <div>
            <div className="small text-muted fw-semibold">{business?.attributes.name ?? 'Business'}</div>
            <div className="small">{a.business_message}</div>
          </div>
        )}
      </div>

      {/* The business's decision, with an optional note back to the customer. */}
      {a.can_respond && (
        <div className="mp-card p-4 mb-3">
          <h2 className="h6 mb-2">Your response</h2>
          <p className="small text-muted">
            Accepting lets you prepare a quotation. It is not a commitment to any price.
          </p>
          <label className="form-label small" htmlFor="response-message">Message (optional)</label>
          <textarea
            id="response-message"
            className="form-control form-control-sm mb-3"
            rows={3}
            maxLength={2000}
            value={responseMessage}
            onChange={(e) => setResponseMessage(e.target.value)}
            placeholder="Anything you want the customer to know."
          />
          <div className="d-flex gap-2 flex-wrap">
            <button type="button" className="btn btn-mp-primary btn-sm" disabled={busy} onClick={() => act('accept')}>
              Accept enquiry
            </button>
            <button type="button" className="btn btn-mp-ghost btn-sm text-danger" disabled={busy} onClick={() => act('decline')}>
              Decline
            </button>
          </div>
        </div>
      )}

      <div className="mp-card p-4 mb-3">
        <div className="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <h2 className="h6 mb-0">Quotes</h2>
          {a.can_quote && (
            <Link to={`/dashboard/quotes/new?enquiry=${enquiry.id}`} className="btn btn-sm btn-mp-accent">
              + Create quote
            </Link>
          )}
        </div>

        {quotes.length === 0 ? (
          <EmptyState
            icon="receipt"
            title="No quotes yet."
            description={
              a.can_quote
                ? 'Build a quotation for this requirement.'
                : isBusinessViewer
                  ? 'Accept the enquiry to start quoting.'
                  : 'The business has not sent a quotation yet.'
            }
          />
        ) : (
          <div className="d-flex flex-column gap-2">
            {quotes.map((quote) => (
              <div key={quote.id} className="d-flex justify-content-between align-items-center flex-wrap gap-2 border-top pt-2">
                <div>
                  <Link to={`/dashboard/quotes/${quote.id}`} className="fw-semibold text-dark text-decoration-none">
                    {quote.attributes.title}
                  </Link>
                  <div className="small text-muted">
                    {quote.attributes.quote_number}
                    {quote.attributes.validity_date && ` · valid until ${quote.attributes.validity_date}`}
                  </div>
                </div>
                <div className="d-flex align-items-center gap-3">
                  <span className="fw-semibold">{formatMoney(quote.attributes.total_amount, quote.attributes.currency)}</span>
                  <StatusBadge status={quote.attributes.status} />
                  <Link to={`/dashboard/quotes/${quote.id}`} className="btn btn-sm btn-mp-outline">Open</Link>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      <div className="d-flex gap-2 flex-wrap">
        {a.can_cancel && (
          <button type="button" className="btn btn-sm btn-mp-ghost text-danger" disabled={busy} onClick={() => act('cancel')}>
            Cancel enquiry
          </button>
        )}
        {a.status === 'accepted' && (
          <button type="button" className="btn btn-sm btn-mp-ghost" disabled={busy} onClick={() => act('close')}>
            Close enquiry
          </button>
        )}
      </div>
    </div>
  );
}
