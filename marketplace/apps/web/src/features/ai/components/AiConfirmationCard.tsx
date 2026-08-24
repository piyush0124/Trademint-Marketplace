import { useState } from 'react';
import { api, getErrorMessage } from '../../../lib/api/client';
import type { AiPendingAction } from '../../../types/ai';

/**
 * The confirmation prompt for a write the assistant has proposed.
 *
 * This card is the human in the loop, and it is written to look like one. The summary
 * shown is composed by the backend from the *validated arguments* — not by the model — so
 * if the assistant said "500 chairs" while the arguments say 50, this card says 50,
 * because 50 is what will actually be written.
 *
 * Nothing is pre-selected and neither button is styled as the obvious default. A
 * confirmation that nudges is not a confirmation.
 */
interface AiConfirmationCardProps {
  action: AiPendingAction;
  onResolved: (outcome: 'confirmed' | 'rejected') => void;
}

export default function AiConfirmationCard({ action, onResolved }: AiConfirmationCardProps) {
  const [busy, setBusy] = useState<'confirm' | 'reject' | null>(null);
  const [error, setError] = useState('');
  const [done, setDone] = useState<'confirmed' | 'rejected' | null>(null);

  const act = async (decision: 'confirm' | 'reject') => {
    setError('');
    setBusy(decision);
    try {
      await api.post(`/ai/actions/${action.uuid}/${decision}`);
      const outcome = decision === 'confirm' ? 'confirmed' : 'rejected';
      setDone(outcome);
      onResolved(outcome);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setBusy(null);
    }
  };

  if (done === 'confirmed') {
    return (
      <div className="mp-ai-confirm mp-ai-confirm--done" role="status">
        <span aria-hidden="true">✓</span> Done — {action.summary ?? action.tool}
      </div>
    );
  }

  if (done === 'rejected') {
    return (
      <div className="mp-ai-confirm mp-ai-confirm--declined" role="status">
        Declined. Nothing was changed.
      </div>
    );
  }

  return (
    <div className="mp-ai-confirm">
      <div className="mp-ai-confirm__label">Needs your confirmation</div>
      {/* The backend's description of what will happen, not the assistant's. */}
      <p className="mp-ai-confirm__summary">{action.summary ?? `Run ${action.tool}`}</p>

      {error && <div className="alert alert-danger py-2 small mb-2" role="alert">{error}</div>}

      <div className="d-flex gap-2 flex-wrap">
        <button
          type="button"
          className="btn btn-sm btn-mp-primary"
          disabled={busy !== null}
          onClick={() => act('confirm')}
        >
          {busy === 'confirm' ? 'Working…' : 'Yes, do it'}
        </button>
        <button
          type="button"
          className="btn btn-sm btn-mp-outline"
          disabled={busy !== null}
          onClick={() => act('reject')}
        >
          No, cancel
        </button>
      </div>
    </div>
  );
}
