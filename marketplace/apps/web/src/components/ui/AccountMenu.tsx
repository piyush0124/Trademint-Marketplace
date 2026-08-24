import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import Icon, { type IconName } from './Icon';

interface AccountMenuProps {
  name: string;
  email?: string;
  roleLabel: string;
  dashboardHref: string;
  isOwner: boolean;
  isAdmin: boolean;
  onLogout: () => void;
}

/**
 * The account cluster: one avatar button that opens a menu.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS REPLACES FOUR LOOSE CONTROLS
 * ---------------------------------------------------------------------------
 * The navbar previously carried "Dashboard", a primary action button, a bare
 * avatar and a "Log out" button as four separate top-level items, plus three nav
 * links, a search field and a notification bell. Nine competing controls on one
 * 64px row.
 *
 * The visible consequence was not subtle: the nav links wrapped onto two lines,
 * the search placeholder truncated mid-word, and the bell drifted outside the
 * account group. A navbar that wraps is a navbar with more in it than fits.
 *
 * Collapsing the account items behind the avatar is the standard fix because it
 * matches how often each is used. Log out is pressed once per session at most;
 * giving it permanent real estate next to the primary action was backwards.
 *
 * ---------------------------------------------------------------------------
 * ACCESSIBILITY
 * ---------------------------------------------------------------------------
 * A real menu, not a hover card: `aria-haspopup="menu"` with `aria-expanded`, the
 * panel is `role="menu"` and its items are `role="menuitem"`, Escape closes and
 * returns focus to the trigger, and a click outside dismisses it. The trigger's
 * accessible name includes the user's name, so it is not announced as just "button".
 *
 * Opens on click rather than hover — a hover menu is unusable on touch and
 * unreachable by keyboard without extra handling.
 */
export default function AccountMenu({
  name,
  email,
  roleLabel,
  dashboardHref,
  isOwner,
  isAdmin,
  onLogout,
}: AccountMenuProps) {
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setOpen(false);
        triggerRef.current?.focus();
      }
    };

    const onPointerDown = (event: PointerEvent) => {
      const target = event.target as Node;

      if (!panelRef.current?.contains(target) && !triggerRef.current?.contains(target)) {
        setOpen(false);
      }
    };

    document.addEventListener('keydown', onKeyDown);
    document.addEventListener('pointerdown', onPointerDown);

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.removeEventListener('pointerdown', onPointerDown);
    };
  }, [open]);

  const go = (to: string) => {
    setOpen(false);
    navigate(to);
  };

  /*
   * Menu contents differ by role because the destinations do. An admin has no
   * business profile or saved list; a buyer has no verification flow. Showing a
   * link that 403s or redirects is worse than not showing it.
   */
  const items: { label: string; to: string; icon: IconName }[] = isAdmin
    ? [
        { label: 'Platform overview', to: '/admin', icon: 'chart' },
        { label: 'Trust & oversight', to: '/admin/oversight', icon: 'shield' },
      ]
    : isOwner
      ? [
          { label: 'Dashboard', to: dashboardHref, icon: 'chart' },
          { label: 'Business profile', to: '/dashboard/business', icon: 'building' },
          { label: 'Verification', to: '/dashboard/verification', icon: 'shield' },
          { label: 'Orders', to: '/dashboard/orders', icon: 'package' },
        ]
      : [
          { label: 'Dashboard', to: dashboardHref, icon: 'chart' },
          { label: 'My requirements', to: '/dashboard/requirements', icon: 'clipboard' },
          { label: 'Orders', to: '/dashboard/orders', icon: 'package' },
          { label: 'Saved', to: '/dashboard/favorites', icon: 'heart' },
        ];

  const initial = name.trim().charAt(0).toUpperCase() || '?';

  return (
    <div className="mp-account">
      <button
        ref={triggerRef}
        type="button"
        className="mp-account__trigger"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls="account-menu"
        aria-label={`Account menu for ${name}`}
        onClick={() => setOpen((value) => !value)}
      >
        <span className="mp-account__avatar" aria-hidden="true">
          {initial}
        </span>
        {/* Chevron rotates to signal state, and is decorative — the state is
            already carried by aria-expanded. */}
        <svg
          className={`mp-account__caret${open ? ' is-open' : ''}`}
          width="10"
          height="10"
          viewBox="0 0 10 10"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.6"
          strokeLinecap="round"
          aria-hidden="true"
          focusable="false"
        >
          <path d="m2 4 3 3 3-3" />
        </svg>
      </button>

      {open && (
        <div ref={panelRef} id="account-menu" className="mp-account__panel" role="menu" aria-label="Account">
          <div className="mp-account__identity">
            <span className="mp-account__avatar mp-account__avatar--lg" aria-hidden="true">
              {initial}
            </span>
            <span className="mp-account__who">
              <strong>{name}</strong>
              {/* Email can be long; it truncates rather than widening the panel. */}
              {email && <span className="mp-account__email">{email}</span>}
              <span className="mp-account__role">{roleLabel}</span>
            </span>
          </div>

          <div className="mp-account__group">
            {items.map((item) => (
              <button key={item.to} type="button" role="menuitem" className="mp-account__item" onClick={() => go(item.to)}>
                <Icon name={item.icon} size={15} />
                {item.label}
              </button>
            ))}
          </div>

          <div className="mp-account__group mp-account__group--last">
            <Link to="/" role="menuitem" className="mp-account__item" onClick={() => setOpen(false)}>
              <Icon name="search" size={15} />
              Browse the marketplace
            </Link>
            <button
              type="button"
              role="menuitem"
              className="mp-account__item mp-account__item--danger"
              onClick={() => {
                setOpen(false);
                onLogout();
              }}
            >
              <Icon name="lock" size={15} />
              Log out
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
