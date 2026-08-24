import type { ApiListing } from '../types/api';

/**
 * Canonical public paths.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS CENTRALISED
 * ---------------------------------------------------------------------------
 * A listing now has two valid addresses: its slug (canonical) and its uuid
 * (retained so older links keep resolving). Every place that builds a link, a
 * canonical tag, an Open Graph url or a JSON-LD `@id` has to agree on which one is
 * canonical — a canonical tag pointing at a different URL from the sitemap entry is
 * actively worse for ranking than having neither, because it splits the signal.
 *
 * Six components built listing links inline from `listing.id` before this existed.
 * One function means adding a slug did not require finding all six, and the next
 * URL change will not either.
 */

/**
 * `/listings/{slug}`, falling back to the uuid.
 *
 * The fallback is not dead code: `slug` is nullable because a listing can be
 * created as a draft with no title to slugify, and a draft is still viewable by its
 * owner from the dashboard.
 */
export function listingPath(listing: ApiListing): string {
  return `/listings/${listing.attributes.slug || listing.id}`;
}

export function businessPath(slug: string): string {
  return `/business/${slug}`;
}

export function categoryPath(slug: string): string {
  return `/suppliers?category=${encodeURIComponent(slug)}`;
}

export function cityPath(city: string): string {
  return `/suppliers?city=${encodeURIComponent(city)}`;
}

/**
 * Absolute URL for a path, for canonical tags and Open Graph.
 *
 * Built from `window.location.origin` rather than a configured constant so the same
 * bundle is correct on localhost, a preview deployment and production. A hardcoded
 * origin is how a staging build ends up emitting canonical tags that point at
 * production and quietly de-indexes itself.
 */
export function absoluteUrl(path: string): string {
  if (typeof window === 'undefined') {
    return path;
  }

  return new URL(path, window.location.origin).toString();
}
