import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../../lib/api/client';
import { formatMoney } from '../../../lib/money';
import type { ApiOrder, OrderStatus, OrderStatusCounts, PaginatedResponse } from '../../../types/api';
import StatusBadge from '../../../components/ui/StatusBadge';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';
import ErrorState from '../../../components/ui/ErrorState';
import { useAuth } from '../../auth/AuthContext';

/**
 * Order list for both roles, grouped by what the user needs to do about them.
 *
 * The business view leads with orders awaiting confirmation, the customer view
 * with payment and completion — the two genuinely different jobs this screen does.
 */
const FILTERS: { value: OrderStatus | 'all' | 'open'; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'open', label: 'Open' },
  { value: 'pending', label: 'Awaiting confirmation' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'delivered', label: 'Delivered' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
];

export default function OrdersPage() {
  const { isOwner } = useAuth();
  const [orders, setOrders] = useState<ApiOrder[]>([]);
  const [counts, setCounts] = useState<OrderStatusCounts | null>(null);
  const [filter, setFilter] = useState<OrderStatus | 'all' | 'open'>('all');
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');

  const load = () => {
    setStatus('loading');
    const params: Record<string, string | number> = { per_page: 50 };
    if (filter === 'open') params['filter[open]'] = 1;
    else if (filter !== 'all') params['filter[status]'] = filter;

    api
      .get<PaginatedResponse<ApiOrder, OrderStatusCounts>>('/orders', { params })
      .then((res) => {
        setOrders(res.data.data);
        setCounts(res.data.meta.status_counts ?? null);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  useEffect(load, [filter]);

  return (
    <div>
      <div className="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
          <h1 className="h5 mb-1">Orders</h1>
          <p className="text-muted small mb-0">
            {isOwner ? 'Work you have won, and where each order stands.' : 'Orders created from quotes you accepted.'}
          </p>
        </div>
      </div>

      {counts && (
        <div className="row g-3 mb-4">
          <div className="col-6 col-md-3">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{counts.pending}</div>
              <div className="mp-stat-card__label">{isOwner ? 'To Confirm' : 'Awaiting Confirmation'}</div>
            </div>
          </div>
          <div className="col-6 col-md-3">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{counts.confirmed + counts.processing + counts.in_progress + counts.ready}</div>
              <div className="mp-stat-card__label">In Progress</div>
            </div>
          </div>
          <div className="col-6 col-md-3">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{counts.dispatched + counts.delivered}</div>
              <div className="mp-stat-card__label">Awaiting Completion</div>
            </div>
          </div>
          <div className="col-6 col-md-3">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{counts.completed}</div>
              <div className="mp-stat-card__label">Completed</div>
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

      {status === 'ready' && orders.length === 0 && (
        <EmptyState
          icon="package"
          title="No orders here yet."
          description={
            isOwner
              ? 'When a customer accepts one of your quotes, the order appears here ready to confirm.'
              : 'Accept a quote and the order will be created for you automatically.'
          }
          action={<Link to="/dashboard/quotes" className="btn btn-mp-primary">Go to quotes</Link>}
        />
      )}

      {status === 'ready' && orders.length > 0 && (
        <div className="d-flex flex-column gap-2">
          {orders.map((order) => {
            const a = order.attributes;

            return (
              <div key={order.id} className={`mp-match-card status-${a.status}`}>
                <div className="d-flex justify-content-between align-items-start flex-wrap gap-2">
                  <div className="min-width-0">
                    <div className="fw-semibold">
                      <Link to={`/dashboard/orders/${order.id}`} className="text-dark text-decoration-none">
                        {order.relationships.requirement?.attributes.title || a.order_number}
                      </Link>
                    </div>
                    <div className="small text-muted">
                      {a.order_number}
                      {!isOwner && order.relationships.business && ` · ${order.relationships.business.attributes.name}`}
                      {a.expected_delivery_date && ` · delivery ${a.expected_delivery_date}`}
                      {a.expected_completion_date && ` · completion ${a.expected_completion_date}`}
                    </div>
                    {/* What should I do next — the point of an order row. */}
                    {a.next_action && <div className="small mt-1">{a.next_action}</div>}
                  </div>
                  <div className="text-end">
                    <div className="fw-semibold">{formatMoney(a.total_amount, a.currency)}</div>
                    <div className="d-flex gap-2 justify-content-end mt-1 flex-wrap">
                      <StatusBadge status={a.status} />
                      <span className={`mp-status-badge mp-status-${a.payment_status}`}>{a.payment_status_label}</span>
                    </div>
                  </div>
                </div>

                <div className="d-flex gap-2 flex-wrap mt-3">
                  <Link to={`/dashboard/orders/${order.id}`} className="btn btn-sm btn-mp-outline">
                    {a.available_transitions.length > 0 ? 'Manage' : 'View'}
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
