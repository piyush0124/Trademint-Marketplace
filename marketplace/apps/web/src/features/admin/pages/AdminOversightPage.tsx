import { useCallback, useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { api, getErrorMessage } from '../../../lib/api/client';
import type {
  AdminBusinessDocument,
  AdminDisputeRow,
  ApiBusiness,
  ApiDispute,
  ApiEnvelope,
  ApiReview,
  AuditLogEntry,
  DisputeStatus,
  DisputeStatusCounts,
  PaginatedResponse,
  ReviewStatus,
  VerificationCounts,
} from '../../../types/api';
import StarRating from '../../reviews/components/StarRating';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';
import ErrorState from '../../../components/ui/ErrorState';

/**
 * Staff oversight: verification, reviews, disputes and the audit trail.
 *
 * Four queues in one screen rather than four routes, because they are the same job —
 * working through what needs a decision — and splitting them would mean four
 * near-identical pages to keep in step.
 *
 * Two rules are visible in the UI itself: a rejection or suspension cannot be
 * submitted without a reason, and a review can be hidden or restored but never
 * deleted. Both are enforced by the API too; this is just honest about them.
 */
type Tab = 'businesses' | 'reviews' | 'disputes' | 'audit';

const TABS: { value: Tab; label: string }[] = [
  { value: 'businesses', label: 'Verification' },
  { value: 'reviews', label: 'Reviews' },
  { value: 'disputes', label: 'Disputes' },
  { value: 'audit', label: 'Audit Log' },
];

function humanise(value: string): string {
  return value.replace(/[_.]/g, ' ').replace(/^./, (c) => c.toUpperCase());
}

export default function AdminOversightPage() {
  const [params, setParams] = useSearchParams();
  const tabParam = params.get('tab') as Tab | null;
  const tab: Tab = TABS.some((t) => t.value === tabParam) ? (tabParam as Tab) : 'businesses';

  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const [businesses, setBusinesses] = useState<ApiBusiness[]>([]);
  const [verificationCounts, setVerificationCounts] = useState<VerificationCounts | null>(null);
  const [verificationFilter, setVerificationFilter] = useState('pending');
  const [documents, setDocuments] = useState<Record<number, AdminBusinessDocument[]>>({});
  const [expanded, setExpanded] = useState<number | null>(null);
  const [reason, setReason] = useState('');

  const [reviews, setReviews] = useState<ApiReview[]>([]);
  const [reviewFilter, setReviewFilter] = useState('');
  const [moderationNote, setModerationNote] = useState('');
  const [moderating, setModerating] = useState<string | null>(null);

  const [disputes, setDisputes] = useState<AdminDisputeRow[]>([]);
  const [disputeCounts, setDisputeCounts] = useState<DisputeStatusCounts | null>(null);
  const [disputeFilter, setDisputeFilter] = useState('open');
  const [openDispute, setOpenDispute] = useState<ApiDispute | null>(null);
  const [resolution, setResolution] = useState('');
  const [adminNotes, setAdminNotes] = useState('');
  const [nextStatus, setNextStatus] = useState<DisputeStatus>('under_review');

  const [logs, setLogs] = useState<AuditLogEntry[]>([]);
  const [actionFilter, setActionFilter] = useState('');

  const load = useCallback(() => {
    setStatus('loading');
    setError('');

    const done = () => setStatus('ready');
    const fail = () => setStatus('error');

    if (tab === 'businesses') {
      api
        .get<PaginatedResponse<ApiBusiness, never> & { meta: { verification_counts: VerificationCounts } }>(
          '/admin/businesses',
          { params: { per_page: 50, ...(verificationFilter ? { 'filter[verification_status]': verificationFilter } : {}) } }
        )
        .then((res) => {
          setBusinesses(res.data.data);
          setVerificationCounts(res.data.meta.verification_counts);
          done();
        })
        .catch(fail);
      return;
    }

    if (tab === 'reviews') {
      api
        .get<PaginatedResponse<ApiReview>>('/admin/reviews', {
          params: { per_page: 50, ...(reviewFilter ? { 'filter[status]': reviewFilter } : {}) },
        })
        .then((res) => {
          setReviews(res.data.data);
          done();
        })
        .catch(fail);
      return;
    }

    if (tab === 'disputes') {
      api
        .get<PaginatedResponse<AdminDisputeRow, DisputeStatusCounts>>('/admin/disputes', {
          params: { per_page: 50, ...(disputeFilter ? { 'filter[status]': disputeFilter } : {}) },
        })
        .then((res) => {
          setDisputes(res.data.data);
          setDisputeCounts(res.data.meta.status_counts ?? null);
          done();
        })
        .catch(fail);
      return;
    }

    api
      .get<PaginatedResponse<AuditLogEntry>>('/admin/audit-logs', {
        params: { per_page: 100, ...(actionFilter ? { 'filter[action]': actionFilter } : {}) },
      })
      .then((res) => {
        setLogs(res.data.data);
        done();
      })
      .catch(fail);
  }, [tab, verificationFilter, reviewFilter, disputeFilter, actionFilter]);

  useEffect(load, [load]);

  const switchTab = (value: Tab) => {
    setParams({ tab: value });
    setError('');
    setExpanded(null);
    setOpenDispute(null);
  };

  /* ------------------------------------------------------------ actions */

  const decideVerification = async (business: ApiBusiness, action: 'approve' | 'reject' | 'suspend' | 'reinstate') => {
    setError('');
    setBusy(true);
    try {
      await api.post(`/admin/businesses/${business.id}/verification`, {
        action,
        ...(reason.trim() ? { reason: reason.trim() } : {}),
      });
      setReason('');
      setExpanded(null);
      load();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const loadDocuments = async (business: ApiBusiness) => {
    if (documents[business.id]) return;
    try {
      const res = await api.get<ApiEnvelope<AdminBusinessDocument[]>>(`/admin/businesses/${business.id}/documents`);
      setDocuments((current) => ({ ...current, [business.id]: res.data.data }));
    } catch (err) {
      setError(getErrorMessage(err));
    }
  };

  const reviewDocument = async (business: ApiBusiness, document: AdminBusinessDocument, next: 'approved' | 'rejected') => {
    setError('');
    setBusy(true);
    try {
      await api.post(`/admin/business-documents/${document.id}/review`, {
        status: next,
        ...(reason.trim() ? { notes: reason.trim() } : {}),
      });
      setDocuments((current) => {
        const copy = { ...current };
        delete copy[business.id];
        return copy;
      });
      await loadDocuments(business);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const moderateReview = async (review: ApiReview, next: ReviewStatus) => {
    setError('');
    setBusy(true);
    try {
      await api.post(`/admin/reviews/${review.id}/moderate`, {
        status: next,
        ...(moderationNote.trim() ? { note: moderationNote.trim() } : {}),
      });
      setModerating(null);
      setModerationNote('');
      load();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const openDisputeDetail = async (row: AdminDisputeRow) => {
    setError('');
    try {
      const res = await api.get<ApiEnvelope<ApiDispute>>(`/disputes/${row.id}`);
      setOpenDispute(res.data.data);
      setAdminNotes(res.data.data.attributes.admin_notes ?? '');
      setResolution(res.data.data.attributes.resolution ?? '');
      setNextStatus(res.data.data.attributes.status === 'open' ? 'under_review' : 'resolved');
    } catch (err) {
      setError(getErrorMessage(err));
    }
  };

  const decideDispute = async () => {
    if (!openDispute) return;
    setError('');
    setBusy(true);
    try {
      await api.post(`/admin/disputes/${openDispute.id}/status`, {
        status: nextStatus,
        ...(adminNotes.trim() ? { admin_notes: adminNotes.trim() } : {}),
        ...(resolution.trim() ? { resolution: resolution.trim() } : {}),
      });
      setOpenDispute(null);
      load();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  /* -------------------------------------------------------------- render */

  return (
    <div className="mp-container py-4">
      <div className="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
          <h1 className="h4 mb-1">Trust &amp; Oversight</h1>
          <p className="text-muted small mb-0">Every action on this page is written to the audit log against your account.</p>
        </div>
        <div className="d-flex gap-2">
          <Link to="/admin" className="btn btn-sm btn-mp-outline">Overview</Link>
          <Link to="/admin/listings" className="btn btn-sm btn-mp-outline">Listings</Link>
        </div>
      </div>

      <div className="d-flex gap-2 mb-3 flex-wrap" role="tablist" aria-label="Oversight queues">
        {TABS.map((option) => (
          <button
            key={option.value}
            type="button"
            role="tab"
            aria-selected={tab === option.value}
            className={`btn btn-sm ${tab === option.value ? 'btn-mp-primary' : 'btn-mp-outline'}`}
            onClick={() => switchTab(option.value)}
          >
            {option.label}
          </button>
        ))}
      </div>

      {error && <div className="alert alert-danger py-2 small" role="alert">{error}</div>}
      {status === 'loading' && <LoadingState variant="list" rows={5} />}
      {status === 'error' && <ErrorState onRetry={load} />}

      {/* ------------------------------------------------ Verification queue */}
      {status === 'ready' && tab === 'businesses' && (
        <>
          <div className="d-flex gap-2 mb-3 flex-wrap">
            {['pending', 'verified', 'rejected', 'suspended', 'unverified', ''].map((value) => (
              <button
                key={value || 'all'}
                type="button"
                className={`btn btn-sm ${verificationFilter === value ? 'btn-mp-primary' : 'btn-mp-outline'}`}
                onClick={() => setVerificationFilter(value)}
              >
                {value === '' ? 'All' : humanise(value)}
                {verificationCounts && value !== '' ? ` (${verificationCounts[value as keyof VerificationCounts] ?? 0})` : ''}
              </button>
            ))}
          </div>

          {businesses.length === 0 && <EmptyState icon="check" title="Nothing in this queue." description="No businesses match that status." />}

          <div className="d-flex flex-column gap-2">
            {businesses.map((business) => {
              const a = business.attributes;
              const isOpen = expanded === business.id;

              return (
                <div key={business.id} className="mp-card p-3">
                  <div className="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div className="min-width-0">
                      <Link to={`/business/${a.slug}`} className="fw-semibold text-dark text-decoration-none">{a.name}</Link>
                      <div className="small text-muted">
                        {[business.relationships?.location?.attributes.city, business.relationships?.location?.attributes.state]
                          .filter(Boolean)
                          .join(', ') || 'No location set'}
                        {business.relationships?.category && ` · ${business.relationships.category.attributes.name}`}
                      </div>
                      <div className="small text-muted">
                        {a.documents_count ?? 0} documents · {a.orders_count ?? 0} orders · {a.reviews_count ?? 0} reviews
                        {a.rating !== null ? ` · ★ ${Number(a.rating).toFixed(1)}` : ' · no ratings'}
                      </div>
                    </div>
                    <span className={`mp-status-badge mp-status-${a.verification_status}`}>{a.verification_status_label}</span>
                  </div>

                  <div className="d-flex gap-2 flex-wrap mt-2">
                    <button
                      type="button"
                      className="btn btn-sm btn-mp-outline"
                      onClick={() => {
                        setExpanded(isOpen ? null : business.id);
                        setReason('');
                        if (!isOpen) loadDocuments(business);
                      }}
                    >
                      {isOpen ? 'Close' : 'Review'}
                    </button>
                  </div>

                  {isOpen && (
                    <div className="mt-3 p-3 mp-counter-summary">
                      <h3 className="h6">Documents</h3>
                      {(documents[business.id] ?? []).length === 0 ? (
                        <p className="small text-muted mb-2">
                          No documents uploaded. Approving without evidence is possible but leaves a weaker audit trail.
                        </p>
                      ) : (
                        <ul className="list-unstyled small mb-2">
                          {(documents[business.id] ?? []).map((document) => (
                            <li key={document.id} className="d-flex justify-content-between align-items-center gap-2 py-1">
                              <span>
                                {document.title || humanise(document.type)}
                                <span className="text-muted"> · {humanise(document.type)}</span>
                                <span className="text-muted"> · {document.status}</span>
                              </span>
                              <span className="d-flex gap-1">
                                <button
                                  type="button"
                                  className="btn btn-sm btn-mp-ghost"
                                  disabled={busy || document.status === 'approved'}
                                  onClick={() => reviewDocument(business, document, 'approved')}
                                >
                                  Approve
                                </button>
                                <button
                                  type="button"
                                  className="btn btn-sm btn-mp-ghost text-danger"
                                  disabled={busy || document.status === 'rejected'}
                                  onClick={() => reviewDocument(business, document, 'rejected')}
                                >
                                  Reject
                                </button>
                              </span>
                            </li>
                          ))}
                        </ul>
                      )}

                      <label className="form-label small" htmlFor={`reason-${business.id}`}>
                        Reason / notes
                      </label>
                      <textarea
                        id={`reason-${business.id}`}
                        className="form-control form-control-sm"
                        rows={2}
                        maxLength={2000}
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                      />
                      <div className="form-text small">Required to reject or suspend — the business is told why.</div>

                      <div className="d-flex gap-2 flex-wrap mt-2">
                        <button type="button" className="btn btn-sm btn-mp-primary" disabled={busy} onClick={() => decideVerification(business, 'approve')}>
                          Approve verification
                        </button>
                        <button
                          type="button"
                          className="btn btn-sm btn-mp-outline"
                          disabled={busy || reason.trim() === ''}
                          onClick={() => decideVerification(business, 'reject')}
                        >
                          Reject
                        </button>
                        {a.verification_status !== 'suspended' ? (
                          <button
                            type="button"
                            className="btn btn-sm btn-mp-ghost text-danger"
                            disabled={busy || reason.trim() === ''}
                            onClick={() => decideVerification(business, 'suspend')}
                          >
                            Suspend
                          </button>
                        ) : (
                          <button type="button" className="btn btn-sm btn-mp-outline" disabled={busy} onClick={() => decideVerification(business, 'reinstate')}>
                            Reinstate
                          </button>
                        )}
                      </div>
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </>
      )}

      {/* ----------------------------------------------------- Review queue */}
      {status === 'ready' && tab === 'reviews' && (
        <>
          <div className="d-flex gap-2 mb-3 flex-wrap">
            {['', 'published', 'flagged', 'hidden'].map((value) => (
              <button
                key={value || 'all'}
                type="button"
                className={`btn btn-sm ${reviewFilter === value ? 'btn-mp-primary' : 'btn-mp-outline'}`}
                onClick={() => setReviewFilter(value)}
              >
                {value === '' ? 'All' : humanise(value)}
              </button>
            ))}
          </div>

          {reviews.length === 0 && <EmptyState icon="star" title="No reviews match that filter." />}

          <div className="d-flex flex-column gap-2">
            {reviews.map((review) => {
              const a = review.attributes;

              return (
                <div key={review.id} className="mp-card p-3">
                  <div className="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div className="min-width-0">
                      <StarRating value={a.rating} readOnly />
                      <div className="small text-muted mt-1">
                        {review.relationships.business?.attributes.name ?? 'Business'} · {a.reviewer_name}
                        {a.created_at && ` · ${a.created_at.slice(0, 10)}`}
                        {review.relationships.order && ` · ${review.relationships.order.attributes.order_number}`}
                      </div>
                    </div>
                    <span className={`mp-status-badge mp-status-${a.status}`}>{a.status_label}</span>
                  </div>

                  {a.comment && <p className="small mt-2 mb-0">{a.comment}</p>}
                  {a.business_response && (
                    <div className="mt-2 p-3 mp-counter-summary">
                      <div className="small fw-semibold">Business response</div>
                      <div className="small">{a.business_response}</div>
                    </div>
                  )}
                  {a.moderation_note && <div className="small text-muted mt-2">Moderation note: {a.moderation_note}</div>}

                  {moderating !== review.id ? (
                    <button type="button" className="btn btn-sm btn-mp-outline mt-2" onClick={() => { setModerating(review.id); setModerationNote(a.moderation_note ?? ''); }}>
                      Moderate
                    </button>
                  ) : (
                    <div className="mt-2">
                      <label className="form-label small" htmlFor={`note-${review.id}`}>Reason (recorded against you)</label>
                      <textarea
                        id={`note-${review.id}`}
                        className="form-control form-control-sm"
                        rows={2}
                        maxLength={2000}
                        value={moderationNote}
                        onChange={(event) => setModerationNote(event.target.value)}
                      />
                      <p className="form-text small mb-2">
                        Hiding removes a review from the public rating. It is never deleted, and a business disliking a
                        review is not grounds to hide it.
                      </p>
                      <div className="d-flex gap-2 flex-wrap">
                        <button type="button" className="btn btn-sm btn-mp-ghost text-danger" disabled={busy || a.status === 'hidden'} onClick={() => moderateReview(review, 'hidden')}>
                          Hide
                        </button>
                        <button type="button" className="btn btn-sm btn-mp-outline" disabled={busy || a.status === 'flagged'} onClick={() => moderateReview(review, 'flagged')}>
                          Flag for follow-up
                        </button>
                        <button type="button" className="btn btn-sm btn-mp-primary" disabled={busy || a.status === 'published'} onClick={() => moderateReview(review, 'published')}>
                          Restore
                        </button>
                        <button type="button" className="btn btn-sm btn-mp-ghost" onClick={() => setModerating(null)}>Cancel</button>
                      </div>
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </>
      )}

      {/* ---------------------------------------------------- Dispute queue */}
      {status === 'ready' && tab === 'disputes' && (
        <>
          <div className="d-flex gap-2 mb-3 flex-wrap">
            {['open', 'under_review', 'resolved', 'rejected', ''].map((value) => (
              <button
                key={value || 'all'}
                type="button"
                className={`btn btn-sm ${disputeFilter === value ? 'btn-mp-primary' : 'btn-mp-outline'}`}
                onClick={() => setDisputeFilter(value)}
              >
                {value === '' ? 'All' : humanise(value)}
                {disputeCounts && value !== '' ? ` (${disputeCounts[value as DisputeStatus] ?? 0})` : ''}
              </button>
            ))}
          </div>

          {disputes.length === 0 && <EmptyState icon="handshake" title="Nothing to arbitrate." description="No disputes match that status." />}

          <div className="d-flex flex-column gap-2">
            {disputes.map((row) => (
              <div key={row.id} className={`mp-match-card status-${row.status}`}>
                <div className="d-flex justify-content-between align-items-start flex-wrap gap-2">
                  <div className="min-width-0">
                    <div className="fw-semibold">{humanise(row.reason)}</div>
                    <div className="small text-muted">
                      {row.reference}
                      {row.order_number && ` · ${row.order_number}`}
                      {row.business_name && ` · ${row.business_name}`}
                      {row.created_at && ` · raised ${row.created_at.slice(0, 10)} by the ${row.raised_by_party}`}
                    </div>
                  </div>
                  <span className={`mp-status-badge mp-status-${row.status}`}>{humanise(row.status)}</span>
                </div>
                <div className="mt-2">
                  <button type="button" className="btn btn-sm btn-mp-outline" onClick={() => openDisputeDetail(row)}>
                    Open
                  </button>
                </div>
              </div>
            ))}
          </div>

          {openDispute && (
            <div
              className="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center p-3"
              style={{ background: 'rgba(20,32,30,0.5)', zIndex: 70 }}
              onClick={() => setOpenDispute(null)}
            >
              <div className="mp-card p-4" style={{ width: 560, maxWidth: '96vw', maxHeight: '90vh', overflowY: 'auto' }} onClick={(e) => e.stopPropagation()}>
                <div className="d-flex justify-content-between align-items-start gap-2">
                  <h2 className="h6 mb-0">{openDispute.attributes.reference}</h2>
                  <button type="button" className="mp-modal-close" onClick={() => setOpenDispute(null)} aria-label="Close">✕</button>
                </div>
                <div className="small text-muted mt-1">
                  {humanise(openDispute.attributes.reason)} · raised by the {openDispute.attributes.raised_by_party}
                  {openDispute.relationships.order && ` · ${openDispute.relationships.order.order_number}`}
                </div>

                <p className="small mt-3">{openDispute.attributes.description}</p>
                {openDispute.attributes.evidence && (
                  <p className="small text-muted">{openDispute.attributes.evidence}</p>
                )}

                <div className="mp-form-field mt-3">
                  <label className="form-label small" htmlFor="dispute-next-status">Set status</label>
                  <select
                    id="dispute-next-status"
                    className="form-select form-select-sm"
                    value={nextStatus}
                    onChange={(event) => setNextStatus(event.target.value as DisputeStatus)}
                  >
                    <option value="under_review">Under review</option>
                    <option value="resolved">Resolved</option>
                    <option value="rejected">Rejected</option>
                    <option value="closed">Closed</option>
                  </select>
                </div>

                <div className="mp-form-field mt-2">
                  <label className="form-label small" htmlFor="dispute-resolution">Outcome (shown to both parties)</label>
                  <textarea
                    id="dispute-resolution"
                    className="form-control form-control-sm"
                    rows={3}
                    maxLength={5000}
                    value={resolution}
                    onChange={(event) => setResolution(event.target.value)}
                  />
                </div>

                <div className="mp-form-field mt-2">
                  <label className="form-label small" htmlFor="dispute-admin-notes">Internal notes (staff only)</label>
                  <textarea
                    id="dispute-admin-notes"
                    className="form-control form-control-sm"
                    rows={2}
                    maxLength={5000}
                    value={adminNotes}
                    onChange={(event) => setAdminNotes(event.target.value)}
                  />
                </div>

                <p className="form-text small">
                  Resolving a dispute does not move money. Any refund must be issued on the order's payment.
                </p>

                <div className="d-flex justify-content-end gap-2">
                  <button type="button" className="btn btn-mp-ghost" onClick={() => setOpenDispute(null)}>Cancel</button>
                  <button type="button" className="btn btn-mp-primary" disabled={busy} onClick={decideDispute}>
                    {busy ? 'Saving…' : 'Update dispute'}
                  </button>
                </div>
              </div>
            </div>
          )}
        </>
      )}

      {/* -------------------------------------------------------- Audit log */}
      {status === 'ready' && tab === 'audit' && (
        <>
          <div className="mb-3" style={{ maxWidth: 320 }}>
            <label className="form-label small" htmlFor="audit-filter">Filter by action prefix</label>
            <input
              id="audit-filter"
              className="form-control form-control-sm"
              placeholder="e.g. business.verification"
              value={actionFilter}
              onChange={(event) => setActionFilter(event.target.value)}
            />
          </div>

          {logs.length === 0 && <EmptyState icon="clipboard" title="No audit entries." description="Nothing matches that filter yet." />}

          {logs.length > 0 && (
            <div className="mp-card p-0 table-responsive">
              <table className="table table-sm mb-0 align-middle">
                <thead>
                  <tr>
                    <th scope="col">When</th>
                    <th scope="col">Action</th>
                    <th scope="col">Actor</th>
                    <th scope="col">Entity</th>
                    <th scope="col">IP</th>
                  </tr>
                </thead>
                <tbody>
                  {logs.map((log) => (
                    <tr key={log.id}>
                      <td className="small text-muted" style={{ whiteSpace: 'nowrap' }}>
                        {log.created_at ? new Date(log.created_at).toLocaleString('en-IN', { dateStyle: 'short', timeStyle: 'short' }) : '—'}
                      </td>
                      <td className="small"><code>{log.action}</code></td>
                      <td className="small">
                        {log.actor ?? 'System'}
                        {log.actor_role && <span className="text-muted"> ({log.actor_role})</span>}
                      </td>
                      <td className="small">
                        {log.entity_type ? <span className="text-muted">{log.entity_type.split('\\').pop()} </span> : null}
                        {log.entity_reference ?? ''}
                      </td>
                      <td className="small text-muted">{log.ip_address ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </div>
  );
}
