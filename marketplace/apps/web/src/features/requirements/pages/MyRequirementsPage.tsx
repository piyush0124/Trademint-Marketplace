import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, getErrorMessage } from '../../../lib/api/client';
import type { ApiRequirement, PaginatedResponse, RequirementStatus } from '../../../types/api';
import StatusBadge from '../../../components/ui/StatusBadge';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';
import ErrorState from '../../../components/ui/ErrorState';

const STATUS_FILTERS: { value: RequirementStatus | 'all'; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'draft', label: 'Draft' },
  { value: 'published', label: 'Active' },
  { value: 'matching', label: 'Matching' },
  { value: 'receiving_quotes', label: 'Receiving Quotes' },
  { value: 'closed', label: 'Closed' },
  { value: 'cancelled', label: 'Cancelled' },
];

function formatBudget(min: string | null, max: string | null): string {
  if (!min && !max) return 'Budget not set';
  if (min && max) return `₹${Number(min).toLocaleString('en-IN')} – ₹${Number(max).toLocaleString('en-IN')}`;
  return `Up to ₹${Number(min ?? max).toLocaleString('en-IN')}`;
}

export default function MyRequirementsPage() {
  const [requirements, setRequirements] = useState<ApiRequirement[]>([]);
  const [filter, setFilter] = useState<RequirementStatus | 'all'>('all');
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [actionError, setActionError] = useState('');

  const load = () => {
    setStatus('loading');
    const params = filter === 'all' ? { per_page: 50 } : { per_page: 50, 'filter[status]': filter };
    api
      .get<PaginatedResponse<ApiRequirement>>('/users/me/requirements', { params })
      .then((res) => {
        setRequirements(res.data.data);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  useEffect(load, [filter]);

  const runAction = async (action: () => Promise<unknown>, confirmMessage?: string) => {
    if (confirmMessage && !window.confirm(confirmMessage)) return;
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
        <h1 className="h5 mb-0">My Requirements</h1>
        <Link to="/requirements/new" className="btn btn-mp-accent btn-sm">+ New Requirement</Link>
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
      {status === 'ready' && requirements.length === 0 && (
        <EmptyState
          icon="clipboard"
          title="You haven't raised any requirements yet."
          description="Tell us what you need and the marketplace will help find suitable businesses — no need to browse listings first."
          action={<Link to="/requirements/new" className="btn btn-mp-primary">Create Your First Requirement</Link>}
        />
      )}

      {status === 'ready' && requirements.length > 0 && (
        <div className="d-flex flex-column gap-2">
          {requirements.map((requirement) => (
            <div key={requirement.id} className="mp-card p-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
              <div>
                <div className="fw-semibold">{requirement.attributes.title || 'Untitled draft'}</div>
                <div className="small text-muted">
                  {requirement.relationships.category?.attributes.name ?? 'No category'}
                  {' · '}
                  {formatBudget(requirement.attributes.budget_min, requirement.attributes.budget_max)}
                  {requirement.relationships.location && ` · ${requirement.relationships.location.attributes.city}`}
                </div>
              </div>
              <div className="d-flex align-items-center gap-2 flex-wrap">
                <StatusBadge status={requirement.attributes.status} />

                {requirement.attributes.is_editable && (
                  <Link to={`/dashboard/requirements/${requirement.id}/edit`} className="btn btn-sm btn-mp-outline">Edit</Link>
                )}
                {requirement.attributes.status === 'draft' && (
                  <button
                    className="btn btn-sm btn-mp-primary"
                    onClick={() => runAction(() => api.post(`/requirements/${requirement.id}/publish`))}
                  >
                    Publish
                  </button>
                )}
                {requirement.attributes.is_cancellable && (
                  <button
                    className="btn btn-sm btn-mp-ghost text-danger"
                    onClick={() =>
                      runAction(
                        () => api.post(`/requirements/${requirement.id}/cancel`),
                        'Cancel this requirement? Businesses will no longer be able to see it.'
                      )
                    }
                  >
                    Cancel
                  </button>
                )}
                <Link to={`/requirements/${requirement.id}`} className="btn btn-sm btn-mp-ghost">View</Link>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
