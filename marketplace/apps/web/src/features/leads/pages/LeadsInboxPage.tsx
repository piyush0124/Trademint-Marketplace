import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, formatPrice, getErrorMessage } from '../../../lib/api/client';
import type { ApiLead, LeadStatus, LeadStatusCounts, PaginatedResponse } from '../../../types/api';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';
import ErrorState from '../../../components/ui/ErrorState';
import { listingPath } from '../../../lib/urls';

const TABS: { value: LeadStatus | 'all'; label: string; icon: string }[] = [
  { value: 'all', label: 'All', icon: '📥' },
  { value: 'new', label: 'New', icon: '🟢' },
  { value: 'contacted', label: 'Contacted', icon: '📞' },
  { value: 'converted', label: 'Converted', icon: '🎉' },
  { value: 'lost', label: 'Lost', icon: '⚪' },
];

function initials(name: string): string {
  return name
    .split(' ')
    .map((part) => part[0])
    .slice(0, 2)
    .join('')
    .toUpperCase();
}

function timeAgo(iso: string | null): string {
  if (!iso) return '';
  const diffMs = Date.now() - new Date(iso).getTime();
  const minutes = Math.floor(diffMs / 60000);
  if (minutes < 1) return 'just now';
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  const days = Math.floor(hours / 24);
  return `${days}d ago`;
}

export default function LeadsInboxPage() {
  const [tab, setTab] = useState<LeadStatus | 'all'>('all');
  const [leads, setLeads] = useState<ApiLead[]>([]);
  const [counts, setCounts] = useState<LeadStatusCounts>({ new: 0, contacted: 0, converted: 0, lost: 0 });
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [updatingId, setUpdatingId] = useState<number | null>(null);
  const [actionError, setActionError] = useState('');

  const load = () => {
    setStatus('loading');
    const params: Record<string, string> = { per_page: '50' };
    if (tab !== 'all') params['filter[status]'] = tab;

    api
      .get<PaginatedResponse<ApiLead, LeadStatusCounts>>('/users/me/leads', { params })
      .then((res) => {
        setLeads(res.data.data);
        if (res.data.meta.status_counts) setCounts(res.data.meta.status_counts);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  useEffect(load, [tab]);

  const updateStatus = async (lead: ApiLead, next: LeadStatus) => {
    setActionError('');
    setUpdatingId(lead.id);
    try {
      await api.patch(`/leads/${lead.id}/status`, { status: next });
      load();
    } catch (err) {
      setActionError(getErrorMessage(err));
    } finally {
      setUpdatingId(null);
    }
  };

  const total = counts.new + counts.contacted + counts.converted + counts.lost;

  return (
    <div>
      <div className="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
        <div>
          <h1 className="h5 mb-0">Leads Inbox</h1>
          <p className="text-muted small mb-0">Enquiries from people interested in your listings.</p>
        </div>
        {counts.new > 0 && (
          <span className="mp-badge-featured">
            {counts.new} new
          </span>
        )}
      </div>

      <div className="mp-lead-status-tabs my-3">
        {TABS.map((t) => (
          <button
            key={t.value}
            type="button"
            className={`mp-lead-status-tab ${tab === t.value ? 'is-active' : ''}`}
            onClick={() => setTab(t.value)}
          >
            <span>{t.icon}</span>
            {t.label}
            <span className="mp-lead-status-tab__count">{t.value === 'all' ? total : counts[t.value]}</span>
          </button>
        ))}
      </div>

      {actionError && <div className="alert alert-danger">{actionError}</div>}

      {status === 'loading' && <LoadingState variant="list" rows={4} />}
      {status === 'error' && <ErrorState onRetry={load} />}

      {status === 'ready' && leads.length === 0 && (
        <EmptyState
          icon="inbox"
          title={tab === 'all' ? 'No enquiries yet' : `No ${tab} leads`}
          description="When someone enquires about one of your listings, it'll show up here."
        />
      )}

      {status === 'ready' && leads.length > 0 && (
        <div className="d-flex flex-column gap-3">
          {leads.map((lead) => {
            const listing = lead.relationships.listing;
            return (
              <div key={lead.id} className={`mp-lead-card status-${lead.attributes.status}`}>
                <div className="d-flex align-items-start gap-3 flex-wrap">
                  <div className="mp-lead-avatar">{initials(lead.attributes.name)}</div>

                  <div className="flex-grow-1" style={{ minWidth: 220 }}>
                    <div className="d-flex align-items-center gap-2 flex-wrap">
                      <span className="fw-semibold text-dark">{lead.attributes.name}</span>
                      <span className="text-muted small">{timeAgo(lead.attributes.created_at)}</span>
                    </div>
                    <div className="small text-muted">
                      {[lead.attributes.email, lead.attributes.phone].filter(Boolean).join(' · ')}
                    </div>
                    {lead.attributes.message && <p className="mb-2 mt-2">{lead.attributes.message}</p>}
                    {listing && (
                      <Link to={listingPath(listing)} className="small">
                        On: {listing.attributes.title || 'Untitled listing'}
                        {listing.attributes.price ? ` · ${formatPrice(listing.attributes.price, listing.attributes.currency)}` : ''}
                      </Link>
                    )}
                  </div>

                  <div className="d-flex flex-column gap-2 align-items-end">
                    <div className="d-flex gap-2 flex-wrap">
                      {lead.attributes.phone && (
                        <a href={`tel:${lead.attributes.phone}`} className="btn btn-sm btn-mp-outline">📞</a>
                      )}
                      {lead.attributes.phone && (
                        <a
                          href={`https://wa.me/${lead.attributes.phone.replace(/\D/g, '')}`}
                          target="_blank"
                          rel="noreferrer"
                          className="btn btn-sm btn-mp-outline"
                        >
                          💬
                        </a>
                      )}
                    </div>
                    <select
                      className="form-select form-select-sm"
                      style={{ width: 150 }}
                      value={lead.attributes.status}
                      disabled={updatingId === lead.id}
                      onChange={(e) => updateStatus(lead, e.target.value as LeadStatus)}
                    >
                      <option value="new">New</option>
                      <option value="contacted">Contacted</option>
                      <option value="converted">Converted</option>
                      <option value="lost">Lost</option>
                    </select>
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
