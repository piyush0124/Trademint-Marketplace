import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, getErrorMessage } from '../../../lib/api/client';
import type { ApiEnquiry, EnquiryStatus, EnquiryStatusCounts, PaginatedResponse } from '../../../types/api';
import StatusBadge from '../../../components/ui/StatusBadge';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';
import ErrorState from '../../../components/ui/ErrorState';
import { useAuth } from '../../auth/AuthContext';

/**
 * Enquiry list for both roles. One page, because it is one record: the API
 * returns the caller's own side of every conversation they are part of.
 *
 * Actions are driven by the API's `can_*` flags, so a customer never sees an
 * accept button and a business never sees a cancel button — and neither could
 * use one if it were rendered, because the backend re-authorises every call.
 */
const FILTERS: { value: EnquiryStatus | 'all'; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'pending', label: 'Pending' },
  { value: 'accepted', label: 'Accepted' },
  { value: 'declined', label: 'Declined' },
  { value: 'closed', label: 'Closed' },
  { value: 'cancelled', label: 'Cancelled' },
];

export default function EnquiriesPage() {
  const { isOwner } = useAuth();
  const [enquiries, setEnquiries] = useState<ApiEnquiry[]>([]);
  const [counts, setCounts] = useState<EnquiryStatusCounts | null>(null);
  const [filter, setFilter] = useState<EnquiryStatus | 'all'>('all');
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [emptyMessage, setEmptyMessage] = useState('');
  const [actionError, setActionError] = useState('');
  const [busyId, setBusyId] = useState<string | null>(null);

  const load = () => {
    setStatus('loading');
    const params = filter === 'all' ? { per_page: 50 } : { per_page: 50, 'filter[status]': filter };
    api
      .get<PaginatedResponse<ApiEnquiry, EnquiryStatusCounts>>('/enquiries', { params })
      .then((res) => {
        setEnquiries(res.data.data);
        setCounts(res.data.meta.status_counts ?? null);
        setEmptyMessage(res.data.message);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  useEffect(load, [filter]);

  const act = async (enquiry: ApiEnquiry, action: 'accept' | 'decline' | 'cancel') => {
    if (action === 'cancel' && !window.confirm('Cancel this enquiry?')) return;

    setActionError('');
    setBusyId(enquiry.id);
    try {
      await api.post(`/enquiries/${enquiry.id}/${action}`);
      load();
    } catch (err) {
      setActionError(getErrorMessage(err));
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div>
      <div className="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
          <h1 className="h5 mb-1">Enquiries</h1>
          <p className="text-muted small mb-0">
            {isOwner
              ? 'Requirements customers have asked you about. Accept one to unlock quoting.'
              : 'Businesses you have approached about your requirements.'}
          </p>
        </div>
      </div>

      {counts && (
        <div className="row g-3 mb-4">
          <div className="col-4">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{counts.pending}</div>
              <div className="mp-stat-card__label">Pending</div>
            </div>
          </div>
          <div className="col-4">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{counts.accepted}</div>
              <div className="mp-stat-card__label">Accepted</div>
            </div>
          </div>
          <div className="col-4">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{counts.declined}</div>
              <div className="mp-stat-card__label">Declined</div>
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

      {actionError && <div className="alert alert-danger">{actionError}</div>}

      {status === 'loading' && <LoadingState variant="list" rows={4} />}
      {status === 'error' && <ErrorState onRetry={load} />}

      {status === 'ready' && enquiries.length === 0 && (
        <EmptyState
          icon="chat"
          title="No enquiries yet."
          description={
            emptyMessage.includes('business profile')
              ? emptyMessage
              : isOwner
                ? 'When a customer approaches you about a matched requirement, it will appear here.'
                : 'Open one of your requirements, review the matched businesses and send an enquiry.'
          }
          action={
            isOwner ? (
              <Link to="/dashboard/matched-requirements" className="btn btn-mp-primary">View matched requirements</Link>
            ) : (
              <Link to="/dashboard/requirements" className="btn btn-mp-primary">View my requirements</Link>
            )
          }
        />
      )}

      {status === 'ready' && enquiries.length > 0 && (
        <div className="d-flex flex-column gap-2">
          {enquiries.map((enquiry) => {
            const a = enquiry.attributes;
            const requirement = enquiry.relationships.requirement;
            const business = enquiry.relationships.business;
            const isBusy = busyId === enquiry.id;

            return (
              <div key={enquiry.id} className={`mp-match-card status-${a.status}`}>
                <div className="d-flex justify-content-between align-items-start flex-wrap gap-2">
                  <div>
                    <div className="fw-semibold">
                      <Link to={`/dashboard/enquiries/${enquiry.id}`} className="text-dark text-decoration-none">
                        {requirement?.attributes.title || 'Untitled requirement'}
                      </Link>
                    </div>
                    <div className="small text-muted">
                      {requirement?.relationships.category?.attributes.name ?? 'No category'}
                      {requirement?.relationships.location && ` · ${requirement.relationships.location.attributes.city}`}
                      {/* A customer sees who they asked; a business is never shown
                          the customer's identity. */}
                      {!isOwner && business && ` · ${business.attributes.name}`}
                    </div>
                  </div>
                  <div className="text-end">
                    <StatusBadge status={a.status} />
                    {!!a.quotes_count && (
                      <div className="small text-muted mt-1">{a.quotes_count} quote{a.quotes_count === 1 ? '' : 's'}</div>
                    )}
                  </div>
                </div>

                <div className="d-flex gap-2 flex-wrap mt-3">
                  <Link to={`/dashboard/enquiries/${enquiry.id}`} className="btn btn-sm btn-mp-outline">View</Link>

                  {a.can_respond && (
                    <>
                      <button type="button" className="btn btn-sm btn-mp-primary" disabled={isBusy} onClick={() => act(enquiry, 'accept')}>
                        Accept
                      </button>
                      <button type="button" className="btn btn-sm btn-mp-ghost text-danger" disabled={isBusy} onClick={() => act(enquiry, 'decline')}>
                        Decline
                      </button>
                    </>
                  )}

                  {a.can_quote && (
                    <Link to={`/dashboard/quotes/new?enquiry=${enquiry.id}`} className="btn btn-sm btn-mp-accent">
                      Create quote
                    </Link>
                  )}

                  {a.can_cancel && (
                    <button type="button" className="btn btn-sm btn-mp-ghost text-danger" disabled={isBusy} onClick={() => act(enquiry, 'cancel')}>
                      Cancel
                    </button>
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
