import { useEffect, useRef, useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { api } from '../../../lib/api/client';
import type {
  ApiLead,
  EnquiryStatusCounts,
  MatchListResponse,
  NegotiationStatusCounts,
  OrderStatusCounts,
  PaginatedResponse,
  QuoteStatusCounts,
} from '../../../types/api';
import { useAuth } from '../../auth/AuthContext';
import { usePrivateSeo } from '../../../lib/useSeo';
import Icon, { type IconName } from '../../../components/ui/Icon';
import ActionQueue, { type BadgeKey, type Badges } from '../components/ActionQueue';

/**
 * Dashboard shell and navigation.
 *
 * Responsive by structure rather than by shrinking: on desktop the navigation is
 * a persistent sidebar; below the lg breakpoint it becomes an off-canvas drawer
 * opened from a toolbar button. The markup is the same, the layout differs.
 *
 * Accessibility: the toggle carries aria-expanded/aria-controls, the open drawer
 * is a labelled dialog that takes focus, Escape closes it, focus returns to the
 * toggle, and background scrolling is locked while it is open. Badge counts are
 * announced as text ("3 waiting"), never conveyed by colour alone.
 */
interface NavItem {
  to: string;
  label: string;
  end?: boolean;
  icon: IconName;
  /** Key into the badge map, when this item can demand attention. */
  badge?: BadgeKey;
}

/**
 * Navigation is grouped, not flat.
 *
 * ---------------------------------------------------------------------------
 * WHY
 * ---------------------------------------------------------------------------
 * The seller navigation had sixteen items in one undifferentiated column. That is
 * past the point where a list can be scanned — a new supplier cannot tell from it
 * what the product expects them to do, in what order, or which of those sixteen
 * words are related to each other.
 *
 * The groups follow the seller's actual sequence: win work, then deliver it, then
 * maintain what you sell, then manage the business itself. Grouping by workflow
 * rather than by data type is what turns a list of screens into a description of
 * the job.
 *
 * Admin stays flat at four items. Grouping four things adds structure nobody
 * needed and makes the sections look emptier than the content warrants.
 */
interface NavGroup {
  heading?: string;
  items: NavItem[];
}

const OWNER_NAV: NavGroup[] = [
  {
    items: [{ to: '/dashboard', label: 'Overview', end: true, icon: 'chart' }],
  },
  {
    heading: 'Win work',
    items: [
      { to: '/dashboard/matched-requirements', label: 'Opportunities', icon: 'compass', badge: 'opportunities' },
      { to: '/dashboard/leads', label: 'Leads', icon: 'inbox', badge: 'leads' },
      { to: '/dashboard/enquiries', label: 'Enquiries', icon: 'chat', badge: 'enquiries' },
      { to: '/dashboard/quotes', label: 'Quotes', icon: 'receipt' },
      { to: '/dashboard/negotiations', label: 'Negotiations', icon: 'handshake', badge: 'negotiations' },
    ],
  },
  {
    heading: 'Deliver',
    items: [
      { to: '/dashboard/orders', label: 'Orders', icon: 'package', badge: 'orders' },
      { to: '/dashboard/payments', label: 'Payments', icon: 'card' },
      { to: '/dashboard/disputes', label: 'Disputes', icon: 'scale', badge: 'disputes' },
    ],
  },
  {
    heading: 'What you sell',
    items: [
      { to: '/dashboard/listings', label: 'Listings', icon: 'clipboard' },
      { to: '/dashboard/products', label: 'Products', icon: 'package' },
      { to: '/dashboard/services', label: 'Services', icon: 'spark' },
    ],
  },
  {
    heading: 'Your business',
    items: [
      { to: '/dashboard/business', label: 'Business profile', icon: 'building' },
      { to: '/dashboard/verification', label: 'Verification', icon: 'shield' },
      { to: '/dashboard/reviews', label: 'Reviews', icon: 'star', badge: 'reviews' },
      { to: '/dashboard/favorites', label: 'Saved', icon: 'heart' },
    ],
  },
];

const CUSTOMER_NAV: NavGroup[] = [
  {
    items: [{ to: '/dashboard', label: 'Overview', end: true, icon: 'chart' }],
  },
  {
    heading: 'Buying',
    items: [
      { to: '/suppliers', label: 'Find suppliers', icon: 'search' },
      { to: '/dashboard/requirements', label: 'Requirements', icon: 'clipboard' },
      { to: '/dashboard/enquiries', label: 'Enquiries', icon: 'chat' },
      { to: '/dashboard/quotes', label: 'Quotes', icon: 'receipt', badge: 'quotes' },
      { to: '/dashboard/negotiations', label: 'Negotiations', icon: 'handshake', badge: 'negotiations' },
    ],
  },
  {
    heading: 'Transactions',
    items: [
      { to: '/dashboard/orders', label: 'Orders', icon: 'package', badge: 'orders' },
      { to: '/dashboard/payments', label: 'Payments', icon: 'card' },
      { to: '/dashboard/reviews', label: 'Reviews', icon: 'star' },
      { to: '/dashboard/disputes', label: 'Disputes', icon: 'scale', badge: 'disputes' },
    ],
  },
  {
    heading: 'Saved',
    items: [{ to: '/dashboard/favorites', label: 'Favourites', end: true, icon: 'heart' }],
  },
];

const ADMIN_NAV: NavGroup[] = [
  {
    items: [
      { to: '/admin', label: 'Platform overview', end: true, icon: 'chart' },
      { to: '/admin/listings', label: 'Listing moderation', icon: 'clipboard' },
      { to: '/admin/oversight', label: 'Trust & oversight', icon: 'shield' },
      { to: '/admin/ai-usage', label: 'AI usage', icon: 'spark' },
    ],
  },
];

export default function DashboardLayout() {
  /*
   * Applied at the shell, so every nested dashboard route inherits noindex
   * without each page having to remember.
   *
   * "It needs a login anyway" is not sufficient reason to skip this: URLs leak
   * through referrer headers, shared links and browser sync, and an indexed URL
   * is an invitation to probe even when the response is a 401.
   */
  usePrivateSeo('Dashboard');

  const { user, isOwner, isAdmin, isCustomer } = useAuth();
  const location = useLocation();

  const [badges, setBadges] = useState<Badges>({});
  const [badgesLoaded, setBadgesLoaded] = useState(false);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const toggleRef = useRef<HTMLButtonElement>(null);
  const drawerRef = useRef<HTMLDivElement>(null);

  /**
   * Attention counts. Every call is independent and failure-tolerant: a badge is
   * a nicety, so one failing endpoint must never blank the navigation.
   */
  useEffect(() => {
    const next: Badges = {};
    let outstanding = 0;

    const settle = (key: BadgeKey, value: number) => {
      next[key] = value;
      setBadges({ ...next });
    };

    /*
     * Tracks completion so the action queue can distinguish "nothing needs you"
     * from "we have not finished asking yet". Without this, the queue flashes its
     * empty state on every dashboard load before the counts arrive, which reads as
     * "all clear" a moment before it says the opposite.
     */
    const track = <T,>(promise: Promise<T>): Promise<T | undefined> => {
      outstanding++;

      return promise
        .catch(() => undefined)
        .finally(() => {
          outstanding--;
          if (outstanding === 0) setBadgesLoaded(true);
        });
    };

    if (isOwner) {
      track(api.get<MatchListResponse>('/users/me/matched-requirements', { params: { per_page: 1 } })
        .then((res) => settle('opportunities', res.data.meta.response_counts?.pending ?? 0)));

      track(api.get<PaginatedResponse<ApiLead, { new: number }>>('/users/me/leads', { params: { per_page: 1 } })
        .then((res) => settle('leads', res.data.meta.status_counts?.new ?? 0)));

      track(api.get<PaginatedResponse<unknown, EnquiryStatusCounts>>('/enquiries', { params: { per_page: 1 } })
        .then((res) => settle('enquiries', res.data.meta.status_counts?.pending ?? 0)));
    }

    if (isCustomer) {
      track(api.get<PaginatedResponse<unknown, QuoteStatusCounts>>('/quotes', { params: { per_page: 1 } })
        .then((res) => {
          const counts = res.data.meta.status_counts;
          settle('quotes', (counts?.sent ?? 0) + (counts?.viewed ?? 0));
        }));
    }

    if (isOwner || isCustomer) {
      // "Waiting on me" is the only negotiation count worth a badge.
      track(api.get<PaginatedResponse<unknown, NegotiationStatusCounts>>('/negotiations', {
        params: { per_page: 1, 'filter[awaiting_me]': 1 },
      }).then((res) => settle('negotiations', res.data.meta.total ?? 0)));

      // Orders needing action: a business confirms, a customer completes or pays.
      track(api.get<PaginatedResponse<unknown, OrderStatusCounts>>('/orders', { params: { per_page: 1 } })
        .then((res) => {
          const counts = res.data.meta.status_counts;
          settle('orders', isOwner
            ? (counts?.pending ?? 0)
            : (counts?.delivered ?? 0) + (counts?.dispatched ?? 0));
        }));

      // Phase 10: an open dispute is the most urgent thing on the dashboard, for
      // either party. Counted from the caller's own scoped list.
      track(api.get<PaginatedResponse<unknown>>('/disputes', { params: { per_page: 1, 'filter[status]': 'open' } })
        .then((res) => settle('disputes', res.data.meta.total ?? 0)));
    }

    if (isOwner) {
      // Reviews awaiting a reply. Answering a review is optional, so this is a
      // nudge rather than a demand — but an unanswered bad review costs work.
      track(api.get<PaginatedResponse<{ attributes: { can_respond: boolean } }>>('/reviews', { params: { per_page: 100 } })
        .then((res) => settle('reviews', res.data.data.filter((review) => review.attributes.can_respond).length)));
    }

    // Admins have no personal queue; nothing was requested, so nothing is pending.
    if (outstanding === 0) setBadgesLoaded(true);
  }, [isOwner, isCustomer]);

  // Route change closes the drawer — otherwise it stays over the new page.
  useEffect(() => setDrawerOpen(false), [location.pathname]);

  useEffect(() => {
    if (!drawerOpen) return;

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setDrawerOpen(false);
        toggleRef.current?.focus();
      }
    };

    document.addEventListener('keydown', onKeyDown);
    document.body.style.overflow = 'hidden';
    drawerRef.current?.focus();

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.body.style.overflow = '';
    };
  }, [drawerOpen]);

  const navGroups = isAdmin ? ADMIN_NAV : isOwner ? OWNER_NAV : CUSTOMER_NAV;

  // Total across every badge, used for the drawer toggle so a mobile user can see
  // there is something waiting without opening the menu to find out.
  const pendingTotal = Object.values(badges).reduce((sum, value) => sum + (value ?? 0), 0);

  const navigation = (
    <nav className="mp-sidebar" aria-label="Dashboard sections">
      {navGroups.map((group, index) => (
        <div className="mp-sidebar__group" key={group.heading ?? `group-${index}`}>
          {group.heading && <h2 className="mp-sidebar__heading">{group.heading}</h2>}

          {group.items.map((item) => {
            const count = item.badge ? (badges[item.badge] ?? 0) : 0;

            return (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.end}
                className={({ isActive }) => `mp-sidebar__link ${isActive ? 'is-active' : ''}`}
              >
                <Icon name={item.icon} size={16} className="mp-sidebar__icon" />
                <span className="mp-sidebar__label">{item.label}</span>
                {count > 0 && (
                  <span className="mp-sidebar__count">
                    {count}
                    <span className="visually-hidden"> waiting</span>
                  </span>
                )}
              </NavLink>
            );
          })}
        </div>
      ))}
    </nav>
  );

  // The queue belongs on the overview only. Repeating it above every sub-page
  // would push the actual content down and quickly become furniture to scroll past.
  const isOverview = location.pathname === '/dashboard';
  const showQueue = isOverview && (isOwner || isCustomer);

  return (
    <div className="mp-container py-4">
      <div className="d-flex justify-content-between align-items-center gap-3 mb-3 mb-lg-4">
        <h1 className="h4 mb-0">Welcome, {user?.attributes.name}</h1>

        {/* Drawer toggle: below lg only. */}
        <button
          ref={toggleRef}
          type="button"
          className="btn btn-mp-outline btn-sm d-lg-none"
          aria-expanded={drawerOpen}
          aria-controls="dashboard-drawer"
          onClick={() => setDrawerOpen(true)}
        >
          Menu
          {pendingTotal > 0 && (
            <>
              {' '}
              <span className="mp-sidebar__count">{pendingTotal}</span>
              <span className="visually-hidden"> items need attention</span>
            </>
          )}
        </button>
      </div>

      <div className="row g-4">
        {/* Persistent sidebar from lg up. */}
        <div className="col-lg-3 d-none d-lg-block">{navigation}</div>

        <div className="col-12 col-lg-9">
          {showQueue && (
            <>
              <ActionQueue badges={badges} role={isOwner ? 'owner' : 'customer'} />

              {/*
                * Shown only once the counts have all resolved, so the page cannot
                * claim "you are up to date" while requests are still outstanding.
                */}
              {badgesLoaded && pendingTotal === 0 && (
                <p className="mp-queue__clear" role="status">
                  <Icon name="check" size={16} /> Nothing needs your attention right now.
                </p>
              )}
            </>
          )}

          <Outlet />
        </div>
      </div>

      {drawerOpen && (
        <>
          <div className="mp-drawer-backdrop" onClick={() => setDrawerOpen(false)} aria-hidden="true" />
          <div
            id="dashboard-drawer"
            ref={drawerRef}
            className="mp-drawer"
            role="dialog"
            aria-modal="true"
            aria-label="Dashboard navigation"
            tabIndex={-1}
          >
            <div className="d-flex justify-content-between align-items-center mb-3">
              <span className="fw-semibold">Menu</span>
              <button
                type="button"
                className="mp-modal-close"
                onClick={() => {
                  setDrawerOpen(false);
                  toggleRef.current?.focus();
                }}
                aria-label="Close navigation"
              >
                {/* A multiplication sign, not the letter x or an emoji cross:
                    it is centred on the em box so the glyph sits square in the
                    button without optical nudging. */}
                &times;
              </button>
            </div>
            {navigation}
          </div>
        </>
      )}
    </div>
  );
}
