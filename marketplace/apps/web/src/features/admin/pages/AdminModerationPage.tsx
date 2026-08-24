import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, formatPrice, getErrorMessage } from '../../../lib/api/client';
import type { ApiListing, PaginatedResponse } from '../../../types/api';
import StatusBadge from '../../../components/ui/StatusBadge';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';
import ErrorState from '../../../components/ui/ErrorState';
import { listingPath } from '../../../lib/urls';

export default function AdminModerationPage() {
  const [listings, setListings] = useState<ApiListing[]>([]);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [actionError, setActionError] = useState('');
  const [rejecting, setRejecting] = useState<ApiListing | null>(null);
  const [reason, setReason] = useState('');

  const load = () => {
    setStatus('loading');
    api
      .get<PaginatedResponse<ApiListing>>('/admin/listings', { params: { 'filter[status]': 'pending_review', per_page: 50 } })
      .then((res) => {
        setListings(res.data.data);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  useEffect(load, []);

  const approve = async (listing: ApiListing) => {
    setActionError('');
    try {
      await api.post(`/admin/listings/${listing.id}/approve`);
      load();
    } catch (err) {
      setActionError(getErrorMessage(err));
    }
  };

  const submitRejection = async () => {
    if (!rejecting || !reason.trim()) return;
    setActionError('');
    try {
      await api.post(`/admin/listings/${rejecting.id}/reject`, { reason });
      setRejecting(null);
      setReason('');
      load();
    } catch (err) {
      setActionError(getErrorMessage(err));
    }
  };

  return (
    <div className="mp-container py-4">
      <h1 className="h4 mb-4">Listing Moderation</h1>

      {actionError && <div className="alert alert-danger">{actionError}</div>}

      {status === 'loading' && <LoadingState variant="list" rows={5} />}
      {status === 'error' && <ErrorState onRetry={load} />}
      {status === 'ready' && listings.length === 0 && (
        <EmptyState icon="check" title="No listings pending review" description="You're all caught up." />
      )}

      {status === 'ready' && listings.length > 0 && (
        <div className="d-flex flex-column gap-2">
          {listings.map((listing) => {
            const media = listing.relationships.media ?? [];
            const primary = media.find((m) => m.attributes.is_primary) ?? media[0];
            return (
              <div key={listing.id} className="mp-card p-3 d-flex flex-wrap align-items-center gap-3">
                <div style={{ width: 88, height: 66, borderRadius: 10, overflow: 'hidden', flexShrink: 0, background: 'var(--mp-surface-muted)' }}>
                  {primary?.attributes.url && <img src={primary.attributes.url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />}
                </div>
                <div className="flex-grow-1">
                  <Link to={listingPath(listing)} className="fw-semibold text-dark text-decoration-none">
                    {listing.attributes.title}
                  </Link>
                  <div className="small text-muted">
                    {listing.relationships.user?.attributes.name} · {listing.relationships.category?.attributes.name} ·{' '}
                    {[listing.relationships.location?.attributes.area, listing.relationships.location?.attributes.city].filter(Boolean).join(', ')}
                  </div>
                  <div className="small text-muted">
                    {formatPrice(listing.attributes.price, listing.attributes.currency)} · Submitted{' '}
                    {listing.attributes.submitted_at ? new Date(listing.attributes.submitted_at).toLocaleDateString() : '—'}
                  </div>
                </div>
                <StatusBadge status={listing.attributes.system_status} />
                <div className="d-flex gap-2">
                  <Link to={listingPath(listing)} className="btn btn-sm btn-mp-outline">Review</Link>
                  <button className="btn btn-sm btn-mp-primary" onClick={() => approve(listing)}>Approve</button>
                  <button className="btn btn-sm btn-mp-ghost text-danger" onClick={() => setRejecting(listing)}>Reject</button>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {rejecting && (
        <div
          className="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
          style={{ background: 'rgba(20,32,30,0.5)', zIndex: 70 }}
          onClick={() => setRejecting(null)}
        >
          <div className="mp-card p-4" style={{ width: 420, maxWidth: '92vw' }} onClick={(e) => e.stopPropagation()}>
            <h2 className="h6">Reject "{rejecting.attributes.title}"</h2>
            <div className="mp-form-field my-3">
              <label>Reason (shown to the owner)</label>
              <textarea className="form-control" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
            </div>
            <div className="d-flex justify-content-end gap-2">
              <button className="btn btn-mp-ghost" onClick={() => setRejecting(null)}>Cancel</button>
              <button className="btn btn-mp-primary" onClick={submitRejection} disabled={!reason.trim()}>Reject Listing</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
