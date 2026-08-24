import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../../lib/api/client';
import { formatMoney } from '../../../lib/money';
import type { ApiNegotiation, NegotiationStatusCounts, PaginatedResponse } from '../../../types/api';
import StatusBadge from '../../../components/ui/StatusBadge';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';
import ErrorState from '../../../components/ui/ErrorState';
import { useAuth } from '../../auth/AuthContext';

/**
 * Negotiation list for both roles, ordered by the one thing that matters on a
 * list like this: whether it is waiting on you.
 */
export default function NegotiationsPage() {
  const { isOwner } = useAuth();
  const [negotiations, setNegotiations] = useState<ApiNegotiation[]>([]);
  const [counts, setCounts] = useState<NegotiationStatusCounts | null>(null);
  const [onlyMine, setOnlyMine] = useState(false);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');

  const load = () => {
    setStatus('loading');
    const params: Record<string, string | number> = { per_page: 50 };
    if (onlyMine) params['filter[awaiting_me]'] = 1;

    api
      .get<PaginatedResponse<ApiNegotiation, NegotiationStatusCounts>>('/negotiations', { params })
      .then((res) => {
        setNegotiations(res.data.data);
        setCounts(res.data.meta.status_counts ?? null);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  useEffect(load, [onlyMine]);

  const awaitingMe = negotiations.filter((item) => item.attributes.is_my_turn).length;

  return (
    <div>
      <div className="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
          <h1 className="h5 mb-1">Negotiations</h1>
          <p className="text-muted small mb-0">
            Counter offers on your quotes. Every offer is kept in full — nothing is overwritten.
          </p>
        </div>
      </div>

      {counts && (
        <div className="row g-3 mb-4">
          <div className="col-4">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{awaitingMe}</div>
              <div className="mp-stat-card__label">Awaiting You</div>
            </div>
          </div>
          <div className="col-4">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{counts.countered + counts.open}</div>
              <div className="mp-stat-card__label">Active</div>
            </div>
          </div>
          <div className="col-4">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{counts.accepted}</div>
              <div className="mp-stat-card__label">Agreed</div>
            </div>
          </div>
        </div>
      )}

      <div className="form-check form-switch mb-3">
        <input className="form-check-input" type="checkbox" role="switch" id="awaiting-me"
          checked={onlyMine} onChange={(e) => setOnlyMine(e.target.checked)} />
        <label className="form-check-label small" htmlFor="awaiting-me">Only show negotiations waiting on me</label>
      </div>

      {status === 'loading' && <LoadingState variant="list" rows={4} />}
      {status === 'error' && <ErrorState onRetry={load} />}

      {status === 'ready' && negotiations.length === 0 && (
        <EmptyState
          icon="handshake"
          title={onlyMine ? 'Nothing is waiting on you.' : 'No negotiations yet.'}
          description={
            onlyMine
              ? 'Turn the filter off to see negotiations waiting on the other party.'
              : isOwner
                ? 'When a customer counters one of your quotes, it will appear here.'
                : 'Open a quote you have received and counter it to start negotiating.'
          }
          action={<Link to="/dashboard/quotes" className="btn btn-mp-primary">Go to quotes</Link>}
        />
      )}

      {status === 'ready' && negotiations.length > 0 && (
        <div className="d-flex flex-column gap-2">
          {negotiations.map((negotiation) => {
            const a = negotiation.attributes;
            const revision = negotiation.relationships.current_revision;
            const requirement = negotiation.relationships.requirement;
            const quote = negotiation.relationships.quote;

            return (
              <div key={negotiation.id} className={`mp-match-card status-${a.status} ${a.is_my_turn ? 'is-awaiting-me' : ''}`}>
                <div className="d-flex justify-content-between align-items-start flex-wrap gap-2">
                  <div>
                    <div className="fw-semibold">
                      <Link to={`/dashboard/negotiations/${negotiation.id}`} className="text-dark text-decoration-none">
                        {requirement?.attributes.title || quote?.attributes.title || 'Negotiation'}
                      </Link>
                    </div>
                    <div className="small text-muted">
                      {quote?.attributes.quote_number}
                      {` · round ${a.round}`}
                      {revision && ` · latest offer from ${revision.attributes.authored_by_label}`}
                    </div>
                  </div>
                  <div className="text-end">
                    {revision && (
                      <div className="fw-semibold">{formatMoney(revision.attributes.total_amount, revision.attributes.currency)}</div>
                    )}
                    <div className="mt-1 d-flex gap-2 justify-content-end align-items-center flex-wrap">
                      {a.is_my_turn && <span className="mp-badge-featured">Your move</span>}
                      <StatusBadge status={a.status} />
                    </div>
                  </div>
                </div>

                <div className="d-flex gap-2 flex-wrap mt-3">
                  <Link to={`/dashboard/negotiations/${negotiation.id}`} className="btn btn-sm btn-mp-outline">
                    {a.is_my_turn ? 'Respond' : 'View'}
                  </Link>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
