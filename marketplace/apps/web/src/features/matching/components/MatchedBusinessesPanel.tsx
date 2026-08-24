import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, getErrorMessage } from '../../../lib/api/client';
import type {
  ApiEnquiry,
  ApiRequirementMatch,
  EnquiryStatusCounts,
  MatchListResponse,
  MatchResponseCounts,
  PaginatedResponse,
  RequirementStatus,
} from '../../../types/api';
import LoadingState from '../../../components/ui/LoadingState';
import ErrorState from '../../../components/ui/ErrorState';
import EmptyState from '../../../components/ui/EmptyState';
import StatusBadge from '../../../components/ui/StatusBadge';
import MatchScore from './MatchScore';
import MatchBreakdown from './MatchBreakdown';

interface MatchedBusinessesPanelProps {
  requirementId: string;
  requirementStatus: RequirementStatus;
}

/** Matching only ever runs on a requirement that's actually open to businesses. */
const MATCHABLE_STATUSES: RequirementStatus[] = ['published', 'matching', 'receiving_quotes'];

function locationLabel(match: ApiRequirementMatch): string {
  const location = match.relationships.business?.relationships?.location;
  if (!location) return 'Location not set';

  return [location.attributes.city, location.attributes.state].filter(Boolean).join(', ');
}

function ratingLabel(rating: string | null, reviewCount: number): string {
  if (rating === null) return 'No ratings yet';

  return `★ ${Number(rating).toFixed(1)} (${reviewCount} review${reviewCount === 1 ? '' : 's'})`;
}

/** Keyed by business id so a match row can find its own conversation in O(1). */
function indexEnquiries(enquiries: ApiEnquiry[]): Record<number, ApiEnquiry> {
  return enquiries.reduce<Record<number, ApiEnquiry>>((accumulator, enquiry) => {
    const businessId = enquiry.relationships.business?.id;
    if (businessId) accumulator[businessId] = enquiry;

    return accumulator;
  }, {});
}

/**
 * The customer's view of the matching engine's output: which businesses were
 * found, how strong each match is, and why.
 *
 * Read-only by design. The customer can re-run matching on their own
 * requirement, but accepting or declining belongs to the business — this
 * panel only reports what each one has said so far.
 */
export default function MatchedBusinessesPanel({ requirementId, requirementStatus }: MatchedBusinessesPanelProps) {
  const [matches, setMatches] = useState<ApiRequirementMatch[]>([]);
  const [counts, setCounts] = useState<MatchResponseCounts | null>(null);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [message, setMessage] = useState('');
  const [actionError, setActionError] = useState('');
  const [isMatching, setIsMatching] = useState(false);
  const [expanded, setExpanded] = useState<string | null>(null);
  // Phase 5: enquiries already opened on this requirement, keyed by business id,
  // so each match shows either "send enquiry" or where that conversation got to.
  const [enquiriesByBusiness, setEnquiriesByBusiness] = useState<Record<number, ApiEnquiry>>({});
  const [enquiringId, setEnquiringId] = useState<string | null>(null);

  const load = () => {
    setStatus('loading');

    Promise.all([
      api.get<MatchListResponse>(`/requirements/${requirementId}/matches`),
      api.get<PaginatedResponse<ApiEnquiry, EnquiryStatusCounts>>('/enquiries', {
        params: { per_page: 100, 'filter[requirement]': requirementId },
      }),
    ])
      .then(([matchesRes, enquiriesRes]) => {
        setMatches(matchesRes.data.data);
        setCounts(matchesRes.data.meta.response_counts ?? null);
        setMessage(matchesRes.data.message);
        setEnquiriesByBusiness(indexEnquiries(enquiriesRes.data.data));
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  useEffect(load, [requirementId]);

  const sendEnquiry = async (match: ApiRequirementMatch) => {
    setActionError('');
    setEnquiringId(match.id);
    try {
      const res = await api.post<{ data: ApiEnquiry }>(`/requirements/${requirementId}/enquiries`, {
        match_id: match.id,
      });
      const enquiry = res.data.data;
      const businessId = enquiry.relationships.business?.id ?? match.relationships.business?.id;
      if (businessId) {
        setEnquiriesByBusiness((current) => ({ ...current, [businessId]: enquiry }));
      }
    } catch (err) {
      setActionError(getErrorMessage(err));
    } finally {
      setEnquiringId(null);
    }
  };

  const runMatching = async () => {
    setActionError('');
    setIsMatching(true);
    try {
      const res = await api.post<MatchListResponse>(`/requirements/${requirementId}/matches`);
      setMatches(res.data.data);
      setCounts(res.data.meta.response_counts ?? null);
      setMessage(res.data.message);
    } catch (err) {
      setActionError(getErrorMessage(err));
    } finally {
      setIsMatching(false);
    }
  };

  const canRunMatching = MATCHABLE_STATUSES.includes(requirementStatus);

  return (
    <div className="mp-card p-4">
      <div className="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
          <h5 className="mb-1">Matched Businesses</h5>
          <p className="text-muted small mb-0">
            Suitable businesses found for this requirement, strongest match first.
          </p>
        </div>
        {canRunMatching && (
          <button type="button" className="btn btn-sm btn-mp-outline" onClick={runMatching} disabled={isMatching}>
            {isMatching ? 'Finding matches…' : matches.length > 0 ? 'Refresh matches' : 'Find matches'}
          </button>
        )}
      </div>

      {actionError && <div className="alert alert-danger py-2 small">{actionError}</div>}

      {counts && matches.length > 0 && (
        <div className="d-flex gap-2 flex-wrap mb-3 small text-muted">
          <span className="mp-status-badge mp-status-pending">{counts.pending} awaiting reply</span>
          <span className="mp-status-badge mp-status-accepted">{counts.accepted} accepted</span>
          <span className="mp-status-badge mp-status-declined">{counts.declined} declined</span>
        </div>
      )}

      {status === 'loading' && <LoadingState variant="list" rows={3} />}
      {status === 'error' && <ErrorState message="Could not load matched businesses." onRetry={load} />}

      {status === 'ready' && matches.length === 0 && (
        <EmptyState
          icon="compass"
          title={
            canRunMatching
              ? 'No suitable businesses matched yet.'
              : 'Matching starts once you publish this requirement.'
          }
          description={message}
        />
      )}

      {status === 'ready' && matches.length > 0 && (
        <div className="d-flex flex-column gap-3">
          {matches.map((match) => {
            const business = match.relationships.business;
            const attributes = match.attributes;
            const isExpanded = expanded === match.id;
            const enquiry = business ? enquiriesByBusiness[business.id] : undefined;

            return (
              <div key={match.id} className={`mp-match-card status-${attributes.response_status}`}>
                <div className="d-flex gap-3 align-items-start">
                  <MatchScore percentage={attributes.match_percentage} />

                  <div className="flex-grow-1">
                    <div className="d-flex justify-content-between align-items-start flex-wrap gap-2">
                      <div>
                        <div className="fw-semibold">
                          {business ? (
                            <Link to={`/business/${business.attributes.slug}`} className="text-dark text-decoration-none">
                              {business.attributes.name}
                            </Link>
                          ) : (
                            'Business unavailable'
                          )}
                        </div>
                        <div className="small text-muted">
                          {locationLabel(match)}
                          {business?.relationships?.category && ` · ${business.relationships.category.attributes.name}`}
                        </div>
                      </div>
                      <span className={`mp-status-badge mp-status-${attributes.response_status}`}>
                        {attributes.response_status_label}
                      </span>
                    </div>

                    {/* Trust and availability, shown only where the data exists —
                        an unrated business reads as "No ratings yet", never 0. */}
                    <div className="d-flex gap-2 flex-wrap align-items-center mt-2 small">
                      <span className="text-muted">{ratingLabel(business?.attributes.rating ?? null, business?.attributes.review_count ?? 0)}</span>
                      {business?.attributes.is_fully_verified ? (
                        <span className="mp-badge-verified">Fully verified</span>
                      ) : (
                        business?.attributes.is_verified && <span className="mp-badge-verified">Verified</span>
                      )}
                      {!!business?.attributes.years_in_business && (
                        <span className="text-muted">{business.attributes.years_in_business} yrs in business</span>
                      )}
                      <span className={business?.attributes.is_available ? 'text-success' : 'text-danger'}>
                        {business?.attributes.is_available ? 'Available for work' : 'Not accepting work'}
                      </span>
                    </div>

                    {attributes.capabilities.length > 0 && (
                      <div className="mt-2">
                        {attributes.capabilities.map((capability) => (
                          <span key={`${capability.type}-${capability.name}`} className="mp-match-capability">
                            {capability.name}
                          </span>
                        ))}
                      </div>
                    )}

                    {attributes.reasons.length > 0 && (
                      <ul className="mp-match-reasons mt-2">
                        {attributes.reasons.slice(0, isExpanded ? undefined : 3).map((reason) => (
                          <li key={reason}>{reason}</li>
                        ))}
                      </ul>
                    )}

                    {attributes.question && (
                      <div className="alert alert-warning py-2 px-3 small mt-2 mb-0">
                        <strong>Question from this business:</strong> {attributes.question}
                      </div>
                    )}

                    {attributes.response_note && (
                      <div className="small text-muted mt-2">Their note: {attributes.response_note}</div>
                    )}

                    <div className="d-flex gap-3 align-items-center flex-wrap mt-2">
                      <button
                        type="button"
                        className="btn btn-sm btn-mp-ghost px-0"
                        onClick={() => setExpanded(isExpanded ? null : match.id)}
                        aria-expanded={isExpanded}
                      >
                        {isExpanded ? 'Hide score details' : 'Why this match?'}
                      </button>
                      {attributes.viewed_at && <span className="small text-muted">Viewed by business</span>}

                      {/* Phase 5 entry point: either open the conversation, or
                          continue the one that already exists. */}
                      {enquiry ? (
                        <span className="d-inline-flex align-items-center gap-2">
                          <StatusBadge status={enquiry.attributes.status} />
                          <Link to={`/dashboard/enquiries/${enquiry.id}`} className="btn btn-sm btn-mp-outline">
                            {(enquiry.attributes.quotes_count ?? 0) > 0 ? 'View quotes' : 'View enquiry'}
                          </Link>
                        </span>
                      ) : (
                        canRunMatching && (
                          <button
                            type="button"
                            className="btn btn-sm btn-mp-primary"
                            disabled={enquiringId === match.id}
                            onClick={() => sendEnquiry(match)}
                          >
                            {enquiringId === match.id ? 'Sending…' : 'Send enquiry'}
                          </button>
                        )
                      )}
                    </div>

                    {isExpanded && (
                      <div className="mt-3">
                        <MatchBreakdown breakdown={attributes.score_breakdown} />
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
