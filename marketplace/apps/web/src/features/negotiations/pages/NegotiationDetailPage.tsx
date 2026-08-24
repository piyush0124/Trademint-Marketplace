import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, getErrorMessage } from '../../../lib/api/client';
import { formatMoney } from '../../../lib/money';
import type { ApiEnvelope, ApiNegotiation, QuoteItemInput } from '../../../types/api';
import LoadingState from '../../../components/ui/LoadingState';
import ErrorState from '../../../components/ui/ErrorState';
import StatusBadge from '../../../components/ui/StatusBadge';
import NegotiationTimeline from '../components/NegotiationTimeline';
import CounterOfferForm from '../components/CounterOfferForm';
import QuotePreview from '../../quotes/components/QuotePreview';

/**
 * One negotiation, from either side.
 *
 * The page answers the four questions a user actually has: what is on the table
 * right now, whose move it is, what has happened so far, and what can I do next.
 * Every available action comes from the API's turn flags, so the UI cannot offer
 * a move the backend would refuse.
 */
export default function NegotiationDetailPage() {
  const { id } = useParams<{ id: string }>();

  const [negotiation, setNegotiation] = useState<ApiNegotiation | null>(null);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error' | 'forbidden'>('loading');
  const [error, setError] = useState('');
  const [actionError, setActionError] = useState('');
  const [busy, setBusy] = useState(false);
  const [mode, setMode] = useState<'idle' | 'counter' | 'reject'>('idle');
  const [note, setNote] = useState('');
  const [message, setMessage] = useState('');
  const [showHistory, setShowHistory] = useState(false);

  const load = () => {
    if (!id) return;
    setStatus('loading');
    api
      .get<ApiEnvelope<ApiNegotiation>>(`/negotiations/${id}`)
      .then((res) => {
        setNegotiation(res.data.data);
        setStatus('ready');
      })
      .catch((err) => {
        setError(getErrorMessage(err));
        setStatus(err?.response?.status === 403 ? 'forbidden' : 'error');
      });
  };

  useEffect(load, [id]);

  const act = async (path: string, body: Record<string, unknown> = {}) => {
    if (!negotiation) return;
    setActionError('');
    setBusy(true);
    try {
      const res = await api.post<ApiEnvelope<ApiNegotiation>>(`/negotiations/${negotiation.id}/${path}`, body);
      setNegotiation(res.data.data);
      setMode('idle');
      setNote('');
      // Reload so the timeline and history include this action.
      load();
    } catch (err) {
      setActionError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const sendCounter = async (payload: { revision_id: string; items: QuoteItemInput[] } & Record<string, unknown>) => {
    if (!negotiation?.relationships.quote) return;
    setActionError('');
    setBusy(true);
    try {
      await api.post(`/quotes/${negotiation.relationships.quote.id}/counter`, payload);
      setMode('idle');
      load();
    } catch (err) {
      // A 409 means the other side moved first; reloading shows the new offer.
      setActionError(getErrorMessage(err));
      if ((err as { response?: { status?: number } })?.response?.status === 409) load();
    } finally {
      setBusy(false);
    }
  };

  const postMessage = async () => {
    if (!negotiation || message.trim().length === 0) return;
    setBusy(true);
    try {
      await api.post(`/negotiations/${negotiation.id}/messages`, { body: message.trim() });
      setMessage('');
      load();
    } catch (err) {
      setActionError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  if (status === 'loading') return <LoadingState variant="list" rows={5} />;
  if (status === 'forbidden') return <ErrorState message="You don't have access to this negotiation." />;
  if (status === 'error' || !negotiation) return <ErrorState message={error || 'Could not load this negotiation.'} onRetry={load} />;

  const a = negotiation.attributes;
  const current = negotiation.relationships.current_revision;
  const quote = negotiation.relationships.quote;
  const requirement = negotiation.relationships.requirement;
  const revisions = negotiation.relationships.revisions ?? [];
  const messages = negotiation.relationships.messages ?? [];

  return (
    <div>
      <div className="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
          <Link to="/dashboard/negotiations" className="btn btn-sm btn-mp-ghost px-0 mb-1">← All negotiations</Link>
          <h1 className="h5 mb-1">{requirement?.attributes.title || quote?.attributes.title || 'Negotiation'}</h1>
          <div className="small text-muted">
            {quote?.attributes.quote_number}
            {requirement?.relationships.category && ` · ${requirement.relationships.category.attributes.name}`}
            {` · round ${a.round}`}
          </div>
        </div>
        <StatusBadge status={a.status} />
      </div>

      {actionError && <div className="alert alert-danger" role="alert">{actionError}</div>}

      {/* Where am I / what needs my attention — stated in words, not just colour. */}
      <div className={`alert ${a.is_my_turn ? 'alert-primary' : 'alert-secondary'} py-2 small`} role="status">
        {a.status === 'accepted' && 'This negotiation is settled — the terms below were accepted.'}
        {a.status === 'rejected' && 'This negotiation was rejected. The history is kept for your records.'}
        {a.status === 'withdrawn' && 'The live offer was withdrawn.'}
        {a.status === 'expired' && 'The live offer expired before it was decided.'}
        {a.is_active && (a.is_my_turn
          ? 'It is your turn — accept the current offer, counter it, or reject it.'
          : 'Waiting on the other party to respond to the current offer.')}
      </div>

      <div className="row g-3">
        <div className="col-12 col-lg-7">
          {/* What is on the table right now. */}
          {quote && current && (
            <div className="mp-card p-4 mb-3">
              <div className="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h2 className="h6 mb-0">
                  Current offer
                  <span className="text-muted fw-normal small">
                    {' '}· #{current.attributes.revision_number} from {current.attributes.authored_by_label}
                  </span>
                </h2>
                {current.attributes.is_final_offer && <span className="mp-badge-featured">Final offer</span>}
              </div>
              <QuotePreview quote={{ ...quote, relationships: { ...quote.relationships, items: current.relationships.items ?? quote.relationships.items } }} />
            </div>
          )}

          {mode === 'counter' && current && (
            <CounterOfferForm
              revision={current}
              submitting={busy}
              onCancel={() => setMode('idle')}
              onSubmit={sendCounter}
            />
          )}

          {/* The immutable ledger, collapsed by default. */}
          {revisions.length > 1 && (
            <div className="mp-card p-4">
              <button
                type="button"
                className="btn btn-sm btn-mp-ghost px-0"
                onClick={() => setShowHistory(!showHistory)}
                aria-expanded={showHistory}
              >
                {showHistory ? 'Hide offer history' : `Show offer history (${revisions.length} offers)`}
              </button>

              {showHistory && (
                <div className="table-responsive mt-3">
                  <table className="table table-sm align-middle mb-0">
                    <caption className="small text-muted">
                      Every offer is kept exactly as it was made — nothing here is ever rewritten.
                    </caption>
                    <thead>
                      <tr>
                        <th scope="col">#</th>
                        <th scope="col">From</th>
                        <th scope="col" className="text-end">Total</th>
                        <th scope="col">Valid until</th>
                        <th scope="col">State</th>
                      </tr>
                    </thead>
                    <tbody>
                      {revisions.map((revision) => (
                        <tr key={revision.id}>
                          <td>{revision.attributes.revision_number}</td>
                          <td>{revision.attributes.authored_by_label}</td>
                          <td className="text-end">{formatMoney(revision.attributes.total_amount, revision.attributes.currency)}</td>
                          <td>{revision.attributes.validity_date ?? '—'}</td>
                          <td>
                            {revision.attributes.is_current
                              ? <span className="mp-status-badge mp-status-published">Current</span>
                              : <span className="mp-status-badge mp-status-archived">Superseded</span>}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}
        </div>

        <div className="col-12 col-lg-5">
          {/* What can I do next. */}
          <div className="mp-card p-4 mb-3">
            <h2 className="h6 mb-3">Your options</h2>

            {!a.can_accept && !a.can_counter && !a.can_reject && !a.can_withdraw && (
              <p className="small text-muted mb-0">
                No action is available to you right now.
                {a.is_active && !a.is_my_turn && ' The other party has the current offer.'}
              </p>
            )}

            <div className="d-flex flex-column gap-2">
              {a.can_accept && (
                <button
                  type="button"
                  className="btn btn-mp-primary"
                  disabled={busy}
                  onClick={() => act('accept', current ? { revision_id: current.id } : {})}
                >
                  {busy ? 'Working…' : `Accept ${current ? formatMoney(current.attributes.total_amount, current.attributes.currency) : 'offer'}`}
                </button>
              )}
              {a.can_counter && (
                <button type="button" className="btn btn-mp-outline" disabled={busy} onClick={() => setMode(mode === 'counter' ? 'idle' : 'counter')}>
                  {mode === 'counter' ? 'Close counter form' : 'Counter offer'}
                </button>
              )}
              {a.can_reject && (
                <button type="button" className="btn btn-mp-ghost text-danger" disabled={busy} onClick={() => setMode(mode === 'reject' ? 'idle' : 'reject')}>
                  Reject offer
                </button>
              )}
              {a.can_withdraw && (
                <button
                  type="button"
                  className="btn btn-mp-ghost text-danger"
                  disabled={busy}
                  onClick={() => {
                    if (window.confirm('Withdraw your offer? The other party will no longer be able to accept it.')) act('withdraw');
                  }}
                >
                  Withdraw my offer
                </button>
              )}
            </div>

            {mode === 'reject' && (
              <div className="mt-3">
                <label className="form-label small fw-semibold" htmlFor="reject-note">Reason (optional)</label>
                <textarea id="reject-note" className="form-control form-control-sm" rows={3} maxLength={2000}
                  value={note} onChange={(e) => setNote(e.target.value)} />
                <div className="d-flex gap-2 mt-2">
                  <button type="button" className="btn btn-sm btn-mp-primary" disabled={busy}
                    onClick={() => act('reject', note.trim() ? { note: note.trim() } : {})}>
                    Confirm rejection
                  </button>
                  <button type="button" className="btn btn-sm btn-mp-ghost" onClick={() => setMode('idle')}>Cancel</button>
                </div>
              </div>
            )}
          </div>

          <div className="mp-card p-4">
            <h2 className="h6 mb-3">History</h2>
            <NegotiationTimeline entries={messages} viewerParty={a.viewer_party} />

            {a.can_message && (
              <div className="mt-3">
                <label className="form-label small" htmlFor="negotiation-message">Add a message</label>
                <textarea id="negotiation-message" className="form-control form-control-sm" rows={2} maxLength={2000}
                  value={message} onChange={(e) => setMessage(e.target.value)} />
                <button type="button" className="btn btn-sm btn-mp-outline mt-2" disabled={busy || message.trim() === ''} onClick={postMessage}>
                  {busy ? 'Sending…' : 'Send message'}
                </button>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
