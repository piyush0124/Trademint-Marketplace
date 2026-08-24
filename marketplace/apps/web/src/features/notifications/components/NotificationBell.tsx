import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import Icon, { type IconName } from '../../../components/ui/Icon';
import { relativeTime, useNotifications } from '../useNotifications';

/**
 * The navbar notification bell and its dropdown.
 *
 * ---------------------------------------------------------------------------
 * ACCESSIBILITY
 * ---------------------------------------------------------------------------
 * The count is in the button's accessible name ("Notifications, 3 unread"), not
 * only in the badge, because a red dot conveys nothing to a screen reader and
 * nothing to a colour-blind user either.
 *
 * The panel is a labelled dialog. Escape closes it and returns focus to the
 * trigger, a click outside dismisses it, and the list is a real <ul> so row count
 * is announced. Each row is a button rather than a div with onClick, so it is
 * reachable by keyboard without extra handlers.
 *
 * ---------------------------------------------------------------------------
 * WHY ROWS ARE NOT LINKS
 * ---------------------------------------------------------------------------
 * Tempting, since each carries a url. But clicking must also mark the notification
 * read, and an <a> that fires an async side effect before navigating either races
 * the navigation or needs preventDefault — at which point it is a button wearing a
 * link costume. A button that marks read then navigates is honest about what it does.
 */
export default function NotificationBell({ enabled }: { enabled: boolean }) {
  const navigate = useNavigate();
  const { unreadCount, items, loading, loaded, loadItems, markRead, markAllRead } =
    useNotifications(enabled);

  const [open, setOpen] = useState(false);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);

  // Fetch rows only when the panel is opened — see the note in useNotifications
  // about why the list and the badge are separate requests.
  useEffect(() => {
    if (open) loadItems();
  }, [open, loadItems]);

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

  if (!enabled) return null;

  const openNotification = async (id: string, url: string | null, isRead: boolean) => {
    if (!isRead) {
      // Not awaited: the mark-read is optimistic locally, and making the user wait
      // for a round trip before navigating would be a pointless delay.
      markRead(id);
    }

    setOpen(false);

    if (url) navigate(url);
  };

  // Capped for display. The badge is a signal, not a metric, and "99+" is as
  // actionable as "231" while staying inside the pill.
  const badge = unreadCount > 99 ? '99+' : String(unreadCount);

  return (
    <div className="mp-bell">
      <button
        ref={triggerRef}
        type="button"
        className="mp-bell__trigger"
        aria-expanded={open}
        aria-haspopup="dialog"
        aria-controls="notification-panel"
        aria-label={
          unreadCount > 0
            ? `Notifications, ${unreadCount} unread`
            : 'Notifications, none unread'
        }
        onClick={() => setOpen((value) => !value)}
      >
        <Icon name="inbox" size={20} />
        {unreadCount > 0 && (
          <span className="mp-bell__badge" aria-hidden="true">
            {badge}
          </span>
        )}
      </button>

      {open && (
        <div
          ref={panelRef}
          id="notification-panel"
          className="mp-bell__panel"
          role="dialog"
          aria-label="Notifications"
        >
          <div className="mp-bell__head">
            <strong>Notifications</strong>
            {unreadCount > 0 && (
              <button type="button" className="mp-bell__action" onClick={() => markAllRead()}>
                Mark all read
              </button>
            )}
          </div>

          {loading && !loaded ? (
            <div className="mp-bell__body" role="status" aria-live="polite">
              <span className="mp-sr-only">Loading notifications…</span>
              <div className="mp-skeleton mp-skeleton--text mb-2" />
              <div className="mp-skeleton mp-skeleton--text mb-2" style={{ width: '80%' }} />
              <div className="mp-skeleton mp-skeleton--text" style={{ width: '60%' }} />
            </div>
          ) : items.length === 0 ? (
            /*
             * The empty state is phrased as reassurance rather than absence. "No
             * notifications" reads as though something is missing; explaining what
             * will appear here tells a new user the feature works and is simply quiet.
             */
            <div className="mp-bell__empty">
              <Icon name="inbox" size={28} />
              <p className="mb-0">
                You are up to date. Quotes, order updates and payment confirmations will appear here.
              </p>
            </div>
          ) : (
            <ul className="mp-bell__list">
              {items.map((item) => {
                const a = item.attributes;

                return (
                  <li key={item.id}>
                    <button
                      type="button"
                      className={`mp-bell__item${a.is_read ? '' : ' is-unread'}`}
                      onClick={() => openNotification(item.id, a.url, a.is_read)}
                    >
                      <span className={`mp-bell__icon mp-bell__icon--${a.importance}`}>
                        <Icon name={a.icon as IconName} size={16} />
                      </span>

                      <span className="mp-bell__text">
                        <span className="mp-bell__title">
                          {a.title}
                          {!a.is_read && <span className="mp-sr-only"> (unread)</span>}
                        </span>
                        <span className="mp-bell__body-text">{a.body}</span>
                        {/* title carries the exact timestamp for anyone who needs it. */}
                        <time
                          className="mp-bell__time"
                          dateTime={a.created_at ?? undefined}
                          title={a.created_at ? new Date(a.created_at).toLocaleString('en-IN') : undefined}
                        >
                          {relativeTime(a.created_at)}
                        </time>
                      </span>
                    </button>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
