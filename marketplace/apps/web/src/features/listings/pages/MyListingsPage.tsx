import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, formatPrice, getErrorMessage } from '../../../lib/api/client';
import type { ApiListing, PaginatedResponse, SystemStatus } from '../../../types/api';
import StatusBadge from '../../../components/ui/StatusBadge';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';
import ErrorState from '../../../components/ui/ErrorState';
import { listingPath } from '../../../lib/urls';

const STATUS_FILTERS: { value: SystemStatus | 'all'; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'draft', label: 'Draft' },
  { value: 'pending_review', label: 'Pending Review' },
  { value: 'published', label: 'Published' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'archived', label: 'Archived' },
];

export default function MyListingsPage() {
  const [listings, setListings] = useState<ApiListing[]>([]);
  const [filter, setFilter] = useState<SystemStatus | 'all'>('all');
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [actionError, setActionError] = useState('');

  const load = () => {
    setStatus('loading');
    const params = filter === 'all' ? { per_page: 50 } : { per_page: 50, 'filter[status]': filter };
    api
      .get<PaginatedResponse<ApiListing>>('/users/me/listings', { params })
      .then((res) => {
        setListings(res.data.data);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  useEffect(load, [filter]);

  const runAction = async (action: () => Promise<unknown>) => {
    setActionError('');
    try {
      await action();
      load();
    } catch (err) {
      setActionError(getErrorMessage(err));
    }
  };

  return (
    <div>
      <div className="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h1 className="h5 mb-0">My Listings</h1>
        <Link to="/listings/new" className="btn btn-mp-accent btn-sm">+ Add Listing</Link>
      </div>

      <div className="d-flex gap-2 mb-3 flex-wrap">
        {STATUS_FILTERS.map((f) => (
          <button
            key={f.value}
            type="button"
            className={`btn btn-sm ${filter === f.value ? 'btn-mp-primary' : 'btn-mp-outline'}`}
            onClick={() => setFilter(f.value)}
          >
            {f.label}
          </button>
        ))}
      </div>

      {actionError && <div className="alert alert-danger">{actionError}</div>}

      {status === 'loading' && <LoadingState variant="list" rows={5} />}
      {status === 'error' && <ErrorState onRetry={load} />}
      {status === 'ready' && listings.length === 0 && (
        <EmptyState
          icon="building"
          title="You haven't added any listings yet."
          action={<Link to="/listings/new" className="btn btn-mp-primary">Add Your First Listing</Link>}
        />
      )}

      {status === 'ready' && listings.length > 0 && (
        <div className="d-flex flex-column gap-2">
          {listings.map((listing) => (
            <div key={listing.id} className="mp-card p-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
              <div>
                <div className="fw-semibold">{listing.attributes.title || 'Untitled draft'}</div>
                <div className="small text-muted">{formatPrice(listing.attributes.price || 0, listing.attributes.currency)}</div>
                {listing.attributes.system_status === 'rejected' && listing.attributes.rejection_reason && (
                  <div className="small text-danger mt-1">Rejected: {listing.attributes.rejection_reason}</div>
                )}
              </div>
              <div className="d-flex align-items-center gap-2 flex-wrap">
                <StatusBadge status={listing.attributes.system_status} />
                {listing.attributes.system_status === 'published' && <StatusBadge status={listing.attributes.availability_status} />}

                {listing.attributes.is_editable && (
                  <Link to={`/dashboard/listings/${listing.id}/edit`} className="btn btn-sm btn-mp-outline">Edit</Link>
                )}
                {listing.attributes.is_editable && (
                  <button
                    className="btn btn-sm btn-mp-primary"
                    onClick={() => runAction(() => api.post(`/listings/${listing.id}/submit`))}
                  >
                    Submit for Review
                  </button>
                )}
                {listing.attributes.system_status === 'published' && listing.attributes.availability_status === 'available' && (
                  <>
                    <button className="btn btn-sm btn-mp-outline" onClick={() => runAction(() => api.post(`/listings/${listing.id}/mark-sold`))}>
                      Mark Sold
                    </button>
                    <button className="btn btn-sm btn-mp-outline" onClick={() => runAction(() => api.post(`/listings/${listing.id}/mark-rented`))}>
                      Mark Rented
                    </button>
                  </>
                )}
                {listing.attributes.system_status !== 'archived' && (
                  <button
                    className="btn btn-sm btn-mp-ghost text-danger"
                    onClick={() => {
                      if (window.confirm('Archive this listing? It will no longer appear publicly.')) {
                        runAction(() => api.delete(`/listings/${listing.id}`));
                      }
                    }}
                  >
                    Archive
                  </button>
                )}
                <Link to={listingPath(listing)} className="btn btn-sm btn-mp-ghost">View</Link>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
