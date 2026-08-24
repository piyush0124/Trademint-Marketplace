import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api, getErrorMessage } from '../../../lib/api/client';
import type { ApiEnvelope, ApiQuote } from '../../../types/api';
import LoadingState from '../../../components/ui/LoadingState';
import ErrorState from '../../../components/ui/ErrorState';
import QuotePreview from '../components/QuotePreview';
import CounterOfferForm from '../../negotiations/components/CounterOfferForm';

/**
 * A single quote, shared by both parties.
 *
 * The available actions come from the API's own `can_*` flags rather than from a
 * role check here, so the buttons on screen always agree with what the backend
 * will actually permit — including expiry, which the server evaluates on read.
 *
 * Simply opening this page as the receiving customer records the view, which is
 * how the business learns the quote has been seen.
 */
export default function QuoteDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();

  const [quote, setQuote] = useState<ApiQuote | null>(null);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error' | 'forbidden'>('loading');
  const [error, setError] = useState('');
  const [actionError, setActionError] = useState('');
  const [busy, setBusy] = useState(false);
  const [rejecting, setRejecting] = useState(false);
  const [rejectionReason, setRejectionReason] = useState('');
  const [countering, setCountering] = useState(false);

  const load = () => {
    if (!id) return;
    setStatus('loading');
    api
      .get<ApiEnvelope<ApiQuote>>(`/quotes/${id}`)
      .then((res) => {
        setQuote(res.data.data);
        setStatus('ready');
      })
      .catch((err) => {
        setError(getErrorMessage(err));
        setStatus(err?.response?.status === 403 ? 'forbidden' : 'error');
      });
  };

  useEffect(load, [id]);

  const act = async (path: string, body?: Record<string, unknown>) => {
    if (!quote) return;
    setActionError('');
    setBusy(true);
    try {
      const res = await api.post<ApiEnvelope<ApiQuote>>(`/quotes/${quote.id}/${path}`, body ?? {});
      setQuote(res.data.data);
      setRejecting(false);
      setRejectionReason('');
    } catch (err) {
      setActionError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  if (status === 'loading') return <div className="mp-container py-4"><LoadingState variant="list" rows={5} /></div>;
  if (status === 'forbidden') {
    return (
      <div className="mp-container py-4">
        <ErrorState message="You don't have access to this quote." />
      </div>
    );
  }
  if (status === 'error' || !quote) {
    return (
      <div className="mp-container py-4">
        <ErrorState message={error || 'Could not load this quote.'} onRetry={load} />
      </div>
    );
  }

  const a = quote.attributes;
  const enquiryId = quote.relationships.enquiry?.id;
  const currentRevision = quote.relationships.current_revision;

  /**
   * Sending a counter opens (or continues) the negotiation, which then owns the
   * conversation — so the user is taken there rather than left on a quote page
   * showing superseded terms.
   */
  const sendCounter = async (payload: Record<string, unknown>) => {
    setActionError('');
    setBusy(true);
    try {
      const res = await api.post<{ data: { id: string } }>(`/quotes/${quote.id}/counter`, payload);
      navigate(`/dashboard/negotiations/${res.data.data.id}`);
    } catch (err) {
      setActionError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mp-container py-4" style={{ maxWidth: 880 }}>
      <div className="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <Link to={enquiryId ? `/dashboard/enquiries/${enquiryId}` : '/dashboard/quotes'} className="btn btn-sm btn-mp-ghost px-0">
          ← Back
        </Link>
        {a.is_expired && a.status === 'expired' && (
          <span className="small text-danger">This quote expired on {a.expires_at?.slice(0, 10)}.</span>
        )}
      </div>

      {actionError && <div className="alert alert-danger">{actionError}</div>}

      {a.status === 'accepted' && (
        <div className="alert alert-success py-2 small">
          Accepted{a.accepted_at ? ` on ${a.accepted_at.slice(0, 10)}` : ''}. The next step, converting this into an order, is coming in a later phase.
        </div>
      )}
      {a.status === 'rejected' && (
        <div className="alert alert-secondary py-2 small">
          Rejected{a.rejected_at ? ` on ${a.rejected_at.slice(0, 10)}` : ''}
          {a.rejection_reason ? ` — "${a.rejection_reason}"` : ''}.
        </div>
      )}
      {a.status === 'withdrawn' && (
        <div className="alert alert-secondary py-2 small">This quote was withdrawn by the business.</div>
      )}

      <div className="mp-card p-4 mb-3">
        <QuotePreview quote={quote} />
      </div>

      <div className="d-flex gap-2 flex-wrap">
        {/* Customer actions */}
        {a.can_decide && (
          <>
            <button type="button" className="btn btn-mp-primary" disabled={busy} onClick={() => act('accept')}>
              {busy ? 'Working…' : 'Accept quote'}
            </button>
            {/* Phase 6: counter instead of accepting outright. */}
            {currentRevision && (
              <button type="button" className="btn btn-mp-outline" disabled={busy} onClick={() => setCountering(!countering)}>
                {countering ? 'Close counter form' : 'Counter offer'}
              </button>
            )}
            <button type="button" className="btn btn-mp-outline" disabled={busy} onClick={() => setRejecting(!rejecting)}>
              Reject quote
            </button>
          </>
        )}

        {/* An open negotiation is the right place to continue. */}
        {a.negotiation_id && (
          <Link to={`/dashboard/negotiations/${a.negotiation_id}`} className="btn btn-mp-ghost">
            View negotiation{a.revision_count > 1 ? ` (${a.revision_count} offers)` : ''}
          </Link>
        )}

        {/* Business actions */}
        {a.can_edit && (
          <Link to={`/dashboard/quotes/${quote.id}/edit`} className="btn btn-mp-outline">Edit draft</Link>
        )}
        {a.can_send && (
          <button type="button" className="btn btn-mp-primary" disabled={busy} onClick={() => act('send')}>
            Send to customer
          </button>
        )}
        {a.can_withdraw && (
          <button
            type="button"
            className="btn btn-mp-ghost text-danger"
            disabled={busy}
            onClick={() => {
              if (window.confirm('Withdraw this quote? The customer will no longer be able to accept it.')) act('withdraw');
            }}
          >
            Withdraw
          </button>
        )}
      </div>

      {countering && currentRevision && (
        <div className="mt-3">
          <CounterOfferForm
            revision={currentRevision}
            submitting={busy}
            onCancel={() => setCountering(false)}
            onSubmit={sendCounter}
          />
        </div>
      )}

      {rejecting && (
        <div className="mp-card p-3 mt-3">
          <label className="form-label small fw-semibold" htmlFor="rejection-reason">
            Reason (optional)
          </label>
          <textarea
            id="rejection-reason"
            className="form-control form-control-sm"
            rows={3}
            maxLength={2000}
            value={rejectionReason}
            onChange={(e) => setRejectionReason(e.target.value)}
            placeholder="Letting the business know why helps them quote better next time."
          />
          <div className="d-flex gap-2 mt-2">
            <button
              type="button"
              className="btn btn-sm btn-mp-primary"
              disabled={busy}
              onClick={() => act('reject', rejectionReason.trim() ? { reason: rejectionReason.trim() } : {})}
            >
              Confirm rejection
            </button>
            <button type="button" className="btn btn-sm btn-mp-ghost" onClick={() => setRejecting(false)}>
              Cancel
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
