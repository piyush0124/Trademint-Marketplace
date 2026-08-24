import { useEffect, useState } from 'react';
import { api, getErrorMessage } from '../../../lib/api/client';
import type {
  ApiRequirementMatch,
  MatchListResponse,
  MatchResponseCounts,
  MatchResponseStatus,
} from '../../../types/api';
import LoadingState from '../../../components/ui/LoadingState';
import ErrorState from '../../../components/ui/ErrorState';
import EmptyState from '../../../components/ui/EmptyState';
import MatchScore from '../components/MatchScore';
import MatchBreakdown from '../components/MatchBreakdown';

const FILTERS: { value: MatchResponseStatus | 'all'; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'pending', label: 'Awaiting Reply' },
  { value: 'accepted', label: 'Accepted' },
  { value: 'declined', label: 'Declined' },
];

function formatBudget(min: string | null, max: string | null): string {
  if (!min && !max) return 'Budget not specified';
  if (min && max) return `₹${Number(min).toLocaleString('en-IN')} – ₹${Number(max).toLocaleString('en-IN')}`;
  return `Up to ₹${Number(min ?? max).toLocaleString('en-IN')}`;
}

function formatQuantity(quantity: string | null, unit: string | null): string {
  if (!quantity) return 'Quantity not specified';
  const amount = Number(quantity);
  const formatted = Number.isInteger(amount) ? amount.toLocaleString('en-IN') : amount.toLocaleString('en-IN');
  return unit ? `${formatted} ${unit}` : formatted;
}

/**
 * The business's matched-requirements inbox.
 *
 * Every row here is an invitation the matching engine created for *this*
 * business — the API scopes the list by business, so it is never the open
 * marketplace browse. A business can view, accept, decline or ask a
 * clarifying question. Quoting is not part of this phase, so accepting
 * expresses interest and nothing more.
 */
export default function MatchedRequirementsPage() {
  const [matches, setMatches] = useState<ApiRequirementMatch[]>([]);
  const [counts, setCounts] = useState<MatchResponseCounts | null>(null);
  const [filter, setFilter] = useState<MatchResponseStatus | 'all'>('all');
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [emptyMessage, setEmptyMessage] = useState('');
  const [actionError, setActionError] = useState('');
  const [expanded, setExpanded] = useState<string | null>(null);
  const [questionFor, setQuestionFor] = useState<string | null>(null);
  const [questionText, setQuestionText] = useState('');
  const [busyId, setBusyId] = useState<string | null>(null);

  const load = () => {
    setStatus('loading');
    const params = filter === 'all' ? { per_page: 50 } : { per_page: 50, 'filter[response_status]': filter };
    api
      .get<MatchListResponse>('/users/me/matched-requirements', { params })
      .then((res) => {
        setMatches(res.data.data);
        setCounts(res.data.meta.response_counts ?? null);
        setEmptyMessage(res.data.message);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  useEffect(load, [filter]);

  /** Replaces the single row in place — no full reload, so the list doesn't jump. */
  const applyUpdated = (updated: ApiRequirementMatch) => {
    setMatches((current) => current.map((match) => (match.id === updated.id ? updated : match)));
  };

  const runAction = async (matchId: string, action: () => Promise<{ data: { data: ApiRequirementMatch } }>) => {
    setActionError('');
    setBusyId(matchId);
    try {
      const res = await action();
      applyUpdated(res.data.data);
      // Response counts live server-side; refresh them without blocking the UI.
      api
        .get<MatchListResponse>('/users/me/matched-requirements', { params: { per_page: 1 } })
        .then((res2) => setCounts(res2.data.meta.response_counts ?? null))
        .catch(() => undefined);
    } catch (err) {
      setActionError(getErrorMessage(err));
    } finally {
      setBusyId(null);
    }
  };

  const accept = (match: ApiRequirementMatch) =>
    runAction(match.id, () => api.post(`/matches/${match.id}/accept`));

  const decline = (match: ApiRequirementMatch) => {
    const note = window.prompt('Optionally tell the customer why you are declining:') ?? undefined;
    return runAction(match.id, () => api.post(`/matches/${match.id}/decline`, note ? { note } : {}));
  };

  const askQuestion = async (match: ApiRequirementMatch) => {
    if (questionText.trim().length < 5) {
      setActionError('Please write a question of at least 5 characters.');
      return;
    }
    await runAction(match.id, () => api.post(`/matches/${match.id}/question`, { question: questionText.trim() }));
    setQuestionFor(null);
    setQuestionText('');
  };

  /** Marks the invitation as viewed so the customer can see it was opened. */
  const markViewed = (match: ApiRequirementMatch) => {
    if (match.attributes.viewed_at) {
      setExpanded(expanded === match.id ? null : match.id);
      return;
    }
    setExpanded(match.id);
    api
      .get<{ data: ApiRequirementMatch }>(`/matches/${match.id}`)
      .then((res) => applyUpdated(res.data.data))
      .catch(() => undefined);
  };

  return (
    <div>
      <div className="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
          <h1 className="h5 mb-1">Matched Requirements</h1>
          <p className="text-muted small mb-0">
            Customer requirements the marketplace matched to your business. Respond to let them know you can help.
          </p>
        </div>
      </div>

      {counts && (
        <div className="row g-3 mb-4">
          <div className="col-4">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{counts.pending}</div>
              <div className="mp-stat-card__label">Awaiting Reply</div>
            </div>
          </div>
          <div className="col-4">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{counts.accepted}</div>
              <div className="mp-stat-card__label">Accepted</div>
            </div>
          </div>
          <div className="col-4">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{counts.declined}</div>
              <div className="mp-stat-card__label">Declined</div>
            </div>
          </div>
        </div>
      )}

      <div className="d-flex gap-2 mb-3 flex-wrap">
        {FILTERS.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`btn btn-sm ${filter === option.value ? 'btn-mp-primary' : 'btn-mp-outline'}`}
            onClick={() => setFilter(option.value)}
          >
            {option.label}
          </button>
        ))}
      </div>

      {actionError && <div className="alert alert-danger">{actionError}</div>}

      {status === 'loading' && <LoadingState variant="list" rows={4} />}
      {status === 'error' && <ErrorState onRetry={load} />}

      {status === 'ready' && matches.length === 0 && (
        <EmptyState
          icon="inbox"
          title="No matched requirements yet."
          description={
            emptyMessage.includes('business profile')
              ? emptyMessage
              : 'Keep your categories, service areas and capabilities up to date — the better your profile, the more requirements you will be matched with.'
          }
        />
      )}

      {status === 'ready' && matches.length > 0 && (
        <div className="d-flex flex-column gap-3">
          {matches.map((match) => {
            const requirement = match.relationships.requirement;
            const attributes = match.attributes;
            const isExpanded = expanded === match.id;
            const isBusy = busyId === match.id;

            return (
              <div key={match.id} className={`mp-match-card status-${attributes.response_status}`}>
                <div className="d-flex gap-3 align-items-start">
                  <MatchScore percentage={attributes.match_percentage} size="sm" />

                  <div className="flex-grow-1">
                    <div className="d-flex justify-content-between align-items-start flex-wrap gap-2">
                      <div>
                        <div className="fw-semibold">{requirement?.attributes.title || 'Untitled requirement'}</div>
                        <div className="small text-muted">
                          {requirement?.relationships.category?.attributes.name ?? 'No category'}
                          {requirement?.relationships.location &&
                            ` · ${[requirement.relationships.location.attributes.city, requirement.relationships.location.attributes.state]
                              .filter(Boolean)
                              .join(', ')}`}
                        </div>
                      </div>
                      <span className={`mp-status-badge mp-status-${attributes.response_status}`}>
                        {attributes.response_status_label}
                      </span>
                    </div>

                    <dl className="row mb-0 mt-2 small">
                      <dt className="col-5 col-sm-3 text-muted fw-normal">Quantity</dt>
                      <dd className="col-7 col-sm-9 mb-1">
                        {formatQuantity(requirement?.attributes.quantity ?? null, requirement?.attributes.unit ?? null)}
                      </dd>
                      <dt className="col-5 col-sm-3 text-muted fw-normal">Budget</dt>
                      <dd className="col-7 col-sm-9 mb-1">
                        {formatBudget(requirement?.attributes.budget_min ?? null, requirement?.attributes.budget_max ?? null)}
                      </dd>
                      <dt className="col-5 col-sm-3 text-muted fw-normal">Required by</dt>
                      <dd className="col-7 col-sm-9 mb-1">{requirement?.attributes.required_date ?? 'No fixed date'}</dd>
                      <dt className="col-5 col-sm-3 text-muted fw-normal">Urgency</dt>
                      <dd className="col-7 col-sm-9 mb-0 text-capitalize">{requirement?.attributes.urgency ?? '—'}</dd>
                    </dl>

                    {isExpanded && requirement?.attributes.description && (
                      <p className="small mt-2 mb-0">{requirement.attributes.description}</p>
                    )}

                    {isExpanded && attributes.reasons.length > 0 && (
                      <>
                        <div className="small fw-semibold mt-3">Why you were matched</div>
                        <ul className="mp-match-reasons mt-1">
                          {attributes.reasons.map((reason) => (
                            <li key={reason}>{reason}</li>
                          ))}
                        </ul>
                        <div className="mt-2">
                          <MatchBreakdown breakdown={attributes.score_breakdown} />
                        </div>
                      </>
                    )}

                    {attributes.question && (
                      <div className="alert alert-warning py-2 px-3 small mt-2 mb-0">
                        <strong>Your question:</strong> {attributes.question}
                      </div>
                    )}

                    <div className="d-flex gap-2 flex-wrap align-items-center mt-3">
                      <button type="button" className="btn btn-sm btn-mp-outline" onClick={() => markViewed(match)}>
                        {isExpanded ? 'Hide details' : 'View'}
                      </button>

                      {attributes.can_respond && (
                        <>
                          <button
                            type="button"
                            className="btn btn-sm btn-mp-primary"
                            disabled={isBusy}
                            onClick={() => accept(match)}
                          >
                            Accept
                          </button>
                          <button
                            type="button"
                            className="btn btn-sm btn-mp-ghost text-danger"
                            disabled={isBusy}
                            onClick={() => decline(match)}
                          >
                            Decline
                          </button>
                          <button
                            type="button"
                            className="btn btn-sm btn-mp-ghost"
                            disabled={isBusy}
                            onClick={() => {
                              setQuestionFor(questionFor === match.id ? null : match.id);
                              setQuestionText(attributes.question ?? '');
                            }}
                          >
                            Ask a question
                          </button>
                        </>
                      )}

                      {!attributes.can_respond && attributes.responded_at && (
                        <span className="small text-muted">
                          You {attributes.response_status_label.toLowerCase()} this requirement.
                        </span>
                      )}
                    </div>

                    {questionFor === match.id && (
                      <div className="mt-3">
                        <label className="form-label small fw-semibold" htmlFor={`question-${match.id}`}>
                          Your question for the customer
                        </label>
                        <textarea
                          id={`question-${match.id}`}
                          className="form-control form-control-sm"
                          rows={3}
                          value={questionText}
                          maxLength={1000}
                          onChange={(event) => setQuestionText(event.target.value)}
                          placeholder="e.g. Is the quantity flexible, or is the required date firm?"
                        />
                        <div className="d-flex gap-2 mt-2">
                          <button
                            type="button"
                            className="btn btn-sm btn-mp-primary"
                            disabled={isBusy}
                            onClick={() => askQuestion(match)}
                          >
                            Send question
                          </button>
                          <button
                            type="button"
                            className="btn btn-sm btn-mp-ghost"
                            onClick={() => {
                              setQuestionFor(null);
                              setQuestionText('');
                            }}
                          >
                            Cancel
                          </button>
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
