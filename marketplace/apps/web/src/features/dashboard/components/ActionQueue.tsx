import { Link } from 'react-router-dom';
import Icon, { type IconName } from '../../../components/ui/Icon';

export type BadgeKey =
  | 'leads'
  | 'opportunities'
  | 'enquiries'
  | 'quotes'
  | 'negotiations'
  | 'orders'
  | 'reviews'
  | 'disputes';

export type Badges = Partial<Record<BadgeKey, number>>;

interface ActionDefinition {
  to: string;
  icon: IconName;
  /** Written as the thing the user must do, not the name of a screen. */
  label: (count: number) => string;
  /** `urgent` outranks `normal` in the ordering below. */
  urgency: 'urgent' | 'normal';
}

/**
 * What each count actually means, expressed as an instruction.
 *
 * The wording is the point. A sidebar already says "Quotes 3"; repeating that here
 * would add a second list of nouns. "3 quotes waiting for your decision" tells the
 * user what is being asked of them, which is the only reason to surface it twice.
 */
const CUSTOMER_ACTIONS: Partial<Record<BadgeKey, ActionDefinition>> = {
  disputes: {
    to: '/dashboard/disputes',
    icon: 'scale',
    urgency: 'urgent',
    label: (n) => `${n} open ${n === 1 ? 'dispute needs' : 'disputes need'} your input`,
  },
  quotes: {
    to: '/dashboard/quotes',
    icon: 'receipt',
    urgency: 'urgent',
    label: (n) => `${n} ${n === 1 ? 'quote is' : 'quotes are'} waiting for your decision`,
  },
  negotiations: {
    to: '/dashboard/negotiations',
    icon: 'handshake',
    urgency: 'urgent',
    label: (n) => `${n} ${n === 1 ? 'counter-offer needs' : 'counter-offers need'} your reply`,
  },
  orders: {
    to: '/dashboard/orders',
    icon: 'package',
    urgency: 'normal',
    label: (n) => `${n} ${n === 1 ? 'order is' : 'orders are'} in transit or ready to confirm`,
  },
};

const OWNER_ACTIONS: Partial<Record<BadgeKey, ActionDefinition>> = {
  disputes: {
    to: '/dashboard/disputes',
    icon: 'scale',
    urgency: 'urgent',
    label: (n) => `${n} open ${n === 1 ? 'dispute needs' : 'disputes need'} your response`,
  },
  negotiations: {
    to: '/dashboard/negotiations',
    icon: 'handshake',
    urgency: 'urgent',
    label: (n) => `${n} ${n === 1 ? 'counter-offer is' : 'counter-offers are'} waiting on you`,
  },
  orders: {
    to: '/dashboard/orders',
    icon: 'package',
    urgency: 'urgent',
    label: (n) => `${n} ${n === 1 ? 'order needs' : 'orders need'} confirming`,
  },
  opportunities: {
    to: '/dashboard/matched-requirements',
    icon: 'compass',
    urgency: 'normal',
    label: (n) => `${n} matched ${n === 1 ? 'requirement is' : 'requirements are'} open to quote`,
  },
  enquiries: {
    to: '/dashboard/enquiries',
    icon: 'chat',
    urgency: 'normal',
    label: (n) => `${n} new ${n === 1 ? 'enquiry' : 'enquiries'} to accept or decline`,
  },
  leads: {
    to: '/dashboard/leads',
    icon: 'inbox',
    urgency: 'normal',
    label: (n) => `${n} new ${n === 1 ? 'lead' : 'leads'} from your listings`,
  },
  reviews: {
    to: '/dashboard/reviews',
    icon: 'star',
    urgency: 'normal',
    label: (n) => `${n} ${n === 1 ? 'review' : 'reviews'} you can respond to`,
  },
};

/**
 * The "needs you" queue that opens both dashboards.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * Both dashboards previously opened with a list of *categories of things* —
 * Orders, Quotes, Payments — which is a filing cabinet, not a dashboard. The user
 * had to open each one to discover whether anything needed them.
 *
 * The counts to answer that were already being computed for the sidebar badges.
 * They were just never assembled into the one question a person actually arrives
 * with: what do I have to do today.
 *
 * ---------------------------------------------------------------------------
 * WHY IT DISAPPEARS WHEN EMPTY
 * ---------------------------------------------------------------------------
 * A permanent panel reading "nothing needs your attention" trains people to skip
 * that region of the page, and once they skip it they also skip it on the day it
 * has something in it. Rendering nothing keeps the presence of the panel
 * meaningful: if it is there, it matters.
 *
 * The empty case is handled by the caller, which shows a short "you are up to
 * date" line instead — see DashboardLayout.
 */
export default function ActionQueue({ badges, role }: { badges: Badges; role: 'owner' | 'customer' }) {
  const definitions = role === 'owner' ? OWNER_ACTIONS : CUSTOMER_ACTIONS;

  const actions = (Object.keys(definitions) as BadgeKey[])
    .map((key) => ({ key, count: badges[key] ?? 0, definition: definitions[key]! }))
    .filter((entry) => entry.count > 0)
    // Urgent first, then by size. Ordering by count alone would bury a single
    // open dispute — the most serious thing on the page — beneath six new leads.
    .sort((a, b) => {
      if (a.definition.urgency !== b.definition.urgency) {
        return a.definition.urgency === 'urgent' ? -1 : 1;
      }

      return b.count - a.count;
    });

  if (actions.length === 0) {
    return null;
  }

  const total = actions.reduce((sum, entry) => sum + entry.count, 0);

  return (
    <section className="mp-queue" aria-labelledby="action-queue-heading">
      <div className="mp-queue__head">
        <h2 id="action-queue-heading" className="mp-queue__title">
          Needs you
        </h2>
        {/*
          * The total is announced as text rather than shown only as a coloured
          * count, so it is available to a screen reader in one read of the heading
          * region instead of requiring a walk through every row.
          */}
        <span className="mp-queue__total">
          {total} {total === 1 ? 'item' : 'items'}
        </span>
      </div>

      <ul className="mp-queue__list">
        {actions.map(({ key, count, definition }) => (
          <li key={key}>
            <Link to={definition.to} className={`mp-queue__item mp-queue__item--${definition.urgency}`}>
              <span className="mp-queue__icon">
                <Icon name={definition.icon} size={18} />
              </span>
              <span className="mp-queue__label">{definition.label(count)}</span>
              <Icon name="arrow-right" size={16} className="mp-queue__chevron" />
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}
