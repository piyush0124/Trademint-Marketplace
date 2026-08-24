import { useEffect, useState } from 'react';
import { api, getErrorMessage } from '../../../lib/api/client';
import { formatMoney } from '../../../lib/money';
import type { ApiPayment, OrderPaymentsResponse, PaymentMethod } from '../../../types/api';
import LoadingState from '../../../components/ui/LoadingState';
import StatusBadge from '../../../components/ui/StatusBadge';

/**
 * The payment panel on an order.
 *
 * Both sides use it, and each sees only what it may do: the customer pays, the
 * business confirms offline receipt or refunds. Every button is driven by the API's
 * own `can_*` flags, so the UI cannot offer an action the backend would refuse.
 *
 * Nothing here decides that a payment succeeded. After a redirect-based payment the
 * panel simply re-reads the server; the status shown is always the server's.
 */
interface OrderPaymentPanelProps {
  orderId: string;
  /** Bumped by the parent after any order change, to force a refresh. */
  refreshKey?: number;
  onSettled?: () => void;
}

export default function OrderPaymentPanel({ orderId, refreshKey = 0, onSettled }: OrderPaymentPanelProps) {
  const [payments, setPayments] = useState<ApiPayment[]>([]);
  const [methods, setMethods] = useState<PaymentMethod[]>([]);
  const [amountDue, setAmountDue] = useState('0.00');
  const [currency, setCurrency] = useState('INR');
  const [isPayable, setIsPayable] = useState(false);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [provider, setProvider] = useState<string>('');
  const [refundFor, setRefundFor] = useState<string | null>(null);
  const [refundAmount, setRefundAmount] = useState('');
  const [refundReason, setRefundReason] = useState('');

  const load = () => {
    api
      .get<OrderPaymentsResponse>(`/orders/${orderId}/payments`)
      .then((res) => {
        setPayments(res.data.data);
        setMethods(res.data.meta.methods);
        setAmountDue(res.data.meta.amount_due);
        setCurrency(res.data.meta.currency);
        setIsPayable(res.data.meta.is_payable);
        setProvider((current) => current || res.data.meta.methods.find((m) => m.is_default)?.provider || '');
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  useEffect(load, [orderId, refreshKey]);

  /**
   * Starting a payment sends an idempotency key, so a double-click or a retry
   * cannot create two attempts against the same order.
   */
  const pay = async () => {
    setError('');
    setBusy(true);
    try {
      const res = await api.post<{ data: ApiPayment }>(
        `/orders/${orderId}/payments`,
        { provider },
        { headers: { 'Idempotency-Key': `${orderId}-${amountDue}-${provider}` } }
      );

      const checkout = res.data.data.attributes.checkout;

      // Redirect-based providers hand back a URL; everything else is settled
      // out of band and confirmed server-side.
      if (typeof checkout.redirect_url === 'string' && checkout.redirect_url) {
        window.location.assign(checkout.redirect_url);

        return;
      }

      load();
      onSettled?.();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const act = async (payment: ApiPayment, path: string, body: Record<string, unknown> = {}) => {
    setError('');
    setBusy(true);
    try {
      await api.post(`/payments/${payment.id}/${path}`, body);
      setRefundFor(null);
      setRefundAmount('');
      setRefundReason('');
      load();
      onSettled?.();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  if (status === 'loading') return <div className="mp-card p-4"><LoadingState rows={2} /></div>;
  if (status === 'error') return null;

  const dueValue = Number(amountDue);

  return (
    <div className="mp-card p-4 mb-3">
      <div className="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <h2 className="h6 mb-0">Payment</h2>
        <div className="text-end">
          <div className="small text-muted">Outstanding</div>
          <div className="fw-semibold">{formatMoney(amountDue, currency)}</div>
        </div>
      </div>

      {error && <div className="alert alert-danger py-2 small" role="alert">{error}</div>}

      {dueValue <= 0 && payments.length > 0 && (
        <div className="alert alert-success py-2 small" role="status">This order is fully paid.</div>
      )}

      {/* Customer: pay what is outstanding. */}
      {isPayable && dueValue > 0 && payments.every((p) => !p.attributes.is_viewer_business) && (
        <div className="mb-3">
          <label className="form-label small" htmlFor="payment-provider">Payment method</label>
          <select
            id="payment-provider"
            className="form-select form-select-sm mb-2"
            value={provider}
            onChange={(event) => setProvider(event.target.value)}
          >
            {methods.map((method) => (
              <option key={method.provider} value={method.provider}>{method.label}</option>
            ))}
          </select>
          <button type="button" className="btn btn-mp-primary" disabled={busy || provider === ''} onClick={pay}>
            {busy ? 'Working…' : `Pay ${formatMoney(amountDue, currency)}`}
          </button>
        </div>
      )}

      {payments.length === 0 && <p className="small text-muted mb-0">No payments have been started yet.</p>}

      <div className="d-flex flex-column gap-2">
        {payments.map((payment) => {
          const a = payment.attributes;

          return (
            <div key={payment.id} className="border-top pt-2">
              <div className="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                  <div className="fw-semibold small">{a.payment_reference}</div>
                  <div className="small text-muted">
                    {a.provider}
                    {a.payment_method && ` · ${a.payment_method}`}
                    {a.paid_at && ` · paid ${a.paid_at.slice(0, 10)}`}
                  </div>
                  {a.failure_reason && <div className="small text-danger">{a.failure_reason}</div>}
                  {/* Instructions for offline settlement, straight from config. */}
                  {typeof a.checkout.instructions === 'string' && a.status !== 'paid' && (
                    <div className="small text-muted mt-1">{a.checkout.instructions}</div>
                  )}
                </div>
                <div className="text-end">
                  <div className="fw-semibold">{formatMoney(a.amount, a.currency)}</div>
                  <StatusBadge status={a.status} />
                  {Number(a.amount_refunded) > 0 && (
                    <div className="small text-muted mt-1">{formatMoney(a.amount_refunded, a.currency)} refunded</div>
                  )}
                </div>
              </div>

              <div className="d-flex gap-2 flex-wrap mt-2">
                {/* Only the receiving business sees these. */}
                {a.can_confirm && (
                  <button type="button" className="btn btn-sm btn-mp-primary" disabled={busy} onClick={() => act(payment, 'confirm')}>
                    Confirm payment received
                  </button>
                )}
                {a.can_refund && (
                  <button
                    type="button"
                    className="btn btn-sm btn-mp-ghost text-danger"
                    disabled={busy}
                    onClick={() => {
                      setRefundFor(refundFor === payment.id ? null : payment.id);
                      setRefundAmount(a.refundable_amount);
                    }}
                  >
                    Refund
                  </button>
                )}
              </div>

              {refundFor === payment.id && (
                <div className="mt-2 p-3 mp-counter-summary">
                  <div className="row g-2">
                    <div className="col-12 col-sm-5">
                      <label className="form-label small" htmlFor={`refund-amount-${payment.id}`}>Amount</label>
                      <input
                        id={`refund-amount-${payment.id}`}
                        type="number"
                        min="0.01"
                        step="0.01"
                        max={a.refundable_amount}
                        className="form-control form-control-sm"
                        value={refundAmount}
                        onChange={(event) => setRefundAmount(event.target.value)}
                      />
                      <div className="form-text small">Up to {formatMoney(a.refundable_amount, a.currency)}</div>
                    </div>
                    <div className="col-12 col-sm-7">
                      <label className="form-label small" htmlFor={`refund-reason-${payment.id}`}>Reason</label>
                      <input
                        id={`refund-reason-${payment.id}`}
                        className="form-control form-control-sm"
                        maxLength={2000}
                        value={refundReason}
                        onChange={(event) => setRefundReason(event.target.value)}
                      />
                    </div>
                  </div>
                  <div className="d-flex gap-2 mt-2">
                    <button
                      type="button"
                      className="btn btn-sm btn-mp-primary"
                      disabled={busy || refundAmount === ''}
                      onClick={() => act(payment, 'refund', { amount: refundAmount, ...(refundReason ? { reason: refundReason } : {}) })}
                    >
                      Confirm refund
                    </button>
                    <button type="button" className="btn btn-sm btn-mp-ghost" onClick={() => setRefundFor(null)}>Cancel</button>
                  </div>
                </div>
              )}

              {(payment.relationships.refunds ?? []).length > 0 && (
                <ul className="list-unstyled small text-muted mt-2 mb-0">
                  {(payment.relationships.refunds ?? []).map((refund) => (
                    <li key={refund.id}>
                      {refund.attributes.refund_reference}: {formatMoney(refund.attributes.amount, refund.attributes.currency)}
                      {' · '}{refund.attributes.status_label}
                      {refund.attributes.reason ? ` · ${refund.attributes.reason}` : ''}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}
