import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../../lib/api/client';
import { formatMoney } from '../../../lib/money';
import type { AdminDashboardResponse } from '../../../types/api';
import LoadingState from '../../../components/ui/LoadingState';
import ErrorState from '../../../components/ui/ErrorState';

/**
 * Platform overview for staff.
 *
 * Structured around "what needs attention" first and "how big is this thing" second,
 * because the former is actionable and the latter is only interesting. Every figure
 * is a count from the API — there is no client-side arithmetic on money here.
 *
 * Note what is absent: no endpoint on this screen lets an admin act commercially for
 * a party. Staff can moderate and resolve; they cannot accept a quote, pay an order
 * or answer a negotiation.
 */
type Data = AdminDashboardResponse['data'];

export default function AdminDashboardPage() {
  const [data, setData] = useState<Data | null>(null);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');

  const load = () => {
    setStatus('loading');
    api
      .get<AdminDashboardResponse>('/admin/dashboard')
      .then((res) => {
        setData(res.data.data);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  useEffect(load, []);

  if (status === 'loading') return <div className="mp-container py-4"><LoadingState variant="list" rows={5} /></div>;
  if (status === 'error' || data === null) return <div className="mp-container py-4"><ErrorState onRetry={load} /></div>;

  const attention = [
    { label: 'Pending Verifications', value: data.attention.pending_verifications, to: '/admin/oversight?tab=businesses' },
    { label: 'Open Disputes', value: data.attention.open_disputes, to: '/admin/oversight?tab=disputes' },
    { label: 'Flagged Reviews', value: data.attention.flagged_reviews, to: '/admin/oversight?tab=reviews' },
    { label: 'Failed Payments', value: data.attention.failed_payments, to: '/admin/oversight?tab=audit' },
  ];

  return (
    <div className="mp-container py-4">
      <div className="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
          <h1 className="h4 mb-1">Platform Overview</h1>
          <p className="text-muted small mb-0">Counts only — no personal data on this screen.</p>
        </div>
        <div className="d-flex gap-2">
          <Link to="/admin/listings" className="btn btn-sm btn-mp-outline">Listing moderation</Link>
          <Link to="/admin/oversight" className="btn btn-sm btn-mp-primary">Trust &amp; disputes</Link>
        </div>
      </div>

      <h2 className="h6 text-uppercase text-muted mb-2" style={{ letterSpacing: '0.04em', fontSize: '0.75rem' }}>
        Needs attention
      </h2>
      <div className="row g-3 mb-4">
        {attention.map((item) => (
          <div key={item.label} className="col-6 col-md-3">
            <Link to={item.to} className="text-decoration-none">
              <div className={`mp-stat-card ${item.value > 0 ? 'is-attention' : ''}`}>
                <div className="mp-stat-card__value">{item.value}</div>
                <div className="mp-stat-card__label">{item.label}</div>
              </div>
            </Link>
          </div>
        ))}
      </div>

      <div className="row g-3">
        <div className="col-12 col-lg-4">
          <div className="mp-card p-4 h-100">
            <h2 className="h6 mb-3">People</h2>
            <dl className="mp-quote-totals mb-0">
              <div><dt>Total users</dt><dd>{data.users.total}</dd></div>
              <div><dt>Customers</dt><dd>{data.users.customers}</dd></div>
              <div><dt>Business owners</dt><dd>{data.users.owners}</dd></div>
            </dl>
          </div>
        </div>

        <div className="col-12 col-lg-4">
          <div className="mp-card p-4 h-100">
            <h2 className="h6 mb-3">Businesses</h2>
            <dl className="mp-quote-totals mb-0">
              <div><dt>Total</dt><dd>{data.businesses.total}</dd></div>
              <div><dt>Verified</dt><dd>{data.businesses.verified}</dd></div>
              <div><dt>Awaiting review</dt><dd>{data.businesses.pending}</dd></div>
              <div><dt>Suspended</dt><dd>{data.businesses.suspended}</dd></div>
            </dl>
          </div>
        </div>

        <div className="col-12 col-lg-4">
          <div className="mp-card p-4 h-100">
            <h2 className="h6 mb-3">Trade</h2>
            <dl className="mp-quote-totals mb-0">
              <div><dt>Requirements</dt><dd>{data.marketplace.requirements}</dd></div>
              <div><dt>Quotes</dt><dd>{data.marketplace.quotes}</dd></div>
              <div><dt>Orders</dt><dd>{data.marketplace.orders}</dd></div>
              <div><dt>Completed orders</dt><dd>{data.marketplace.completed_orders}</dd></div>
              <div><dt>Reviews</dt><dd>{data.marketplace.reviews}</dd></div>
            </dl>
          </div>
        </div>

        <div className="col-12">
          <div className="mp-card p-4">
            <h2 className="h6 mb-3">Settled money</h2>
            <p className="small text-muted">
              Settled payments only. Anything still in flight is excluded so the figure means something.
            </p>
            {data.money.currencies_in_use.length > 1 && (
              <div className="alert alert-warning py-2 small" role="alert">
                Payments exist in {data.money.currencies_in_use.join(', ')}. These totals add them together
                without conversion, so treat them as indicative only.
              </div>
            )}
            <div className="row g-3">
              <div className="col-12 col-md-4">
                <div className="mp-stat-card">
                  <div className="mp-stat-card__value" style={{ fontSize: '1.4rem' }}>
                    {formatMoney(data.money.paid_total, data.money.currency)}
                  </div>
                  <div className="mp-stat-card__label">Received</div>
                </div>
              </div>
              <div className="col-12 col-md-4">
                <div className="mp-stat-card">
                  <div className="mp-stat-card__value" style={{ fontSize: '1.4rem' }}>
                    {formatMoney(data.money.refunded_total, data.money.currency)}
                  </div>
                  <div className="mp-stat-card__label">Refunded</div>
                </div>
              </div>
              <div className="col-12 col-md-4">
                <div className="mp-stat-card">
                  <div className="mp-stat-card__value" style={{ fontSize: '1.4rem' }}>{data.money.settled_payments}</div>
                  <div className="mp-stat-card__label">Settled Payments</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
