import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../../lib/api/client';
import { formatMoney } from '../../../lib/money';
import type {
  ApiListing,
  ApiNegotiation,
  ApiRequirementMatch,
  EnquiryStatusCounts,
  MatchListResponse,
  NegotiationStatusCounts,
  PaginatedResponse,
  QuoteStatusCounts,
} from '../../../types/api';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';
import StatusBadge from '../../../components/ui/StatusBadge';

/**
 * The business's home, ordered by revenue opportunity rather than by table.
 *
 * The primary call to action is "View Opportunities" because an unanswered
 * matched requirement is the most perishable thing on this screen — quotes and
 * listings can wait, an expiring opportunity cannot.
 *
 * Panels load independently (allSettled), so one failing endpoint degrades a
 * single card instead of the whole dashboard.
 */
export default function OwnerDashboardPage() {
  const [opportunities, setOpportunities] = useState<ApiRequirementMatch[]>([]);
  const [pendingOpportunities, setPendingOpportunities] = useState(0);
  const [enquiryCounts, setEnquiryCounts] = useState<EnquiryStatusCounts | null>(null);
  const [quoteCounts, setQuoteCounts] = useState<QuoteStatusCounts | null>(null);
  const [negotiations, setNegotiations] = useState<ApiNegotiation[]>([]);
  const [negotiationCounts, setNegotiationCounts] = useState<NegotiationStatusCounts | null>(null);
  const [listings, setListings] = useState<ApiListing[]>([]);
  const [newLeads, setNewLeads] = useState(0);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.allSettled([
      api.get<MatchListResponse>('/users/me/matched-requirements', { params: { per_page: 5, 'filter[response_status]': 'pending' } }),
      api.get<PaginatedResponse<unknown, EnquiryStatusCounts>>('/enquiries', { params: { per_page: 1 } }),
      api.get<PaginatedResponse<unknown, QuoteStatusCounts>>('/quotes', { params: { per_page: 1 } }),
      api.get<PaginatedResponse<ApiNegotiation, NegotiationStatusCounts>>('/negotiations', { params: { per_page: 5 } }),
      api.get<PaginatedResponse<ApiListing, unknown>>('/users/me/listings', { params: { per_page: 100 } }),
      api.get<PaginatedResponse<unknown, { new: number }>>('/users/me/leads', { params: { per_page: 1 } }),
    ])
      .then(([opportunitiesRes, enquiriesRes, quotesRes, negotiationsRes, listingsRes, leadsRes]) => {
        if (opportunitiesRes.status === 'fulfilled') {
          setOpportunities(opportunitiesRes.value.data.data);
          setPendingOpportunities(opportunitiesRes.value.data.meta.response_counts?.pending ?? 0);
        }
        if (enquiriesRes.status === 'fulfilled') setEnquiryCounts(enquiriesRes.value.data.meta.status_counts ?? null);
        if (quotesRes.status === 'fulfilled') setQuoteCounts(quotesRes.value.data.meta.status_counts ?? null);
        if (negotiationsRes.status === 'fulfilled') {
          setNegotiations(negotiationsRes.value.data.data);
          setNegotiationCounts(negotiationsRes.value.data.meta.status_counts ?? null);
        }
        if (listingsRes.status === 'fulfilled') setListings(listingsRes.value.data.data);
        if (leadsRes.status === 'fulfilled') setNewLeads(leadsRes.value.data.meta.status_counts?.new ?? 0);
      })
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <LoadingState rows={4} />;

  const awaitingResponse = (quoteCounts?.sent ?? 0) + (quoteCounts?.viewed ?? 0);
  const activeNegotiations = (negotiationCounts?.open ?? 0) + (negotiationCounts?.countered ?? 0);
  const myMove = negotiations.filter((item) => item.attributes.is_my_turn);
  const pendingEnquiries = enquiryCounts?.pending ?? 0;
  const publishedListings = listings.filter((listing) => listing.attributes.system_status === 'published').length;
  const draftQuotes = quoteCounts?.draft ?? 0;

  const actions: { key: string; label: string; to: string; cta: string }[] = [];
  if (pendingOpportunities > 0) {
    actions.push({
      key: 'opportunities',
      label: `${pendingOpportunities} matched requirement${pendingOpportunities === 1 ? '' : 's'} you have not responded to`,
      to: '/dashboard/matched-requirements',
      cta: 'Review',
    });
  }
  if (pendingEnquiries > 0) {
    actions.push({
      key: 'enquiries',
      label: `${pendingEnquiries} enquir${pendingEnquiries === 1 ? 'y' : 'ies'} awaiting your accept or decline`,
      to: '/dashboard/enquiries',
      cta: 'Respond',
    });
  }
  if (myMove.length > 0) {
    actions.push({
      key: 'negotiations',
      label: `${myMove.length} counter offer${myMove.length === 1 ? '' : 's'} waiting on you`,
      to: '/dashboard/negotiations',
      cta: 'Respond',
    });
  }
  if (draftQuotes > 0) {
    actions.push({
      key: 'drafts',
      label: `${draftQuotes} draft quote${draftQuotes === 1 ? '' : 's'} not yet sent`,
      to: '/dashboard/quotes',
      cta: 'Finish and send',
    });
  }

  return (
    <div>
      <div className="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
        <div>
          <h2 className="h5 mb-1">Your business</h2>
          <p className="text-muted small mb-0">Opportunities matched to what you do, and the deals in flight.</p>
        </div>
        <Link to="/dashboard/matched-requirements" className="btn btn-mp-accent">View Opportunities</Link>
      </div>

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
        <div className="col-6 col-md-4 col-xl-2">
          <Link to="/dashboard/matched-requirements" className="text-decoration-none">
            <div className="mp-stat-card" style={pendingOpportunities > 0 ? { borderColor: 'var(--mp-accent)' } : undefined}>
              <div className="mp-stat-card__value">{pendingOpportunities}</div>
              <div className="mp-stat-card__label">New Opportunities</div>
            </div>
          </Link>
        </div>
        <div className="col-6 col-md-4 col-xl-2">
          <Link to="/dashboard/enquiries" className="text-decoration-none">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{pendingEnquiries}</div>
              <div className="mp-stat-card__label">Pending Enquiries</div>
            </div>
          </Link>
        </div>
        <div className="col-6 col-md-4 col-xl-2">
          <Link to="/dashboard/quotes" className="text-decoration-none">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{awaitingResponse}</div>
              <div className="mp-stat-card__label">Quotes Awaiting Reply</div>
            </div>
          </Link>
        </div>
        <div className="col-6 col-md-4 col-xl-2">
          <Link to="/dashboard/negotiations" className="text-decoration-none">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{activeNegotiations}</div>
              <div className="mp-stat-card__label">Active Negotiations</div>
            </div>
          </Link>
        </div>
        <div className="col-6 col-md-4 col-xl-2">
          <Link to="/dashboard/listings" className="text-decoration-none">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{publishedListings}</div>
              <div className="mp-stat-card__label">Live Listings</div>
            </div>
          </Link>
        </div>
        <div className="col-6 col-md-4 col-xl-2">
          <Link to="/dashboard/leads" className="text-decoration-none">
            <div className="mp-stat-card" style={newLeads > 0 ? { borderColor: 'var(--mp-accent)' } : undefined}>
              <div className="mp-stat-card__value">{newLeads}</div>
              <div className="mp-stat-card__label">New Enquiries</div>
            </div>
          </Link>
        </div>
      </div>

      <div className="row g-4">
        <div className="col-12 col-xl-6">
          <div className="d-flex justify-content-between align-items-center mb-2">
            <h3 className="h6 mb-0">Latest opportunities</h3>
            <Link to="/dashboard/matched-requirements" className="btn btn-sm btn-mp-ghost">View all</Link>
          </div>

          {opportunities.length === 0 ? (
            <EmptyState
              icon="inbox"
              title="No open opportunities."
              description="Keep your categories, service areas and capabilities current — the better your profile, the more requirements you are matched with."
              action={<Link to="/dashboard/business" className="btn btn-mp-primary">Improve profile</Link>}
            />
          ) : (
            <div className="mp-card">
              {opportunities.map((match, index) => (
                <div
                  key={match.id}
                  className="d-flex justify-content-between align-items-center gap-2 p-3"
                  style={{ borderBottom: index < opportunities.length - 1 ? '1px solid var(--mp-border)' : 'none' }}
                >
                  <div className="min-width-0">
                    <div className="fw-semibold">{match.relationships.requirement?.attributes.title ?? 'Requirement'}</div>
                    <div className="small text-muted">
                      {match.relationships.requirement?.relationships.category?.attributes.name}
                      {match.relationships.requirement?.relationships.location &&
                        ` · ${match.relationships.requirement.relationships.location.attributes.city}`}
                    </div>
                  </div>
                  <div className="text-end">
                    <div className="fw-semibold">{match.attributes.match_percentage}%</div>
                    <div className="small text-muted">match</div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="col-12 col-xl-6">
          <div className="d-flex justify-content-between align-items-center mb-2">
            <h3 className="h6 mb-0">Deals in flight</h3>
            <Link to="/dashboard/negotiations" className="btn btn-sm btn-mp-ghost">View all</Link>
          </div>

          {negotiations.length === 0 ? (
            <EmptyState
              icon="handshake"
              title="No active negotiations."
              description="When a customer counters one of your quotes, the conversation appears here."
            />
          ) : (
            <div className="mp-card">
              {negotiations.map((negotiation, index) => {
                const revision = negotiation.relationships.current_revision;

                return (
                  <div
                    key={negotiation.id}
                    className="d-flex justify-content-between align-items-center gap-2 p-3"
                    style={{ borderBottom: index < negotiations.length - 1 ? '1px solid var(--mp-border)' : 'none' }}
                  >
                    <div className="min-width-0">
                      <Link to={`/dashboard/negotiations/${negotiation.id}`} className="fw-semibold text-dark text-decoration-none">
                        {negotiation.relationships.requirement?.attributes.title ?? 'Negotiation'}
                      </Link>
                      <div className="small text-muted">round {negotiation.attributes.round}</div>
                    </div>
                    <div className="text-end">
                      {revision && (
                        <div className="fw-semibold">{formatMoney(revision.attributes.total_amount, revision.attributes.currency)}</div>
                      )}
                      <div className="d-flex gap-1 justify-content-end align-items-center mt-1">
                        {negotiation.attributes.is_my_turn && <span className="mp-badge-featured">Your move</span>}
                        <StatusBadge status={negotiation.attributes.status} />
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
