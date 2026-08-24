import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, getErrorMessage } from '../../../lib/api/client';
import { formatMoney } from '../../../lib/money';
import type { QuoteComparisonResponse, QuoteComparisonRow } from '../../../types/api';
import LoadingState from '../../../components/ui/LoadingState';
import ErrorState from '../../../components/ui/ErrorState';
import EmptyState from '../../../components/ui/EmptyState';
import StatusBadge from '../../../components/ui/StatusBadge';

/**
 * Multi-vendor quote comparison for one requirement.
 *
 * Every "best" marker is computed by the backend so the comparison is honest and
 * identical for everyone. Where a comparison would be misleading — quotes in
 * different currencies, a service quote with no delivery date — the page says so
 * instead of inventing a winner.
 *
 * Badges are text, not colour alone, so the "cheapest" row is identifiable
 * without relying on hue (WCAG 1.4.1).
 */
export default function QuoteComparisonPage() {
  const { id } = useParams<{ id: string }>();

  const [data, setData] = useState<QuoteComparisonResponse | null>(null);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error' | 'forbidden'>('loading');
  const [error, setError] = useState('');

  const load = () => {
    if (!id) return;
    setStatus('loading');
    api
      .get<QuoteComparisonResponse>(`/requirements/${id}/quotes/comparison`)
      .then((res) => {
        setData(res.data);
        setStatus('ready');
      })
      .catch((err) => {
        setError(getErrorMessage(err));
        setStatus(err?.response?.status === 403 ? 'forbidden' : 'error');
      });
  };

  useEffect(load, [id]);

  if (status === 'loading') return <LoadingState variant="list" rows={4} />;
  if (status === 'forbidden') return <ErrorState message="Only the customer who raised this requirement can compare its quotes." />;
  if (status === 'error' || !data) return <ErrorState message={error || 'Could not load the comparison.'} onRetry={load} />;

  const rows = data.meta.comparison.totals;
  const { comparison, requirement } = data.meta;

  const badgesFor = (row: QuoteComparisonRow): string[] => {
    const badges: string[] = [];
    if (comparison.lowest_total_quote_id === row.quote_id) badges.push('Lowest price');
    if (comparison.fastest_quote_id === row.quote_id) badges.push('Soonest');
    if (comparison.best_rated_quote_id === row.quote_id) badges.push('Best rated');
    if (row.within_budget === true) badges.push('In budget');

    return badges;
  };

  return (
    <div>
      <div className="mb-3">
        <Link to={`/requirements/${id}`} className="btn btn-sm btn-mp-ghost px-0 mb-1">← Back to requirement</Link>
        <h1 className="h5 mb-1">Compare quotes</h1>
        <p className="text-muted small mb-0">
          {requirement.title}
          {requirement.quantity && ` · ${Number(requirement.quantity).toLocaleString('en-IN')} ${requirement.unit ?? ''}`}
          {requirement.required_date && ` · needed by ${requirement.required_date}`}
        </p>
      </div>

      {rows.length === 0 && (
        <EmptyState
          icon="chart"
          title="No quotes to compare yet."
          description="Once matched businesses send quotations, they will line up here side by side."
        />
      )}

      {rows.length > 0 && (
        <>
          {comparison.comparable_on_price === false && (
            <div className="alert alert-warning py-2 small" role="alert">
              These quotes are in different currencies ({comparison.currencies.join(', ')}), so they are not compared on
              price. Review the totals individually.
            </div>
          )}

          <div className="table-responsive">
            <table className="table align-middle mp-compare-table">
              <caption className="small text-muted">
                {rows.length} quote{rows.length === 1 ? '' : 's'} received. Rankings are calculated server-side.
              </caption>
              <thead>
                <tr>
                  <th scope="col">Business</th>
                  <th scope="col" className="text-end">Total</th>
                  <th scope="col">Timing</th>
                  <th scope="col">Trust</th>
                  <th scope="col">Terms</th>
                  <th scope="col">Status</th>
                  <th scope="col" className="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => {
                  const badges = badgesFor(row);

                  return (
                    <tr key={row.quote_id}>
                      <th scope="row" className="fw-semibold">
                        {row.business_name ?? 'Business'}
                        <div className="small text-muted fw-normal">{row.quote_number}</div>
                        {badges.length > 0 && (
                          <div className="mt-1 d-flex gap-1 flex-wrap">
                            {badges.map((badge) => (
                              <span key={badge} className="mp-badge-featured">{badge}</span>
                            ))}
                          </div>
                        )}
                      </th>
                      <td className="text-end">
                        <div className="fw-semibold">{formatMoney(row.total_amount, row.currency)}</div>
                        {row.within_budget === false && <div className="small text-danger">Over budget</div>}
                        {row.revision_count > 1 && (
                          <div className="small text-muted">after {row.revision_count - 1} counter{row.revision_count > 2 ? 's' : ''}</div>
                        )}
                      </td>
                      <td className="small">
                        {row.estimated_delivery_date && <div>Delivery {row.estimated_delivery_date}</div>}
                        {row.estimated_completion_date && <div>Completion {row.estimated_completion_date}</div>}
                        {!row.estimated_delivery_date && !row.estimated_completion_date && <span className="text-muted">Not stated</span>}
                        {row.validity_date && <div className="text-muted">Valid to {row.validity_date}</div>}
                      </td>
                      <td className="small">
                        {row.business_is_verified && <div className="mp-badge-verified">Verified</div>}
                        <div>{row.business_rating !== null ? `★ ${Number(row.business_rating).toFixed(1)}` : 'No ratings yet'}</div>
                      </td>
                      <td className="small" style={{ maxWidth: 220 }}>
                        {row.warranty_terms && <div className="text-truncate" title={row.warranty_terms}>Warranty: {row.warranty_terms}</div>}
                        {row.payment_terms && <div className="text-truncate" title={row.payment_terms}>Payment: {row.payment_terms}</div>}
                        {!row.warranty_terms && !row.payment_terms && <span className="text-muted">Not stated</span>}
                      </td>
                      <td>
                        <StatusBadge status={row.status} />
                        {row.is_negotiating && <div className="small text-muted mt-1">Negotiating</div>}
                        {row.is_expired && <div className="small text-danger mt-1">Expired</div>}
                      </td>
                      <td className="text-end">
                        <Link to={`/dashboard/quotes/${row.quote_id}`} className="btn btn-sm btn-mp-outline">Open</Link>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  );
}
