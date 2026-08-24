import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from '../../lib/api/client';

export interface ApiNotification {
  type: 'notification';
  id: string;
  attributes: {
    notification_type: string;
    importance: 'action' | 'alert' | 'info';
    icon: string;
    title: string;
    body: string;
    url: string | null;
    entity_type: string | null;
    entity_id: string | null;
    meta: Record<string, unknown>;
    is_read: boolean;
    read_at: string | null;
    created_at: string | null;
  };
}

/**
 * How often the unread badge re-checks, in milliseconds.
 *
 * 60s is a deliberate compromise. Notifications here are not chat messages — a
 * quote arriving a minute late costs nothing — and the alternative costs a lot:
 * at 5s, a user with three tabs open generates over two thousand requests an hour
 * for a number that changes a handful of times a day.
 *
 * Websockets would remove the polling entirely and are the right long-term answer,
 * but they need a broadcast driver and a persistent connection tier. This works
 * today with no new infrastructure.
 */
const POLL_INTERVAL_MS = 60_000;

/**
 * Notification state for the navbar bell.
 *
 * ---------------------------------------------------------------------------
 * WHY THE COUNT AND THE LIST ARE FETCHED SEPARATELY
 * ---------------------------------------------------------------------------
 * The badge needs one integer and needs it repeatedly. The list needs twenty rows
 * and needs them once, when the user opens the dropdown.
 *
 * Polling the list to derive the count would mean serialising twenty
 * notifications every minute to render a number, for every open tab. The backend
 * exposes a dedicated `unread-count` endpoint that is a single indexed COUNT, and
 * this hook only fetches rows when someone actually looks.
 */
export function useNotifications(enabled: boolean) {
  const [unreadCount, setUnreadCount] = useState(0);
  const [items, setItems] = useState<ApiNotification[]>([]);
  const [loading, setLoading] = useState(false);
  const [loaded, setLoaded] = useState(false);

  // Guards against a slow response landing after the component unmounted, or after
  // the user signed out — both would otherwise set state on a dead component.
  const alive = useRef(true);

  useEffect(() => {
    alive.current = true;

    return () => {
      alive.current = false;
    };
  }, []);

  const refreshCount = useCallback(async () => {
    if (!enabled) return;

    try {
      const response = await api.get('/notifications/unread-count');

      if (alive.current) {
        setUnreadCount(Number(response.data?.data?.unread_count ?? 0));
      }
    } catch {
      /*
       * Silent. A failed badge poll is not worth a visible error: the bell simply
       * shows its last known count. Surfacing "could not load notifications" on a
       * transient network blip would be more alarming than the missing information.
       */
    }
  }, [enabled]);

  const loadItems = useCallback(async () => {
    if (!enabled) return;

    setLoading(true);

    try {
      const response = await api.get('/notifications', { params: { per_page: 15 } });

      if (alive.current) {
        setItems(response.data?.data ?? []);
        setUnreadCount(Number(response.data?.meta?.unread_count ?? 0));
        setLoaded(true);
      }
    } catch {
      if (alive.current) setLoaded(true);
    } finally {
      if (alive.current) setLoading(false);
    }
  }, [enabled]);

  const markRead = useCallback(
    async (id: string) => {
      /*
       * Optimistic. The row is marked read locally before the request resolves,
       * because the user is usually navigating away in the same gesture and would
       * otherwise watch the item stay bold for a round trip.
       *
       * No rollback on failure: the worst case is a notification that reappears as
       * unread on the next poll, which is self-correcting and strictly better than
       * an error toast for something the user did not ask to be told about.
       */
      setItems((current) =>
        current.map((item) =>
          item.id === id ? { ...item, attributes: { ...item.attributes, is_read: true } } : item,
        ),
      );
      setUnreadCount((count) => Math.max(0, count - 1));

      try {
        await api.post(`/notifications/${id}/read`);
      } catch {
        /* See above — self-corrects on the next poll. */
      }
    },
    [],
  );

  const markAllRead = useCallback(async () => {
    setItems((current) =>
      current.map((item) => ({ ...item, attributes: { ...item.attributes, is_read: true } })),
    );
    setUnreadCount(0);

    try {
      await api.post('/notifications/read-all');
    } catch {
      // Re-sync rather than guess, so the badge cannot be left wrong.
      refreshCount();
    }
  }, [refreshCount]);

  const remove = useCallback(async (id: string) => {
    setItems((current) => current.filter((item) => item.id !== id));

    try {
      await api.delete(`/notifications/${id}`);
    } catch {
      /* Reappears on next open if it failed. */
    }
  }, []);

  /*
   * Poll, but only while the tab is visible.
   *
   * A backgrounded tab left open overnight would otherwise keep requesting for
   * hours with nobody looking at the result. Pausing on hide and refreshing
   * immediately on show also means returning to a stale tab shows a current badge
   * straight away rather than after up to a minute.
   */
  useEffect(() => {
    if (!enabled) {
      setUnreadCount(0);
      setItems([]);
      setLoaded(false);

      return;
    }

    refreshCount();

    let timer: number | undefined;

    const start = () => {
      stop();
      timer = window.setInterval(refreshCount, POLL_INTERVAL_MS);
    };

    const stop = () => {
      if (timer !== undefined) {
        window.clearInterval(timer);
        timer = undefined;
      }
    };

    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible') {
        refreshCount();
        start();
      } else {
        stop();
      }
    };

    if (document.visibilityState === 'visible') start();

    document.addEventListener('visibilitychange', onVisibilityChange);

    return () => {
      stop();
      document.removeEventListener('visibilitychange', onVisibilityChange);
    };
  }, [enabled, refreshCount]);

  return { unreadCount, items, loading, loaded, loadItems, markRead, markAllRead, remove, refreshCount };
}

/**
 * Compact relative time: "just now", "4m", "3h", "2d", then a date.
 *
 * Hand-rolled rather than pulling in a date library for one function. Notification
 * timestamps only ever need coarse granularity — nobody cares whether a quote
 * arrived 3 or 4 minutes ago — and the exact time is available in the title
 * attribute for anyone who does.
 */
export function relativeTime(iso: string | null): string {
  if (!iso) return '';

  const then = new Date(iso).getTime();

  if (Number.isNaN(then)) return '';

  const seconds = Math.max(0, Math.floor((Date.now() - then) / 1000));

  if (seconds < 45) return 'just now';
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86_400) return `${Math.floor(seconds / 3600)}h ago`;
  if (seconds < 604_800) return `${Math.floor(seconds / 86_400)}d ago`;

  return new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
}
