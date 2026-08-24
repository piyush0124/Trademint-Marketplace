import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../features/auth/AuthContext';
import { BRAND } from '../config/brand';
import AiAssistantPanel from '../features/ai/components/AiAssistantPanel';
import NotificationBell from '../features/notifications/components/NotificationBell';
import NavbarSearch from '../components/ui/NavbarSearch';
import AccountMenu from '../components/ui/AccountMenu';

/**
 * The public app shell: header, page outlet, footer.
 *
 * Two behaviours worth knowing about:
 *
 *  - The header is flush against the hero at rest and grows a border + shadow
 *    only once the page scrolls. A static border across the top of a full-bleed
 *    hero is the single most common way to make a landing page look unfinished.
 *  - Below `lg` the links collapse into a sheet rather than shrinking. It closes
 *    on navigation and on Escape, and returns focus to the toggle.
 */
const PUBLIC_LINKS = [
  { to: '/suppliers', label: 'Find suppliers' },
  { to: '/browse', label: 'Browse listings' },
  { to: '/#how-it-works', label: 'How it works' },
];

export default function Layout() {
  const { isAuthenticated, isOwner, isCustomer, isAdmin, user, logout } = useAuth();
  const location = useLocation();
  const [scrolled, setScrolled] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  useEffect(() => setMenuOpen(false), [location.pathname, location.hash]);

  useEffect(() => {
    if (!menuOpen) return;

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setMenuOpen(false);
    };

    document.addEventListener('keydown', onKeyDown);

    return () => document.removeEventListener('keydown', onKeyDown);
  }, [menuOpen]);

  // The homepage hero carries its own full search, so the navbar one is suppressed
  // there rather than presenting the same action twice in one viewport.
  const isHome = location.pathname === '/';

  const dashboardHref = isAdmin ? '/admin' : '/dashboard';
  // The primary action differs by role: a customer states a need, a business
  // goes looking for work. Showing both to everyone would help nobody.
  const primaryAction = isCustomer
    ? { to: '/requirements/new', label: 'Post a requirement' }
    : isOwner
      ? { to: '/dashboard/matched-requirements', label: 'Find work' }
      : null;

  return (
    <>
      <a href="#main-content" className="visually-hidden-focusable btn btn-mp-primary m-2">
        Skip to content
      </a>

      <header className={`mp-navbar ${scrolled ? 'is-scrolled' : ''}`}>
        <div className="mp-container d-flex align-items-center justify-content-between gap-3 w-100">
          <Link className="mp-navbar__brand" to="/" aria-label={`${BRAND.name} home`}>
            <span className="mp-navbar__mark" aria-hidden="true">{BRAND.name.charAt(0)}</span>
            {/*
              * Both halves of the wordmark live inside ONE element.
              *
              * They used to be two separate flex children of .mp-navbar__brand, which
              * has `gap: 0.5rem` for spacing the logo mark from the text — so the gap
              * also applied between them and the brand rendered as "Trade mint" with a
              * visible 8px hole in the middle of the word.
              */}
            <span className="mp-navbar__wordmark">
              {BRAND.namePrefix}
              <span className="mp-navbar__wordmark-accent">{BRAND.nameSuffix}</span>
            </span>
          </Link>

          <nav className="d-none d-lg-flex align-items-center gap-4" aria-label="Main">
            {PUBLIC_LINKS.map((link) =>
              link.to.includes('#') ? (
                <a key={link.to} className="mp-navbar__link" href={link.to}>{link.label}</a>
              ) : (
                <NavLink
                  key={link.to}
                  to={link.to}
                  className={({ isActive }) => `mp-navbar__link ${isActive ? 'is-active' : ''}`}
                >
                  {link.label}
                </NavLink>
              )
            )}
          </nav>

          {/*
            * Search, from lg up.
            *
            * Hidden on the homepage because the hero already carries the full
            * two-field search directly below — showing both would present the same
            * action twice within one viewport, and the navbar copy is the weaker of
            * the two. Everywhere else it is the only way to search.
            */}
          {!isHome && (
            /*
             * No flex-grow here. The previous `flex-grow-1` wrapper let the search
             * absorb all spare width, which starved the nav links until they wrapped
             * onto two lines and clipped the search's own placeholder. Sizing now
             * lives entirely in .mp-navsearch, which yields space instead of taking it.
             */
            <div className="d-none d-lg-flex px-2">
              <NavbarSearch />
            </div>
          )}

          {/*
            * One right-hand cluster for everything.
            *
            * Previously this was two sibling groups — a `d-lg-flex` block for the
            * account controls and a separate always-visible block for the bell and
            * the menu toggle. That is why the bell appeared detached from the avatar
            * on desktop: it was in a different flex container.
            *
            * `flex-shrink: 0` via .mp-navbar__actions is what stops this cluster
            * being compressed by the search field, which was the other half of the
            * wrapping problem.
            */}
          <div className="mp-navbar__actions">
            {/* Below lg the search collapses to an icon that expands over the row. */}
            {!isHome && (
              <span className="d-lg-none">
                <NavbarSearch compact />
              </span>
            )}

            {isAuthenticated ? (
              <>
                {/* The single primary action, from lg up. It is the one thing on this
                    row worth a filled button, so nothing else competes with it. */}
                {primaryAction && (
                  <Link className="btn btn-mp-accent btn-sm d-none d-lg-inline-flex" to={primaryAction.to}>
                    {primaryAction.label}
                  </Link>
                )}

                {/* Rendered exactly once, at every breakpoint. Two instances would mean
                    two pollers per tab for one badge, and two counts that can disagree. */}
                <NotificationBell enabled={isAuthenticated} />

                {/* Dashboard, profile links and Log out now live in here rather than
                    as four loose controls on the row. */}
                <span className="d-none d-lg-inline-flex">
                  <AccountMenu
                    name={user?.attributes.name ?? 'Account'}
                    email={user?.attributes.email}
                    roleLabel={isAdmin ? 'Administrator' : isOwner ? 'Business account' : 'Buyer account'}
                    dashboardHref={dashboardHref}
                    isOwner={isOwner}
                    isAdmin={isAdmin}
                    onLogout={() => logout()}
                  />
                </span>
              </>
            ) : (
              <>
                <Link className="mp-navbar__link d-none d-lg-inline-flex" to="/login">
                  Log in
                </Link>
                <Link className="btn btn-mp-primary btn-sm d-none d-lg-inline-flex" to="/register">
                  Get started
                </Link>
              </>
            )}

            <button
              type="button"
              className="mp-navbar__toggle d-lg-none"
              aria-expanded={menuOpen}
              aria-controls="mobile-nav"
              aria-label={menuOpen ? 'Close menu' : 'Open menu'}
              onClick={() => setMenuOpen((open) => !open)}
            >
              <span aria-hidden="true" />
              <span aria-hidden="true" />
              <span aria-hidden="true" />
            </button>
          </div>
        </div>
      </header>

      {/*
        * The mobile sheet mirrors the desktop grouping rather than being a flat list.
        *
        * Signed in, the primary action leads as a filled button — on a phone it is the
        * reason most people opened the menu — then navigation, then the account block
        * with Log out separated at the bottom. Previously all of it was one
        * undifferentiated column of links with Log out sitting directly beneath
        * "Dashboard", which is easy to hit by accident.
        */}
      {menuOpen && (
        <div className="mp-navsheet d-lg-none" id="mobile-nav">
          {isAuthenticated && (
            <>
              <div className="mp-navsheet__who">
                <span className="mp-account__avatar" aria-hidden="true">
                  {(user?.attributes.name ?? '?').charAt(0).toUpperCase()}
                </span>
                <span>
                  <strong>{user?.attributes.name}</strong>
                  <span className="mp-account__role">
                    {isAdmin ? 'Administrator' : isOwner ? 'Business account' : 'Buyer account'}
                  </span>
                </span>
              </div>

              {primaryAction && (
                <Link className="btn btn-mp-accent w-100 mb-2" to={primaryAction.to}>
                  {primaryAction.label}
                </Link>
              )}
            </>
          )}

          <div className="mp-navsheet__group">
            <span className="mp-navsheet__heading">Marketplace</span>
            {PUBLIC_LINKS.map((link) =>
              link.to.includes('#') ? (
                <a key={link.to} href={link.to}>{link.label}</a>
              ) : (
                <Link key={link.to} to={link.to}>{link.label}</Link>
              )
            )}
          </div>

          {isAuthenticated ? (
            <>
              <div className="mp-navsheet__group">
                <span className="mp-navsheet__heading">Your account</span>
                <Link to={dashboardHref}>{isAdmin ? 'Admin overview' : 'Dashboard'}</Link>
                {!isAdmin && <Link to="/dashboard/orders">Orders</Link>}
                {isOwner && <Link to="/dashboard/business">Business profile</Link>}
                {isCustomer && <Link to="/dashboard/requirements">My requirements</Link>}
              </div>

              <div className="mp-navsheet__group mp-navsheet__group--last">
                <button type="button" className="mp-navsheet__danger" onClick={() => logout()}>
                  Log out
                </button>
              </div>
            </>
          ) : (
            <div className="mp-navsheet__group mp-navsheet__group--last">
              <span className="mp-navsheet__heading">Get started</span>
              <Link to="/login">Log in</Link>
              <Link to="/register">Sign up as a buyer</Link>
              <Link to="/register/owner">List your business</Link>
            </div>
          )}
        </div>
      )}

      <main id="main-content">
        <Outlet />
      </main>

      {/*
        The AI assistant, available on every page for a signed-in user.

        It renders nothing at all when AI is disabled or the provider is unreachable —
        no greyed button, no error banner. That is the fallback contract: an AI outage
        should be invisible, and the marketplace should simply look like itself.
      */}
      {isAuthenticated && <AiAssistantPanel />}

      <footer className="mp-footer">
        <div className="mp-container">
          <div className="row g-4">
            <div className="col-12 col-lg-4">
              <div className="mp-footer__brand">{BRAND.name}</div>
              <p className="mt-2 mb-0" style={{ fontSize: '0.9rem', maxWidth: '26rem' }}>
                {BRAND.promise}
              </p>
            </div>

            <div className="col-6 col-md-3 col-lg-2">
              <h3>Buyers</h3>
              <Link to="/requirements/new">Post a requirement</Link>
              <Link to="/suppliers">Find suppliers</Link>
              <Link to="/browse">Browse listings</Link>
              <Link to="/register">Create an account</Link>
            </div>

            <div className="col-6 col-md-3 col-lg-2">
              <h3>Businesses</h3>
              <Link to="/register/owner">List your business</Link>
              <Link to="/dashboard/matched-requirements">Find work</Link>
              <Link to="/dashboard/verification">Get verified</Link>
              <Link to="/dashboard/quotes">Send quotes</Link>
            </div>

            <div className="col-6 col-md-3 col-lg-2">
              <h3>Platform</h3>
              <a href="/#how-it-works">How it works</a>
              <a href="/#trust">Trust &amp; safety</a>
              <a href={`mailto:${BRAND.supportEmail}`}>Contact support</a>
            </div>
          </div>

          <div className="mp-footer__bottom d-flex flex-wrap justify-content-between gap-2">
            <span>
              © {new Date().getFullYear()} {BRAND.name}. {BRAND.tagline}.
            </span>
            <span>Built for every industry — pan-India.</span>
          </div>
        </div>
      </footer>
    </>
  );
}
