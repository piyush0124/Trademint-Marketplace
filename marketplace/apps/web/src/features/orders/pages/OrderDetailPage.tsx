import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, getErrorMessage } from '../../../lib/api/client';
import { formatMoney } from '../../../lib/money';
import type { ApiEnvelope, ApiOrder, OrderStatus } from '../../../types/api';
import LoadingState from '../../../components/ui/LoadingState';
import ErrorState from '../../../components/ui/ErrorState';
import StatusBadge from '../../../components/ui/StatusBadge';
import OrderPaymentPanel from '../../payments/components/OrderPaymentPanel';
import OrderReviewCard from '../../reviews/components/OrderReviewCard';
import RaiseDisputeCard from '../../disputes/components/RaiseDisputeCard';
import OrderProtectionPanel from '../components/OrderProtectionPanel';

/**
 * One order: what was agreed, where it stands, what happens next, and the full
 * audit of how it got here.
 *
 * The action buttons are built from `available_transitions`, which the backend
 * computes from the state machine *and* the viewer's party — so the UI holds no
 * copy of the transition rules and cannot drift from them.
 */
export default function OrderDetailPage() {
  const { id } = useParams<{ id: string }>();

  const [order, setOrder] = useState<ApiOrder | null>(null);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error' | 'forbidden'>('loading');
  const [error, setError] = useState('');
  const [actionError, setActionError] = useState('');
  const [busy, setBusy] = useState(false);
  const [pendingStatus, setPendingStatus] = useState<OrderStatus | null>(null);
  const [note, setNote] = useState('');
  const [notes, setNotes] = useState('');
  const [savingNotes, setSavingNotes] = useState(false);
  // Bumped whenever the order changes, so the payment panel re-reads the server
  // rather than trusting a stale balance.
  const [refreshKey, setRefreshKey] = useState(0);

  const load = () => {
    if (!id) return;
    setStatus('loading');
    api
      .get<ApiEnvelope<ApiOrder>>(`/orders/${id}`)
      .then((res) => {
        setOrder(res.data.data);
        const party = res.data.data.attributes.viewer_party;
        setNotes((party === 'business' ? res.data.data.attributes.business_notes : res.data.data.attributes.customer_notes) ?? '');
        setStatus('ready');
        setRefreshKey((key) => key + 1);
      })
      .catch((err) => {
        setError(getErrorMessage(err));
        setStatus(err?.response?.status === 403 ? 'forbidden' : 'error');
      });
  };

  useEffect(load, [id]);

  const applyStatus = async (target: OrderStatus) => {
    if (!order) return;
    setActionError('');
    setBusy(true);
    try {
      const res = await api.post<ApiEnvelope<ApiOrder>>(`/orders/${order.id}/status`, {
        status: target,
        ...(note.trim() ? { note: note.trim() } : {}),
      });
      setOrder(res.data.data);
      setPendingStatus(null);
      setNote('');
      load();
    } catch (err) {
      setActionError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const saveNotes = async () => {
    if (!order) return;
    setSavingNotes(true);
    setActionError('');
    try {
      const res = await api.patch<ApiEnvelope<ApiOrder>>(`/orders/${order.id}/notes`, { note: notes.trim() || null });
      setOrder(res.data.data);
    } catch (err) {
      setActionError(getErrorMessage(err));
    } finally {
      setSavingNotes(false);
    }
  };

  if (status === 'loading') return <LoadingState variant="list" rows={5} />;
  if (status === 'forbidden') return <ErrorState message="You don't have access to this order." />;
  if (status === 'error' || !order) return <ErrorState message={error || 'Could not load this order.'} onRetry={load} />;

  const a = order.attributes;
  const items = order.relationships.items ?? [];
  const history = order.relationships.status_history ?? [];
  const dispute = order.relationships.active_dispute ?? null;
  const currency = a.currency;

  return (
    <div>
      <div className="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
          <Link to="/dashboard/orders" className="btn btn-sm btn-mp-ghost px-0 mb-1">← All orders</Link>
          <h1 className="h5 mb-1">{order.relationships.requirement?.attributes.title || a.order_number}</h1>
          <div className="small text-muted">
            {a.order_number}
            {order.relationships.quote && ` · from quote ${order.relationships.quote.attributes.quote_number}`}
            {a.placed_at && ` · placed ${a.placed_at.slice(0, 10)}`}
          </div>
        </div>
        <div className="text-end">
          <StatusBadge status={a.status} />
          <div className="mt-1">
            <span className={`mp-status-badge mp-status-${a.payment_status}`}>{a.payment_status_label}</span>
          </div>
        </div>
      </div>

      {actionError && <div className="alert alert-danger" role="alert">{actionError}</div>}

      {a.next_action && (
        <div className="alert alert-primary py-2 small" role="status">{a.next_action}</div>
      )}

      {a.status === 'cancelled' && a.cancellation_reason && (
        <div className="alert alert-secondary py-2 small">Cancelled: {a.cancellation_reason}</div>
      )}

      {/* An open dispute changes what this screen is about, so it leads. */}
      {dispute && (
        <div className="alert alert-warning py-2 small d-flex justify-content-between align-items-center flex-wrap gap-2" role="status">
          <span>
            Dispute <strong>{dispute.reference}</strong> is {dispute.status_label.toLowerCase()} — raised by the{' '}
            {dispute.raised_by_party}. An administrator will review it.
          </span>
          <Link to="/dashboard/disputes" className="btn btn-sm btn-mp-outline">View disputes</Link>
        </div>
      )}

      {/*
        * Hidden once a dispute is already open: at that point the banner above is
        * the live status and this panel's "here is how disputes work" would be
        * explaining a process the user is already inside.
        */}
      {!dispute && <OrderProtectionPanel />}

      <div className="row g-3">
        <div className="col-12 col-lg-7">
          <div className="mp-card p-4 mb-3">
            <h2 className="h6 mb-3">What was agreed</h2>
            <div className="table-responsive">
              <table className="table table-sm mp-quote-table align-middle mb-0">
                <thead>
                  <tr>
                    <th scope="col">Item</th>
                    <th scope="col" className="text-end">Qty</th>
                    <th scope="col" className="text-end">Unit price</th>
                    <th scope="col" className="text-end">Total</th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((item) => (
                    <tr key={item.id}>
                      <td>
                        <div className="fw-semibold">{item.attributes.description}</div>
                        <div className="small text-muted">{item.attributes.item_type_label}</div>
                      </td>
                      <td className="text-end">
                        {Number(item.attributes.quantity).toLocaleString('en-IN')}
                        {item.attributes.unit ? ` ${item.attributes.unit}` : ''}
                      </td>
                      <td className="text-end">{formatMoney(item.attributes.unit_price, currency)}</td>
                      <td className="text-end fw-semibold">{formatMoney(item.attributes.total, currency)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="row justify-content-end mt-3">
              <div className="col-12 col-md-7">
                <dl className="mp-quote-totals mb-0">
                  <div><dt>Subtotal</dt><dd>{formatMoney(a.subtotal, currency)}</dd></div>
                  {Number(a.tax_amount) !== 0 && <div><dt>Tax</dt><dd>{formatMoney(a.tax_amount, currency)}</dd></div>}
                  {Number(a.delivery_fee) !== 0 && <div><dt>Delivery</dt><dd>{formatMoney(a.delivery_fee, currency)}</dd></div>}
                  {Number(a.other_charges) !== 0 && <div><dt>Other charges</dt><dd>{formatMoney(a.other_charges, currency)}</dd></div>}
                  {Number(a.discount_amount) !== 0 && <div><dt>Discount</dt><dd>- {formatMoney(a.discount_amount, currency)}</dd></div>}
                  <div className="mp-quote-totals__grand"><dt>Total</dt><dd>{formatMoney(a.total_amount, currency)}</dd></div>
                  {Number(a.amount_paid) > 0 && <div><dt>Paid</dt><dd>{formatMoney(a.amount_paid, currency)}</dd></div>}
                  {Number(a.amount_due) > 0 && <div><dt>Outstanding</dt><dd>{formatMoney(a.amount_due, currency)}</dd></div>}
                </dl>
              </div>
            </div>
          </div>

          {/* The immutable audit of how this order progressed. */}
          <div className="mp-card p-4">
            <h2 className="h6 mb-3">Order history</h2>
            <ol className="mp-timeline" aria-label="Order status history">
              {history.map((entry) => (
                <li key={entry.id} className="mp-timeline__item">
                  <span className="mp-timeline__marker" aria-hidden="true">•</span>
                  <div className="mp-timeline__body">
                    <div className="small fw-semibold">
                      {entry.attributes.to_status_label}
                      <span className="text-muted fw-normal">
                        {' · '}
                        {entry.attributes.changed_by_party === 'system' ? 'Automatic' : `by ${entry.attributes.changed_by_party}`}
                      </span>
                    </div>
                    {entry.attributes.note && <div className="small">{entry.attributes.note}</div>}
                    <div className="text-muted" style={{ fontSize: '0.75rem' }}>
                      {entry.attributes.created_at
                        ? new Date(entry.attributes.created_at).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' })
                        : ''}
                    </div>
                  </div>
                </li>
              ))}
            </ol>
          </div>
        </div>

        <div className="col-12 col-lg-5">
          {/* Phase 8. Both parties see it; each sees only its own actions. */}
          {a.viewer_party !== null && (
            <OrderPaymentPanel orderId={order.id} refreshKey={refreshKey} onSettled={load} />
          )}

          {/* Phase 9. The review lives with the order that earned it. */}
          <OrderReviewCard order={order} onChange={load} />

          <div className="mp-card p-4 mb-3">
            <h2 className="h6 mb-3">Update this order</h2>

            {a.available_transitions.length === 0 ? (
              <p className="small text-muted mb-0">
                No status change is available to you{a.status === 'completed' || a.status === 'cancelled' ? ' — this order is closed.' : ' right now.'}
              </p>
            ) : (
              <div className="d-flex flex-column gap-2">
                {a.available_transitions.map((transition) => (
                  <button
                    key={transition.value}
                    type="button"
                    className={`btn ${transition.value === 'cancelled' ? 'btn-mp-ghost text-danger' : 'btn-mp-outline'}`}
                    disabled={busy}
                    onClick={() => setPendingStatus(pendingStatus === transition.value ? null : transition.value)}
                  >
                    Mark as {transition.label.toLowerCase()}
                  </button>
                ))}
              </div>
            )}

            {pendingStatus && (
              <div className="mt-3">
                <label className="form-label small fw-semibold" htmlFor="status-note">
                  Note (optional)
                </label>
                <textarea id="status-note" className="form-control form-control-sm" rows={2} maxLength={2000}
                  value={note} onChange={(e) => setNote(e.target.value)} />
                <div className="d-flex gap-2 mt-2">
                  <button type="button" className="btn btn-sm btn-mp-primary" disabled={busy} onClick={() => applyStatus(pendingStatus)}>
                    {busy ? 'Updating…' : 'Confirm change'}
                  </button>
                  <button type="button" className="btn btn-sm btn-mp-ghost" onClick={() => setPendingStatus(null)}>Cancel</button>
                </div>
              </div>
            )}
          </div>

          <div className="mp-card p-4 mb-3">
            <h2 className="h6 mb-2">Your notes</h2>
            <p className="small text-muted">Only you and the other party on this order can see these.</p>
            <textarea className="form-control form-control-sm" rows={3} maxLength={5000}
              value={notes} onChange={(e) => setNotes(e.target.value)}
              aria-label="Order notes" />
            <button type="button" className="btn btn-sm btn-mp-outline mt-2" disabled={savingNotes} onClick={saveNotes}>
              {savingNotes ? 'Saving…' : 'Save notes'}
            </button>

            {a.viewer_party === 'business' && a.customer_notes && (
              <div className="mt-3 small">
                <div className="text-muted fw-semibold">Customer notes</div>
                <div>{a.customer_notes}</div>
              </div>
            )}
            {a.viewer_party === 'customer' && a.business_notes && (
              <div className="mt-3 small">
                <div className="text-muted fw-semibold">Business notes</div>
                <div>{a.business_notes}</div>
              </div>
            )}
          </div>

          {/* Phase 10. Either party, once, and only an admin can decide it. */}
          <RaiseDisputeCard order={order} onRaised={load} />

          {order.relationships.business && a.viewer_party !== 'business' && (
            <div className="mp-card p-4">
              <h2 className="h6 mb-2">Supplier</h2>
              <div className="fw-semibold">{order.relationships.business.attributes.name}</div>
              {order.relationships.business.relationships?.location && (
                <div className="small text-muted">
                  {[order.relationships.business.relationships.location.attributes.city,
                    order.relationships.business.relationships.location.attributes.state].filter(Boolean).join(', ')}
                </div>
              )}
              <Link to={`/business/${order.relationships.business.attributes.slug}`} className="btn btn-sm btn-mp-ghost px-0 mt-2">
                View profile
              </Link>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
