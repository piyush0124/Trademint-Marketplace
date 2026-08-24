import { useEffect } from 'react';

/**
 * Per-page document metadata for a client-rendered app.
 *
 * ---------------------------------------------------------------------------
 * HONEST LIMITATION, STATED UP FRONT
 * ---------------------------------------------------------------------------
 * This mutates the live document after React mounts. It gives correct titles,
 * canonical URLs and Open Graph tags to anything that executes JavaScript —
 * which includes Googlebot and the browser tab, and is why it is worth doing.
 *
 * It does *not* help crawlers that only read the initial HTML response, and
 * several social-preview scrapers fall in that category. Those see the static
 * defaults in index.html. Fixing that properly needs server-side rendering or
 * prerendering, which is an architectural change well outside a redesign and is
 * recorded as a known limitation rather than papered over here.
 *
 * ---------------------------------------------------------------------------
 * WHY noIndex IS A FIRST-CLASS ARGUMENT
 * ---------------------------------------------------------------------------
 * Dashboards, orders, quotes and admin screens must never be indexed. Relying on
 * "they need a login anyway" is not sufficient: URLs leak through referrers,
 * shared links and browser sync, and an indexed URL is an invitation to probe.
 * Making the flag explicit means every private route states its intent.
 */

export interface SeoOptions {
  title: string;
  description?: string;
  /** Absolute or root-relative canonical path. Defaults to the current location. */
  canonical?: string;
  image?: string;
  /** Set on every authenticated or private view. */
  noIndex?: boolean;
  type?: 'website' | 'article' | 'profile';
}

const SITE_NAME = 'Trademint';

function upsertMeta(selector: string, attribute: 'name' | 'property', key: string, content: string): void {
  let tag = document.head.querySelector<HTMLMetaElement>(selector);

  if (!tag) {
    tag = document.createElement('meta');
    tag.setAttribute(attribute, key);
    document.head.appendChild(tag);
  }

  tag.setAttribute('content', content);
}

function upsertLink(rel: string, href: string): void {
  let tag = document.head.querySelector<HTMLLinkElement>(`link[rel="${rel}"]`);

  if (!tag) {
    tag = document.createElement('link');
    tag.setAttribute('rel', rel);
    document.head.appendChild(tag);
  }

  tag.setAttribute('href', href);
}

export function useSeo({
  title,
  description,
  canonical,
  image,
  noIndex = false,
  type = 'website',
}: SeoOptions): void {
  useEffect(() => {
    // Suffix the site name unless the page already is the site name, so a tab
    // never reads "Trademint — Trademint".
    const fullTitle = title === SITE_NAME ? title : `${title} — ${SITE_NAME}`;
    document.title = fullTitle;

    if (description) {
      upsertMeta('meta[name="description"]', 'name', 'description', description);
      upsertMeta('meta[property="og:description"]', 'property', 'og:description', description);
    }

    upsertMeta('meta[property="og:title"]', 'property', 'og:title', fullTitle);
    upsertMeta('meta[property="og:type"]', 'property', 'og:type', type);
    upsertMeta('meta[property="og:site_name"]', 'property', 'og:site_name', SITE_NAME);
    upsertMeta('meta[name="twitter:card"]', 'name', 'twitter:card', 'summary_large_image');

    if (image) {
      upsertMeta('meta[property="og:image"]', 'property', 'og:image', image);
    }

    const url = canonical
      ? new URL(canonical, window.location.origin).toString()
      : window.location.origin + window.location.pathname;

    upsertMeta('meta[property="og:url"]', 'property', 'og:url', url);

    /*
     * Private pages get `noindex, nofollow` and deliberately no canonical.
     *
     * A canonical tag on a private page is a published, durable pointer to a URL
     * that should not be discoverable at all — it works against the noindex
     * directive rather than with it.
     */
    if (noIndex) {
      upsertMeta('meta[name="robots"]', 'name', 'robots', 'noindex, nofollow');
      document.head.querySelector('link[rel="canonical"]')?.remove();
    } else {
      upsertMeta('meta[name="robots"]', 'name', 'robots', 'index, follow');
      upsertLink('canonical', url);
    }
  }, [title, description, canonical, image, noIndex, type]);
}

/** Shorthand for every authenticated view. */
export function usePrivateSeo(title: string): void {
  useSeo({ title, noIndex: true });
}
