import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../../lib/api/client';
import { formatMoney } from '../../../lib/money';
import type { ApiPayment, PaginatedResponse, PaymentStatus, PaymentStatusCounts } from '../../../types/api';
import StatusBadge from '../../../components/ui/StatusBadge';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';
import ErrorState from '../../../components/ui/ErrorState';
import { useAuth } from '../../auth/AuthContext';

/**
 * Payment history for both roles.
 *
 * Read-only on purpose: paying, confirming and refunding all happen on the order
 * they belong to, where the balance and the agreement are in view. This screen
 * answers "what has money done" and nothing else.
 */
const FILTERS: { value: PaymentStatus | 'all'; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'pending', label: 'Pending' },
  { value: 'paid', label: 'Settled' },
  { value: 'failed', label: 'Failed' },
  { value: 'partially_refunded', label: 'Partly refunded' },
  { value: 'refunded', label: 'Refunded' },
];

export default function PaymentsPage() {
  const { isOwner } = useAuth();
  const [payments, setPayments] = useState<ApiPayment[]>([]);
  const [counts, setCounts] = useState<PaymentStatusCounts | null>(null);
  const [filter, setFilter] = useState<PaymentStatus | 'all'>('all');
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');

  const load = () => {
    setStatus('loading');
    const params: Record<string, string | number> = { per_page: 50 };
    if (filter !== 'all') params['filter[status]'] = filter;

    api
      .get<PaginatedResponse<ApiPayment, PaymentStatusCounts>>('/payments', { params })
      .then((res) => {
        setPayments(res.data.data);
        setCounts(res.data.meta.status_counts ?? null);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  useEffect(load, [filter]);

  const settled = counts ? counts.paid + counts.partially_refunded : 0;

  return (
    <div>
      <div className="mb-3">
        <h1 className="h5 mb-1">Payments</h1>
        <p className="text-muted small mb-0">
          {isOwner
            ? 'Money received against your orders. Confirm and refund from the order itself.'
            : 'What you have paid, and anything refunded back to you.'}
        </p>
      </div>

      {counts && (
        <div className="row g-3 mb-4">
          <div className="col-6 col-md-3">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{settled}</div>
              <div className="mp-stat-card__label">Settled</div>
            </div>
          </div>
          <div className="col-6 col-md-3">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{counts.pending + counts.initiated + counts.processing}</div>
              <div className="mp-stat-card__label">In Flight</div>
            </div>
          </div>
          <div className="col-6 col-md-3">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{counts.failed + counts.cancelled}</div>
              <div className="mp-stat-card__label">Failed or Cancelled</div>
            </div>
          </div>
          <div className="col-6 col-md-3">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{counts.refunded + counts.partially_refunded}</div>
              <div className="mp-stat-card__label">Refunded</div>
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

      {status === 'loading' && <LoadingState variant="list" rows={4} />}
      {status === 'error' && <ErrorState onRetry={load} />}

      {status === 'ready' && payments.length === 0 && (
        <EmptyState
          icon="card"
          title="No payments yet."
          description={
            isOwner
              ? 'Once a customer pays an order, the record appears here.'
              : 'Payments you make against your orders will be listed here.'
          }
          action={<Link to="/dashboard/orders" className="btn btn-mp-primary">Go to orders</Link>}
        />
      )}

      {status === 'ready' && payments.length > 0 && (
        <div className="d-flex flex-column gap-2">
          {payments.map((payment) => {
            const a = payment.attributes;
            const order = payment.relationships.order ?? null;
            const refunds = payment.relationships.refunds ?? [];

            return (
              <div key={payment.id} className="mp-card p-3">
                <div className="d-flex justify-content-between align-items-start flex-wrap gap-2">
                  <div className="min-width-0">
                    <div className="fw-semibold small">{a.payment_reference}</div>
                    <div className="small text-muted">
                      {order ? (
                        <Link to={`/dashboard/orders/${order.id}`} className="text-decoration-none">
                          {order.attributes.order_number}
                        </Link>
                      ) : (
                        '—'
                      )}
                      {' · '}{a.provider}
                      {a.payment_method && ` · ${a.payment_method}`}
                      {a.paid_at ? ` · paid ${a.paid_at.slice(0, 10)}` : a.created_at ? ` · started ${a.created_at.slice(0, 10)}` : ''}
                    </div>
                    {isOwner && payment.relationships.business === null && order?.relationships.business && (
                      <div className="small text-muted">{order.relationships.business.attributes.name}</div>
                    )}
                    {a.failure_reason && <div className="small text-danger">{a.failure_reason}</div>}
                  </div>
                  <div className="text-end">
                    <div className="fw-semibold">{formatMoney(a.amount, a.currency)}</div>
                    <StatusBadge status={a.status} />
                    {Number(a.amount_refunded) > 0 && (
                      <div className="small text-muted mt-1">{formatMoney(a.amount_refunded, a.currency)} refunded</div>
                    )}
                  </div>
                </div>

                {refunds.length > 0 && (
                  <ul className="list-unstyled small text-muted mt-2 mb-0">
                    {refunds.map((refund) => (
                      <li key={refund.id}>
                        {refund.attributes.refund_reference}: {formatMoney(refund.attributes.amount, refund.attributes.currency)}
                        {' · '}{refund.attributes.status_label}
                        {refund.attributes.reason ? ` · ${refund.attributes.reason}` : ''}
                      </li>
                    ))}
                  </ul>
                )}

                {order && (
                  <div className="mt-2">
                    <Link to={`/dashboard/orders/${order.id}`} className="btn btn-sm btn-mp-outline">Open order</Link>
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
