import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../../lib/api/client';
import { formatMoney } from '../../../lib/money';
import type { ApiQuote, PaginatedResponse, QuoteStatus, QuoteStatusCounts } from '../../../types/api';
import StatusBadge from '../../../components/ui/StatusBadge';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';
import ErrorState from '../../../components/ui/ErrorState';
import { useAuth } from '../../auth/AuthContext';

/**
 * Quote list, shared by both roles. The API scopes it — a business sees the
 * quotes it issued (drafts included), a customer sees the quotes they have
 * received (drafts excluded, since an unsent quote is private to the business).
 */
const FILTERS: { value: QuoteStatus | 'all'; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'draft', label: 'Drafts' },
  { value: 'sent', label: 'Sent' },
  { value: 'viewed', label: 'Viewed' },
  { value: 'accepted', label: 'Accepted' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'expired', label: 'Expired' },
  { value: 'withdrawn', label: 'Withdrawn' },
];

export default function QuotesPage() {
  const { isOwner } = useAuth();
  const [quotes, setQuotes] = useState<ApiQuote[]>([]);
  const [counts, setCounts] = useState<QuoteStatusCounts | null>(null);
  const [filter, setFilter] = useState<QuoteStatus | 'all'>('all');
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');

  const load = () => {
    setStatus('loading');
    const params = filter === 'all' ? { per_page: 50 } : { per_page: 50, 'filter[status]': filter };
    api
      .get<PaginatedResponse<ApiQuote, QuoteStatusCounts>>('/quotes', { params })
      .then((res) => {
        setQuotes(res.data.data);
        setCounts(res.data.meta.status_counts ?? null);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  useEffect(load, [filter]);

  // A customer never sees drafts, so offering the filter would be misleading.
  const filters = isOwner ? FILTERS : FILTERS.filter((f) => f.value !== 'draft');

  return (
    <div>
      <div className="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
          <h1 className="h5 mb-1">{isOwner ? 'Quotes issued' : 'Quotes received'}</h1>
          <p className="text-muted small mb-0">
            {isOwner
              ? 'Quotations you have prepared for matched requirements.'
              : 'Quotations businesses have sent you. Accept the one that fits.'}
          </p>
        </div>
      </div>

      <div className="d-flex gap-2 mb-3 flex-wrap">
        {filters.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`btn btn-sm ${filter === option.value ? 'btn-mp-primary' : 'btn-mp-outline'}`}
            onClick={() => setFilter(option.value)}
          >
            {option.label}
            {counts && option.value !== 'all' && counts[option.value] > 0 && (
              <span className="ms-1 mp-lead-status-tab__count">{counts[option.value]}</span>
            )}
          </button>
        ))}
      </div>

      {status === 'loading' && <LoadingState variant="list" rows={4} />}
      {status === 'error' && <ErrorState onRetry={load} />}

      {status === 'ready' && quotes.length === 0 && (
        <EmptyState
          icon="receipt"
          title="No quotes here yet."
          description={
            isOwner
              ? 'Accept a matched requirement, then build a quote from the enquiry.'
              : 'Once a matched business sends you a quotation it will appear here.'
          }
          action={
            isOwner ? (
              <Link to="/dashboard/enquiries" className="btn btn-mp-primary">Go to enquiries</Link>
            ) : (
              <Link to="/dashboard/requirements" className="btn btn-mp-primary">View my requirements</Link>
            )
          }
        />
      )}

      {status === 'ready' && quotes.length > 0 && (
        <div className="d-flex flex-column gap-2">
          {quotes.map((quote) => {
            const a = quote.attributes;

            return (
              <div key={quote.id} className={`mp-match-card status-${a.status}`}>
                <div className="d-flex justify-content-between align-items-start flex-wrap gap-2">
                  <div>
                    <div className="fw-semibold">
                      <Link to={`/dashboard/quotes/${quote.id}`} className="text-dark text-decoration-none">
                        {a.title}
                      </Link>
                    </div>
                    <div className="small text-muted">
                      {a.quote_number}
                      {quote.relationships.requirement?.attributes.title && ` · ${quote.relationships.requirement.attributes.title}`}
                      {!isOwner && quote.relationships.business && ` · ${quote.relationships.business.attributes.name}`}
                    </div>
                    <div className="small text-muted mt-1">
                      {a.validity_date ? `Valid until ${a.validity_date}` : 'No validity date'}
                      {a.estimated_delivery_date && ` · Delivery ${a.estimated_delivery_date}`}
                      {a.estimated_completion_date && ` · Completion ${a.estimated_completion_date}`}
                    </div>
                  </div>
                  <div className="text-end">
                    <div className="fw-semibold">{formatMoney(a.total_amount, a.currency)}</div>
                    <div className="mt-1"><StatusBadge status={a.status} /></div>
                  </div>
                </div>

                <div className="d-flex gap-2 flex-wrap mt-3">
                  <Link to={`/dashboard/quotes/${quote.id}`} className="btn btn-sm btn-mp-outline">View</Link>
                  {a.can_edit && (
                    <Link to={`/dashboard/quotes/${quote.id}/edit`} className="btn btn-sm btn-mp-ghost">Edit draft</Link>
                  )}
                  {a.can_decide && (
                    <Link to={`/dashboard/quotes/${quote.id}`} className="btn btn-sm btn-mp-primary">Review &amp; decide</Link>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
