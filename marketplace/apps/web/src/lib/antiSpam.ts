import { api } from './api/client';

/**
 * Client half of the anti-spam layer.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS IS AND IS NOT
 * ---------------------------------------------------------------------------
 * None of this is a security control. Every check it feeds is enforced on the
 * server, and anything enforced only here is enforced only for people who are
 * not attacking us. This module exists to supply the *inputs* the server-side
 * checks need — a honeypot field to observe, and a signed timestamp to measure
 * form age against — plus immediate feedback so a legitimate user is told about
 * a problem before they submit.
 *
 * Field names mirror config/antispam.php. If one changes there, it changes here.
 */

/**
 * Deliberately plausible names. A field called `honeypot` is one a bot author
 * skips after reading the markup once; `contact_reference` looks like a field a
 * B2B marketplace would genuinely have.
 */
export const HONEYPOT_FIELD = 'contact_reference';
export const FORM_TOKEN_FIELD = 'form_loaded_at';

/**
 * The signed form token, cached for the tab's lifetime.
 *
 * The server signs it, because a timestamp the client chooses is a timestamp an
 * attacker chooses, which would make the minimum-submission-time check pure
 * decoration.
 *
 * Cached rather than fetched per form: the token only needs to establish roughly
 * when this session started interacting, and one request per page load is enough.
 * A stale token is re-fetched on demand when it passes the server's window.
 */
let cachedToken: string | null = null;
let inFlight: Promise<string | null> | null = null;

export async function getFormToken(): Promise<string | null> {
  if (cachedToken) {
    return cachedToken;
  }

  // Collapse concurrent callers onto one request — several forms mounting at once
  // should not each fetch a token.
  if (inFlight) {
    return inFlight;
  }

  inFlight = api
    .get('/form-token')
    .then((response) => {
      cachedToken = (response.data?.data?.token as string) ?? null;
      return cachedToken;
    })
    .catch(() => {
      /*
       * Fail open, and quietly.
       *
       * If the token endpoint is unreachable the submission still goes through:
       * the server treats a missing timestamp as "no evidence" rather than as
       * guilt, precisely so that a hiccup in an anti-spam nicety cannot block
       * real business. Blocking a genuine order because a spam heuristic could
       * not initialise would be a far worse outcome than missing one signal.
       */
      return null;
    })
    .finally(() => {
      inFlight = null;
    });

  return inFlight;
}

/** Discard the cached token, so the next form fetches a fresh one. */
export function resetFormToken(): void {
  cachedToken = null;
}

/**
 * Merge the anti-spam fields into a payload just before submission.
 *
 * The honeypot is sent as an empty string rather than omitted: the server needs
 * to see the field present and empty. Omitting it entirely is indistinguishable
 * from a client that does not implement the check at all.
 */
export async function withAntiSpamFields<T extends Record<string, unknown>>(
  payload: T,
): Promise<T & Record<string, unknown>> {
  const token = await getFormToken();

  return {
    ...payload,
    [HONEYPOT_FIELD]: '',
    ...(token ? { [FORM_TOKEN_FIELD]: token } : {}),
  };
}

/**
 * A key identifying one user intent, for the `Idempotency-Key` header.
 *
 * Generated once when a form is opened and reused across retries of that same
 * submission — that is the whole point. Generating a fresh key per attempt would
 * make every retry look like a new intent and defeat the protection.
 *
 * `crypto.randomUUID` where available; the fallback covers older Safari and any
 * non-secure context, where `crypto` may be missing entirely.
 */
export function newIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

export function idempotencyHeaders(key: string): Record<string, string> {
  return { 'Idempotency-Key': key };
}

/**
 * Client-side mirror of the server's content heuristics.
 *
 * Intentionally a *subset* — only the checks a user can act on immediately, so
 * they are told "too many links" while typing instead of after a round trip. The
 * server remains the authority and runs the full set; disagreement between the
 * two is fine in this direction, because the server is always the stricter of
 * the pair.
 */
export function findContentProblem(text: string): string | null {
  if (!text.trim()) {
    return null;
  }

  if (/<\s*(script|iframe|object|embed|style|link|meta|svg|form|input)\b/i.test(text) || /<\/?\s*[a-z][a-z0-9]*\s*(\s[^>]*)?>/i.test(text)) {
    return 'Please use plain text — HTML and script tags are not accepted.';
  }

  const links = (text.match(/\bhttps?:\/\//gi) ?? []).length;
  if (links > 4) {
    return 'Too many links. Please describe your requirement in words.';
  }

  if (/(.)\1{7,}/u.test(text)) {
    return 'Please remove the long runs of repeated characters.';
  }

  const letters = text.replace(/[^\p{L}]/gu, '');
  if (letters.length >= 40) {
    const capitals = text.replace(/[^\p{Lu}]/gu, '').length;
    if (capitals / letters.length > 0.6) {
      return 'Please use normal sentence case rather than all capitals.';
    }
  }

  return null;
}
