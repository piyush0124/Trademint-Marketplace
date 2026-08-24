import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../../lib/api/client';
import { formatMoney } from '../../../lib/money';
import type { ApiDispute, DisputeStatus, PaginatedResponse } from '../../../types/api';
import StatusBadge from '../../../components/ui/StatusBadge';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';
import ErrorState from '../../../components/ui/ErrorState';

/**
 * Disputes the caller is party to.
 *
 * No actions here at all: raising happens on the order, deciding is admin-only. This
 * screen exists so a party can see where their complaint stands without having to
 * remember which order it was on.
 */
const FILTERS: { value: DisputeStatus | 'all'; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'open', label: 'Open' },
  { value: 'under_review', label: 'Under review' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'rejected', label: 'Rejected' },
];

function humanise(reason: string): string {
  return reason.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
}

export default function DisputesPage() {
  const [disputes, setDisputes] = useState<ApiDispute[]>([]);
  const [filter, setFilter] = useState<DisputeStatus | 'all'>('all');
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');

  const load = () => {
    setStatus('loading');
    const params: Record<string, string | number> = { per_page: 50 };
    if (filter !== 'all') params['filter[status]'] = filter;

    api
      .get<PaginatedResponse<ApiDispute>>('/disputes', { params })
      .then((res) => {
        setDisputes(res.data.data);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  useEffect(load, [filter]);

  return (
    <div>
      <div className="mb-3">
        <h1 className="h5 mb-1">Disputes</h1>
        <p className="text-muted small mb-0">
          Complaints raised on your orders, and where each one stands. An administrator decides the outcome.
        </p>
      </div>

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

      {status === 'loading' && <LoadingState variant="list" rows={3} />}
      {status === 'error' && <ErrorState onRetry={load} />}

      {status === 'ready' && disputes.length === 0 && (
        <EmptyState
          icon="handshake"
          title="No disputes."
          description="Nothing has gone wrong that needed escalating. If it does, you can raise a dispute from the order."
          action={<Link to="/dashboard/orders" className="btn btn-mp-outline">Go to orders</Link>}
        />
      )}

      {status === 'ready' && disputes.length > 0 && (
        <div className="d-flex flex-column gap-2">
          {disputes.map((dispute) => {
            const a = dispute.attributes;
            const order = dispute.relationships.order ?? null;

            return (
              <div key={dispute.id} className={`mp-match-card status-${a.status}`}>
                <div className="d-flex justify-content-between align-items-start flex-wrap gap-2">
                  <div className="min-width-0">
                    <div className="fw-semibold">{humanise(a.reason)}</div>
                    <div className="small text-muted">
                      {a.reference}
                      {order && (
                        <>
                          {' · '}
                          <Link to={`/dashboard/orders/${order.id}`} className="text-decoration-none">{order.order_number}</Link>
                          {' · '}{formatMoney(order.total_amount, order.currency)}
                        </>
                      )}
                      {dispute.relationships.business && ` · ${dispute.relationships.business.name}`}
                      {a.created_at && ` · raised ${a.created_at.slice(0, 10)} by the ${a.raised_by_party}`}
                    </div>
                  </div>
                  <StatusBadge status={a.status} />
                </div>

                <p className="small mt-2 mb-0">{a.description}</p>
                {a.evidence && <p className="small text-muted mt-1 mb-0">{a.evidence}</p>}

                {a.resolution && (
                  <div className="mt-3 p-3 mp-counter-summary">
                    <div className="small fw-semibold">
                      Outcome{a.resolved_at ? ` · ${a.resolved_at.slice(0, 10)}` : ''}
                    </div>
                    <div className="small">{a.resolution}</div>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
