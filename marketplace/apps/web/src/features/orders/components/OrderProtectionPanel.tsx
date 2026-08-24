import { useState } from 'react';
import { Link } from 'react-router-dom';
import Icon from '../../../components/ui/Icon';

/**
 * What the platform guarantees on an order, stated before anything goes wrong.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * The order page already handles disputes — it shows an active one and offers a
 * RaiseDisputeCard. But both only appear once there is a problem, which means the
 * protections are invisible at the moment they would actually reassure someone:
 * before they pay.
 *
 * Everything listed here is a guarantee the codebase genuinely enforces:
 *
 *  - Totals are computed server-side (no endpoint accepts an amount).
 *  - The negotiation ledger and order status history are append-only.
 *  - Either party can raise a dispute; only an admin can decide one.
 *  - Reviews require a completed order and are one per order.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT DELIBERATELY DOES NOT CLAIM
 * ---------------------------------------------------------------------------
 * There is no escrow in this product. Payments are recorded and the supplier
 * confirms receipt; the money itself moves off-platform.
 *
 * So this panel says nothing about funds being held, released or refunded by us,
 * and the closing line says plainly that we do not hold the money. Writing
 * "Buyer Protection" over a system with no escrow would be the single most
 * dishonest thing this interface could do — it would earn trust the platform has
 * not built, and the first person to be defrauded would be right to say they were
 * misled.
 *
 * Collapsed by default. A buyer mid-transaction wants the order, not a policy
 * essay; the summary line carries the reassurance and the detail is one click away.
 */
export default function OrderProtectionPanel() {
  const [open, setOpen] = useState(false);

  return (
    <section className="mp-protect">
      <button
        type="button"
        className="mp-protect__summary"
        aria-expanded={open}
        aria-controls="order-protection-detail"
        onClick={() => setOpen((value) => !value)}
      >
        <span className="mp-protect__icon">
          <Icon name="shield" size={18} />
        </span>
        <span className="mp-protect__text">
          <strong>How this order is protected</strong>
          <span className="mp-text-caption text-muted">
            Server-calculated totals, an append-only record, and an admin-decided dispute route.
          </span>
        </span>
        <Icon name={open ? 'check' : 'arrow-right'} size={16} className="mp-protect__chevron" />
      </button>

      {open && (
        <div id="order-protection-detail" className="mp-protect__detail">
          <ul className="mp-protect__list">
            <li>
              <Icon name="calculator" size={15} />
              <span>
                <strong>Every figure is calculated by the platform.</strong> Totals, taxes and balances are
                computed server-side, so a price cannot be altered in a browser after you agreed it.
              </span>
            </li>
            <li>
              <Icon name="ledger" size={15} />
              <span>
                <strong>The record cannot be rewritten.</strong> Every offer, counter-offer and status change is
                appended, never edited or deleted. What was agreed stays provable.
              </span>
            </li>
            <li>
              <Icon name="scale" size={15} />
              <span>
                <strong>Neither side decides its own dispute.</strong> You or the supplier can escalate at any
                point. An administrator reviews the order history and rules on it.
              </span>
            </li>
            <li>
              <Icon name="star" size={15} />
              <span>
                <strong>Reviews are earned, not bought.</strong> Only the buyer on a completed order can review
                it, once. That is why the ratings you compared were meaningful.
              </span>
            </li>
          </ul>

          {/*
            * The honest limitation, stated in the same panel as the guarantees rather
            * than buried in terms elsewhere. A buyer deciding how much to pay up
            * front needs this to be the thing they read next.
            */}
          <p className="mp-protect__caveat">
            <Icon name="lock" size={14} />
            <span>
              <strong>We do not hold your money.</strong> Payment is arranged directly with the supplier and
              recorded here for both parties. For larger orders, agree a staged payment rather than paying in
              full up front.
            </span>
          </p>

          <Link to="/dashboard/disputes" className="btn btn-mp-outline btn-sm">
            See how disputes work
          </Link>
        </div>
      )}
    </section>
  );
}
