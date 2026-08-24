import { useState } from 'react';
import { api, getErrorMessage } from '../../../lib/api/client';
import type { ApiEnvelope, ApiDispute, ApiOrder } from '../../../types/api';

/**
 * Raise a dispute on an order.
 *
 * Shown only when the API says `can_dispute`, which already accounts for "you are a
 * party", "the order isn't cancelled" and "there isn't one open already". The reason
 * list comes from the backend too, so adding a reason server-side needs no frontend
 * change.
 *
 * Deliberately understated: this is a last resort, not a call to action, so it sits
 * at the bottom of the order and is collapsed until asked for.
 */
interface RaiseDisputeCardProps {
  order: ApiOrder;
  onRaised?: () => void;
}

/** Backend reason keys are snake_case; this is presentation only. */
function humanise(reason: string): string {
  return reason.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
}

export default function RaiseDisputeCard({ order, onRaised }: RaiseDisputeCardProps) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [description, setDescription] = useState('');
  const [evidence, setEvidence] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  if (!order.attributes.can_dispute) {
    return null;
  }

  const reasons = order.attributes.dispute_reasons ?? [];

  const submit = async () => {
    setError('');
    setBusy(true);
    try {
      await api.post<ApiEnvelope<ApiDispute>>(`/orders/${order.id}/disputes`, {
        reason,
        description: description.trim(),
        ...(evidence.trim() ? { evidence: evidence.trim() } : {}),
      });
      setOpen(false);
      setReason('');
      setDescription('');
      setEvidence('');
      onRaised?.();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mp-card p-4 mt-3">
      <h2 className="h6 mb-1">Something wrong with this order?</h2>
      <p className="small text-muted mb-2">
        Try messaging the other party first. If that doesn't resolve it, raise a dispute and an administrator will review it.
      </p>

      {!open && (
        <button type="button" className="btn btn-sm btn-mp-ghost text-danger px-0" onClick={() => setOpen(true)}>
          Raise a dispute
        </button>
      )}

      {open && (
        <>
          {error && <div className="alert alert-danger py-2 small" role="alert">{error}</div>}

          <div className="mp-form-field mb-2">
            <label className="form-label small" htmlFor="dispute-reason">Reason</label>
            <select
              id="dispute-reason"
              className="form-select form-select-sm"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
            >
              <option value="">Choose a reason…</option>
              {reasons.map((option) => (
                <option key={option} value={option}>{humanise(option)}</option>
              ))}
            </select>
          </div>

          <div className="mp-form-field mb-2">
            <label className="form-label small" htmlFor="dispute-description">What happened?</label>
            <textarea
              id="dispute-description"
              className="form-control form-control-sm"
              rows={4}
              maxLength={5000}
              value={description}
              onChange={(event) => setDescription(event.target.value)}
            />
            <div className="form-text small">At least 10 characters. This cannot be edited once submitted.</div>
          </div>

          <div className="mp-form-field mb-2">
            <label className="form-label small" htmlFor="dispute-evidence">Supporting detail (optional)</label>
            <textarea
              id="dispute-evidence"
              className="form-control form-control-sm"
              rows={2}
              maxLength={5000}
              value={evidence}
              onChange={(event) => setEvidence(event.target.value)}
            />
          </div>

          <div className="d-flex gap-2">
            <button
              type="button"
              className="btn btn-sm btn-mp-primary"
              disabled={busy || reason === '' || description.trim().length < 10}
              onClick={submit}
            >
              {busy ? 'Submitting…' : 'Submit dispute'}
            </button>
            <button type="button" className="btn btn-sm btn-mp-ghost" onClick={() => setOpen(false)}>Cancel</button>
          </div>
        </>
      )}
    </div>
  );
}
