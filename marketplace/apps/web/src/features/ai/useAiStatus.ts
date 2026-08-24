import { useCallback, useEffect, useState } from 'react';
import { api } from '../../lib/api/client';
import type { AiStatus } from '../../types/ai';
import { useAuth } from '../auth/AuthContext';

/**
 * Is AI usable right now, and for which features?
 *
 * The contract this hook exists to keep: **an AI outage must be invisible.** It never
 * throws and never surfaces an error state — a failed status call resolves to "not
 * available", the caller renders nothing AI-related, and the user sees an ordinary
 * marketplace rather than a broken button or an error toast about a feature they did not
 * ask for.
 *
 * The endpoint is deliberately not rate-limited server-side, so polling it is safe.
 */
interface UseAiStatus {
  status: AiStatus | null;
  /** True only when AI is on, reachable, and this feature's flag is set. */
  isFeatureReady: (feature: string) => boolean;
  loading: boolean;
  refresh: () => void;
}

export function useAiStatus(): UseAiStatus {
  const { isAuthenticated } = useAuth();
  const [status, setStatus] = useState<AiStatus | null>(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(() => {
    // Every AI endpoint requires a session, so an anonymous visitor is a definite no
    // without a request.
    if (!isAuthenticated) {
      setStatus(null);
      setLoading(false);

      return;
    }

    api
      .get<{ data: AiStatus }>('/ai/status')
      .then((res) => setStatus(res.data.data))
      // Swallowed on purpose: the absence of AI is a normal state, not an error worth
      // showing anyone.
      .catch(() => setStatus(null))
      .finally(() => setLoading(false));
  }, [isAuthenticated]);

  useEffect(load, [load]);

  const isFeatureReady = useCallback(
    (feature: string): boolean =>
      status !== null && status.enabled && status.available && status.features[feature] === true,
    [status]
  );

  return { status, isFeatureReady, loading, refresh: load };
}
