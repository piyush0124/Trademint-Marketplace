import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, getErrorMessage } from '../../../lib/api/client';
import type { ApiEnvelope, ApiRequirement } from '../../../types/api';
import StatusBadge from '../../../components/ui/StatusBadge';
import LoadingState from '../../../components/ui/LoadingState';
import ErrorState from '../../../components/ui/ErrorState';
import MatchedBusinessesPanel from '../../matching/components/MatchedBusinessesPanel';
import { useAuth } from '../../auth/AuthContext';

function formatBudget(min: string | null, max: string | null): string {
  if (!min && !max) return 'Not specified';
  if (min && max) return `₹${Number(min).toLocaleString('en-IN')} – ₹${Number(max).toLocaleString('en-IN')}`;
  return `Up to ₹${Number(min ?? max).toLocaleString('en-IN')}`;
}

export default function RequirementDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { user } = useAuth();

  const [requirement, setRequirement] = useState<ApiRequirement | null>(null);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error' | 'forbidden'>('loading');
  const [error, setError] = useState('');

  const load = () => {
    if (!id) return;
    setStatus('loading');
    api
      .get<ApiEnvelope<ApiRequirement>>(`/requirements/${id}`)
      .then((res) => {
        setRequirement(res.data.data);
        setStatus('ready');
      })
      .catch((err) => {
        setError(getErrorMessage(err));
        setStatus(err?.response?.status === 403 ? 'forbidden' : 'error');
      });
  };

  useEffect(load, [id]);

  const isOwnRequirement = user && requirement?.relationships.customer?.id === user.id;

  if (status === 'loading') return <div className="mp-container py-4"><LoadingState variant="list" rows={4} /></div>;
  if (status === 'forbidden') {
    return (
      <div className="mp-container py-4">
        <ErrorState message="You don't have access to this requirement." />
      </div>
    );
  }
  if (status === 'error' || !requirement) {
    return (
      <div className="mp-container py-4">
        <ErrorState message={error || 'Could not load this requirement.'} onRetry={load} />
      </div>
    );
  }

  return (
    <div className="mp-container py-4" style={{ maxWidth: 780 }}>
      <div className="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
          <h1 className="h4 mb-1">{requirement.attributes.title || 'Untitled requirement'}</h1>
          <div className="text-muted small">{requirement.relationships.category?.attributes.name}</div>
        </div>
        <StatusBadge status={requirement.attributes.status} />
      </div>

      <div className="mp-card p-4 mb-4">
        {requirement.attributes.description && <p>{requirement.attributes.description}</p>}
        <dl className="row mb-0">
          <dt className="col-4">Quantity</dt>
          <dd className="col-8">
            {requirement.attributes.quantity ?? '—'} {requirement.attributes.unit}
          </dd>
          <dt className="col-4">Budget</dt>
          <dd className="col-8">{formatBudget(requirement.attributes.budget_min, requirement.attributes.budget_max)}</dd>
          <dt className="col-4">Location</dt>
          <dd className="col-8">
            {requirement.relationships.location
              ? [requirement.relationships.location.attributes.area, requirement.relationships.location.attributes.city, requirement.relationships.location.attributes.state]
                  .filter(Boolean)
                  .join(', ')
              : '—'}
          </dd>
          <dt className="col-4">Required By</dt>
          <dd className="col-8">{requirement.attributes.required_date ?? '—'}</dd>
          <dt className="col-4">Urgency</dt>
          <dd className="col-8 text-capitalize">{requirement.attributes.urgency ?? '—'}</dd>
        </dl>
      </div>

      {/* Matching results are the customer's own view of their requirement.
          A business reading this page sees the requirement only — the API
          would refuse the match list anyway, so it isn't requested. */}
      {isOwnRequirement && (
        <MatchedBusinessesPanel requirementId={requirement.id} requirementStatus={requirement.attributes.status} />
      )}

      {isOwnRequirement && requirement.attributes.is_editable && (
        <div className="mt-3">
          <Link to={`/dashboard/requirements/${requirement.id}/edit`} className="btn btn-mp-outline btn-sm">Edit Requirement</Link>
        </div>
      )}
    </div>
  );
}
