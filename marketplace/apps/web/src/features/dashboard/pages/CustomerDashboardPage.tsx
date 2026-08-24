import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../../lib/api/client';
import { formatMoney } from '../../../lib/money';
import type {
  ApiNegotiation,
  ApiQuote,
  ApiRequirement,
  EnquiryStatusCounts,
  NegotiationStatusCounts,
  PaginatedResponse,
  QuoteStatusCounts,
  RequirementStatusCounts,
} from '../../../types/api';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';
import StatusBadge from '../../../components/ui/StatusBadge';

/**
 * The customer's home, designed around what needs their attention rather than
 * around the database.
 *
 * Reading order answers: what needs me now (action list), where do things stand
 * (counts), and what is the one thing I most likely came here to do (create a
 * requirement).
 *
 * Every panel degrades independently — a failing endpoint shows an empty panel,
 * never a blank dashboard.
 */
interface Counts {
  requirements?: RequirementStatusCounts;
  enquiries?: EnquiryStatusCounts;
  quotes?: QuoteStatusCounts;
  negotiations?: NegotiationStatusCounts;
}

export default function CustomerDashboardPage() {
  const [counts, setCounts] = useState<Counts>({});
  const [requirements, setRequirements] = useState<ApiRequirement[]>([]);
  const [quotes, setQuotes] = useState<ApiQuote[]>([]);
  const [negotiations, setNegotiations] = useState<ApiNegotiation[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.allSettled([
      api.get<PaginatedResponse<ApiRequirement, RequirementStatusCounts>>('/users/me/requirements', { params: { per_page: 5 } }),
      api.get<PaginatedResponse<unknown, EnquiryStatusCounts>>('/enquiries', { params: { per_page: 1 } }),
      api.get<PaginatedResponse<ApiQuote, QuoteStatusCounts>>('/quotes', { params: { per_page: 5 } }),
      api.get<PaginatedResponse<ApiNegotiation, NegotiationStatusCounts>>('/negotiations', { params: { per_page: 5 } }),
    ])
      .then(([requirementsRes, enquiriesRes, quotesRes, negotiationsRes]) => {
        const next: Counts = {};

        if (requirementsRes.status === 'fulfilled') {
          setRequirements(requirementsRes.value.data.data);
          next.requirements = requirementsRes.value.data.meta.status_counts as RequirementStatusCounts | undefined;
        }
        if (enquiriesRes.status === 'fulfilled') next.enquiries = enquiriesRes.value.data.meta.status_counts;
        if (quotesRes.status === 'fulfilled') {
          setQuotes(quotesRes.value.data.data);
          next.quotes = quotesRes.value.data.meta.status_counts;
        }
        if (negotiationsRes.status === 'fulfilled') {
          setNegotiations(negotiationsRes.value.data.data);
          next.negotiations = negotiationsRes.value.data.meta.status_counts;
        }

        setCounts(next);
      })
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <LoadingState variant="list" rows={4} />;

  const activeRequirements = (counts.requirements?.published ?? 0) + (counts.requirements?.matching ?? 0) + (counts.requirements?.receiving_quotes ?? 0);
  const pendingQuotes = (counts.quotes?.sent ?? 0) + (counts.quotes?.viewed ?? 0);
  const activeNegotiations = (counts.negotiations?.open ?? 0) + (counts.negotiations?.countered ?? 0);
  const myMove = negotiations.filter((item) => item.attributes.is_my_turn);
  const decidableQuotes = quotes.filter((quote) => quote.attributes.can_decide);

  // The action list: concrete things only this user can unblock.
  const actions: { key: string; label: string; to: string; cta: string }[] = [];
  if (decidableQuotes.length > 0) {
    actions.push({
      key: 'quotes',
      label: `${decidableQuotes.length} quote${decidableQuotes.length === 1 ? '' : 's'} awaiting your decision`,
      to: '/dashboard/quotes',
      cta: 'Review quotes',
    });
  }
  if (myMove.length > 0) {
    actions.push({
      key: 'negotiations',
      label: `${myMove.length} negotiation${myMove.length === 1 ? '' : 's'} waiting on your response`,
      to: '/dashboard/negotiations',
      cta: 'Respond',
    });
  }
  if ((counts.requirements?.draft ?? 0) > 0) {
    actions.push({
      key: 'drafts',
      label: `${counts.requirements?.draft} draft requirement${counts.requirements?.draft === 1 ? '' : 's'} not yet published`,
      to: '/dashboard/requirements',
      cta: 'Finish and publish',
    });
  }

  return (
    <div>
      <div className="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
        <div>
          <h2 className="h5 mb-1">Your marketplace</h2>
          <p className="text-muted small mb-0">Tell the marketplace what you need and let suitable businesses come to you.</p>
        </div>
        <Link to="/requirements/new" className="btn btn-mp-accent">+ Create Requirement</Link>
      </div>

      {/* What needs my attention — first, and in plain words. */}
      {actions.length > 0 && (
        <div className="mp-card p-3 mb-4" role="region" aria-label="Needs your attention">
          <h3 className="h6 mb-3">Needs your attention</h3>
          <ul className="list-unstyled mb-0 d-flex flex-column gap-2">
            {actions.map((action) => (
              <li key={action.key} className="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span className="small">{action.label}</span>
                <Link to={action.to} className="btn btn-sm btn-mp-primary">{action.cta}</Link>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="row g-3 mb-4">
        <div className="col-6 col-md-3">
          <Link to="/dashboard/requirements" className="text-decoration-none">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{activeRequirements}</div>
              <div className="mp-stat-card__label">Active Requirements</div>
            </div>
          </Link>
        </div>
        <div className="col-6 col-md-3">
          <Link to="/dashboard/enquiries" className="text-decoration-none">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{counts.enquiries?.accepted ?? 0}</div>
              <div className="mp-stat-card__label">Accepted Enquiries</div>
            </div>
          </Link>
        </div>
        <div className="col-6 col-md-3">
          <Link to="/dashboard/quotes" className="text-decoration-none">
            <div className="mp-stat-card" style={pendingQuotes > 0 ? { borderColor: 'var(--mp-accent)' } : undefined}>
              <div className="mp-stat-card__value">{pendingQuotes}</div>
              <div className="mp-stat-card__label">Pending Quotes</div>
            </div>
          </Link>
        </div>
        <div className="col-6 col-md-3">
          <Link to="/dashboard/negotiations" className="text-decoration-none">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{activeNegotiations}</div>
              <div className="mp-stat-card__label">Active Negotiations</div>
            </div>
          </Link>
        </div>
      </div>

      <div className="row g-4">
        <div className="col-12 col-xl-6">
          <div className="d-flex justify-content-between align-items-center mb-2">
            <h3 className="h6 mb-0">Recent requirements</h3>
            <Link to="/dashboard/requirements" className="btn btn-sm btn-mp-ghost">View all</Link>
          </div>

          {requirements.length === 0 ? (
            <EmptyState
              icon="clipboard"
              title="No requirements yet."
              description="A requirement describes what you need — the marketplace finds businesses that can provide it."
              action={<Link to="/requirements/new" className="btn btn-mp-primary">Create your first requirement</Link>}
            />
          ) : (
            <div className="mp-card">
              {requirements.map((requirement, index) => (
                <div
                  key={requirement.id}
                  className="d-flex justify-content-between align-items-center gap-2 p-3"
                  style={{ borderBottom: index < requirements.length - 1 ? '1px solid var(--mp-border)' : 'none' }}
                >
                  <div className="min-width-0">
                    <Link to={`/requirements/${requirement.id}`} className="fw-semibold text-dark text-decoration-none">
                      {requirement.attributes.title || 'Untitled draft'}
                    </Link>
                    <div className="small text-muted">
                      {requirement.relationships.category?.attributes.name ?? 'No category'}
                      {requirement.relationships.location && ` · ${requirement.relationships.location.attributes.city}`}
                    </div>
                  </div>
                  <StatusBadge status={requirement.attributes.status} />
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="col-12 col-xl-6">
          <div className="d-flex justify-content-between align-items-center mb-2">
            <h3 className="h6 mb-0">Latest quotes</h3>
            <Link to="/dashboard/quotes" className="btn btn-sm btn-mp-ghost">View all</Link>
          </div>

          {quotes.length === 0 ? (
            <EmptyState
              icon="receipt"
              title="No quotes yet."
              description="Publish a requirement, then send enquiries to matched businesses — their quotations arrive here."
            />
          ) : (
            <div className="mp-card">
              {quotes.map((quote, index) => (
                <div
                  key={quote.id}
                  className="d-flex justify-content-between align-items-center gap-2 p-3"
                  style={{ borderBottom: index < quotes.length - 1 ? '1px solid var(--mp-border)' : 'none' }}
                >
                  <div className="min-width-0">
                    <Link to={`/dashboard/quotes/${quote.id}`} className="fw-semibold text-dark text-decoration-none">
                      {quote.attributes.title}
                    </Link>
                    <div className="small text-muted">
                      {quote.relationships.business?.attributes.name ?? quote.attributes.quote_number}
                    </div>
                  </div>
                  <div className="text-end">
                    <div className="fw-semibold">{formatMoney(quote.attributes.total_amount, quote.attributes.currency)}</div>
                    <StatusBadge status={quote.attributes.status} />
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
