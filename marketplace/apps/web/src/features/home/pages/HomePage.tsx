import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../../lib/api/client';
import type { ApiBusiness, ApiCategory, ApiListing, PaginatedResponse, SimpleListResponse } from '../../../types/api';
import SearchBar from '../../listings/components/SearchBar';
import ListingCard from '../../listings/components/ListingCard';
import SupplierCard from '../../suppliers/components/SupplierCard';
import LoadingState from '../../../components/ui/LoadingState';
import Icon, { type IconName } from '../../../components/ui/Icon';
import { useAuth } from '../../auth/AuthContext';
import { BRAND, categoryIcon } from '../../../config/brand';
import { useScrollReveal } from '../../../lib/useScrollReveal';
import { useSeo } from '../../../lib/useSeo';

/**
 * The landing page.
 *
 * Four rules shaped it:
 *
 *  1. **Lead with the flow, not the inventory.** This marketplace's product is
 *     "describe what you need and receive priced quotes". A listing grid is the
 *     secondary surface, so it appears low on the page.
 *  2. **Nothing industry-specific is hardcoded.** Industries, cities and counts all
 *     come from the API. Seeding a new industry makes it appear here unaided.
 *  3. **An empty marketplace says so.** No invented review counts, no stock logos,
 *     no "10,000+ businesses". Sections with no data hide or explain themselves,
 *     because a fake number is a promise already broken.
 *  4. **Vary the section shape.** Eight consecutive three-column card rows is what
 *     makes a landing page read as a template regardless of how good the palette
 *     is. The hero is split, industries are a dense pill grid, the process is a
 *     numbered rail, trust is a two-column list, cities are chips.
 */
interface PopularCity {
  city: string;
  listings_count: number;
}

const STEPS = [
  {
    title: 'Describe what you need',
    body: 'One form: category, quantity, budget, location, deadline. No phone calls, no chasing.',
  },
  {
    title: 'Get matched automatically',
    body: 'Suppliers are scored on capability, location, budget fit and track record, then invited for you.',
  },
  {
    title: 'Compare real quotes',
    body: 'Itemised, priced, side by side. Counter-offer until the numbers work, with every revision kept.',
  },
  {
    title: 'Order, pay and review',
    body: 'Accept a quote and the order is created. Track fulfilment, settle payment, then rate the supplier.',
  },
];

const TRUST: { icon: IconName; title: string; body: string }[] = [
  {
    icon: 'shield',
    title: 'Document-backed verification',
    body: 'Verified suppliers submitted registration documents that a human reviewed. The badge means evidence, not a checkbox.',
  },
  {
    icon: 'calculator',
    title: 'Every figure is server-side',
    body: 'Quote totals, taxes and balances are computed by the platform, so a price cannot be quietly edited in a browser.',
  },
  {
    icon: 'star',
    title: 'Reviews that were earned',
    body: 'A review can only be written by the buyer on a completed order, once. Review stuffing is not possible.',
  },
  {
    icon: 'scale',
    title: 'Disputes with a referee',
    body: 'If something goes wrong, either side can escalate. Neither side can decide the outcome of their own dispute.',
  },
  {
    icon: 'ledger',
    title: 'An audited paper trail',
    body: 'Negotiation history, order status changes and staff decisions are append-only. Nothing can be rewritten later.',
  },
  {
    icon: 'map',
    title: 'Pan-India, every industry',
    body: 'No fixed city list and no favoured sector. Construction, IT, logistics, manufacturing and services are all first-class.',
  },
];

export default function HomePage() {
  // The one page whose title is the bare site name. useSeo skips the suffix so the
  // tab never reads "Trademint - Trademint".
  useSeo({
    title: 'Trademint',
    description:
      'Post what your business needs and get real quotes from verified suppliers. Compare offers, negotiate, order and pay in one place. Every industry, pan-India.',
    canonical: '/',
  });

  const { isAuthenticated, isOwner, isCustomer } = useAuth();

  const [industries, setIndustries] = useState<ApiCategory[]>([]);
  const [suppliers, setSuppliers] = useState<ApiBusiness[]>([]);
  const [supplierTotal, setSupplierTotal] = useState(0);
  const [listings, setListings] = useState<ApiListing[]>([]);
  const [cities, setCities] = useState<PopularCity[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;

    const load = async () => {
      // Settled rather than all-or-nothing: one slow endpoint must not blank the
      // whole page. Each section renders from whatever arrived.
      const [industryRes, supplierRes, listingRes, cityRes] = await Promise.allSettled([
        api.get<SimpleListResponse<ApiCategory>>('/categories', {
          params: { 'filter[top_level]': 1, with_counts: 1 },
        }),
        api.get<PaginatedResponse<ApiBusiness>>('/businesses', { params: { per_page: 6 } }),
        api.get<PaginatedResponse<ApiListing>>('/listings', { params: { sort: '-created_at', per_page: 4 } }),
        api.get<SimpleListResponse<PopularCity>>('/locations/popular'),
      ]);

      if (cancelled) return;

      if (industryRes.status === 'fulfilled') {
        // Busiest industries first, so the grid reflects the real marketplace
        // rather than seed order.
        setIndustries(
          [...industryRes.value.data.data].sort(
            (a, b) =>
              (b.attributes.businesses_count ?? 0) + (b.attributes.listings_count ?? 0) -
              ((a.attributes.businesses_count ?? 0) + (a.attributes.listings_count ?? 0))
          )
        );
      }
      if (supplierRes.status === 'fulfilled') {
        setSuppliers(supplierRes.value.data.data);
        setSupplierTotal(supplierRes.value.data.meta.total);
      }
      if (listingRes.status === 'fulfilled') setListings(listingRes.value.data.data);
      if (cityRes.status === 'fulfilled') setCities(cityRes.value.data.data);

      setLoading(false);
    };

    load();

    return () => {
      cancelled = true;
    };
  }, []);

  // Re-arm reveals once the data has landed and the real DOM exists.
  useScrollReveal(loading);

  const verifiedCount = suppliers.filter((s) => s.attributes.verification_status === 'verified').length;
  const industryCount = industries.length;
  const cityCount = cities.length;

  const primaryCta = isCustomer
    ? { to: '/requirements/new', label: 'Post a requirement' }
    : isOwner
      ? { to: '/dashboard/matched-requirements', label: 'See work matched to you' }
      : { to: '/register', label: 'Post a requirement, free' };

  return (
    <div>
      {/* ------------------------------------------------------------ Hero
          Left-aligned rather than centred. A centred hero with a centred
          sub-head and centred buttons is the most template-like arrangement
          available; an asymmetric split reads as a product. */}
      <section className="mp-hero mp-hero--split">
        <div className="mp-container position-relative">
          <div className="row align-items-center g-5">
            <div className="col-lg-7">
              <span className="mp-hero__eyebrow">
                <Icon name="spark" /> {BRAND.tagline}
              </span>
              <h1 className="mt-4 mb-3">
                Tell us what your business needs.
                <br />
                <em>Get real quotes back.</em>
              </h1>
              <p className="lead mb-4">{BRAND.promise}</p>

              <div className="d-flex gap-2 flex-wrap mb-4">
                <Link to={primaryCta.to} className="btn btn-mp-accent btn-lg">
                  {primaryCta.label}
                </Link>
                <Link to="/suppliers" className="btn btn-mp-light btn-lg">
                  Browse suppliers
                </Link>
              </div>

              <div className="mp-trust-strip">
                <span>
                  <Icon name="check" /> No commission on quotes
                </span>
                <span>
                  <Icon name="check" /> Free for buyers
                </span>
                <span>
                  <Icon name="lock" /> Verified suppliers
                </span>
              </div>
            </div>

            {/* Counts, not claims. Every one is a real total from the API, and each
                falls back to a neutral label rather than a zero — "0 suppliers" on a
                landing page is worse than saying nothing. */}
            <div className="col-lg-5">
              <div className="mp-hero__panel">
                <div className="mp-hero__panel-head">Live on the platform</div>
                <dl className="mp-hero__figures">
                  <div>
                    <dt>Industries covered</dt>
                    <dd>{industryCount || 'Every sector'}</dd>
                  </div>
                  <div>
                    <dt>Registered suppliers</dt>
                    <dd>{supplierTotal || 'Growing'}</dd>
                  </div>
                  <div>
                    <dt>Cities with activity</dt>
                    <dd>{cityCount || 'Pan-India'}</dd>
                  </div>
                  <div>
                    <dt>Commission on quotes</dt>
                    <dd>0%</dd>
                  </div>
                </dl>
              </div>
            </div>
          </div>

          <div className="row mt-5">
            <div className="col-lg-10">
              <SearchBar target="/suppliers" ctaLabel="Find suppliers" />
              {industries.length > 0 && (
                <div className="mp-search-suggestions">
                  <span className="mp-text-micro" style={{ color: 'rgba(255,255,255,0.6)' }}>
                    Popular:
                  </span>
                  {industries.slice(0, 5).map((industry) => (
                    <Link key={industry.id} to={`/suppliers?category=${industry.attributes.slug}`}>
                      {industry.attributes.name}
                    </Link>
                  ))}
                </div>
              )}
            </div>
          </div>
        </div>
      </section>

      {/* ------------------------------------------------------ Industries
          A dense pill grid, deliberately not cards. Twelve cards with padding and
          shadows would occupy three screens to convey twelve words. */}
      <section className="mp-section--tight" style={{ paddingTop: 'var(--mp-space-7)' }}>
        <div className="mp-container">
          <div className="mp-section-heading mp-reveal">
            <div>
              <span className="mp-eyebrow">Browse by industry</span>
              <h2>Whatever your business runs on</h2>
              <p>Every industry is a first-class citizen here. The platform has no favoured sector.</p>
            </div>
            <Link to="/suppliers" className="btn btn-mp-outline btn-sm">
              All suppliers <Icon name="arrow-right" />
            </Link>
          </div>

          {loading ? (
            <LoadingState rows={4} />
          ) : industries.length === 0 ? (
            <p className="text-muted">Categories are still being set up.</p>
          ) : (
            <div className="row g-3">
              {industries.slice(0, 12).map((industry) => {
                const a = industry.attributes;
                const suppliersHere = a.businesses_count ?? 0;

                return (
                  <div key={industry.id} className="col-6 col-md-4 col-lg-2 mp-reveal">
                    <Link to={`/suppliers?category=${a.slug}`} className="mp-category-pill text-decoration-none">
                      <span className="mp-category-pill__icon" aria-hidden="true">
                        {categoryIcon(a.slug)}
                      </span>
                      {a.name}
                      <span className="mp-category-pill__meta">
                        {suppliersHere > 0
                          ? `${suppliersHere} supplier${suppliersHere === 1 ? '' : 's'}`
                          : `${a.children_count ?? 0} specialities`}
                      </span>
                    </Link>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      </section>

      {/* ----------------------------------------------------- How it works
          A numbered rail, not four cards. The steps are sequential, and a card
          grid says "four independent things" where a rail says "in this order". */}
      <section className="mp-section mp-section--muted" id="how-it-works">
        <div className="mp-container">
          <div className="row g-5">
            <div className="col-lg-4">
              {/* Sticky on desktop so the heading stays with the steps while they
                  scroll — the pattern that makes a long list feel navigable. */}
              <div className="mp-sticky-aside">
                <span className="mp-eyebrow">How it works</span>
                <h2 className="mt-2">From a requirement to a paid order</h2>
                <p className="mp-text-lead mt-3">
                  The whole transaction lives in one place, so nothing gets lost in a WhatsApp thread.
                </p>
                <Link to={primaryCta.to} className="btn btn-mp-primary mt-3">
                  {primaryCta.label}
                </Link>
              </div>
            </div>

            <div className="col-lg-8">
              <ol className="mp-rail">
                {STEPS.map((step) => (
                  <li className="mp-rail__item mp-reveal" key={step.title}>
                    <h3>{step.title}</h3>
                    <p>{step.body}</p>
                  </li>
                ))}
              </ol>
            </div>
          </div>
        </div>
      </section>

      {/* -------------------------------------------------------- Suppliers */}
      <section className="mp-section">
        <div className="mp-container">
          <div className="mp-section-heading mp-reveal">
            <div>
              <span className="mp-eyebrow">On the platform</span>
              <h2>Suppliers ready to quote</h2>
              <p>
                {verifiedCount > 0
                  ? 'Verified businesses submitted documents a human reviewed. Newer ones are unproven, not unwelcome.'
                  : 'Ratings and verification build up from real completed orders, so they take a little time to appear.'}
              </p>
            </div>
            <Link to="/suppliers" className="btn btn-mp-outline btn-sm">
              View all <Icon name="arrow-right" />
            </Link>
          </div>

          {loading ? (
            <LoadingState variant="list" rows={3} />
          ) : suppliers.length === 0 ? (
            <div className="mp-card p-5 text-center mp-reveal">
              <Icon name="building" size={40} className="text-muted" />
              <h3 className="h5 mt-3">No suppliers have registered yet</h3>
              <p className="text-muted mb-4">Be the first business on the marketplace. Listing is free.</p>
              <Link to="/register/owner" className="btn btn-mp-primary">
                List your business
              </Link>
            </div>
          ) : (
            <div className="row g-3">
              {suppliers.map((business) => (
                <div key={business.id} className="col-12 col-md-6 col-lg-4 mp-reveal">
                  <SupplierCard business={business} />
                </div>
              ))}
            </div>
          )}
        </div>
      </section>

      {/* ------------------------------------------------------------ Trust
          A two-column list rather than six boxes. These are claims to be read,
          not products to be compared, and a bordered card around each one adds
          five lines of chrome per sentence. */}
      <section className="mp-section mp-section--sunken" id="trust">
        <div className="mp-container">
          <div className="mp-section-heading mp-section-heading--center mp-reveal">
            <span className="mp-eyebrow">Trust and safety</span>
            <h2>Built so you can send money through it</h2>
            <p>Marketplaces fail on trust, not features. These are the guarantees the platform enforces itself.</p>
          </div>

          <div className="mp-trust-grid">
            {TRUST.map((item) => (
              <div className="mp-trust-item mp-reveal" key={item.title}>
                <span className="mp-trust-item__icon">
                  <Icon name={item.icon} size={20} />
                </span>
                <div>
                  <h3>{item.title}</h3>
                  <p>{item.body}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ----------------------------------------------------------- Cities */}
      {cities.length > 0 && (
        <section className="mp-section--tight">
          <div className="mp-container">
            <div className="mp-section-heading mp-reveal">
              <div>
                <span className="mp-eyebrow">Where the work is</span>
                <h2>Active cities</h2>
              </div>
            </div>
            <div className="d-flex flex-wrap gap-2 mp-reveal">
              {cities.slice(0, 14).map((entry) => (
                <Link key={entry.city} to={`/suppliers?city=${encodeURIComponent(entry.city)}`} className="mp-chip">
                  {entry.city}
                  {entry.listings_count > 0 && <span className="mp-chip__count">{entry.listings_count}</span>}
                </Link>
              ))}
            </div>
          </div>
        </section>
      )}

      {/* --------------------------------------------------------- Listings
          Only rendered when there is something to show. An empty "Latest
          listings" heading over a "no listings yet" line was the single most
          deflating thing on the old page. */}
      {listings.length > 0 && (
        <section className="mp-section--tight">
          <div className="mp-container">
            <div className="mp-section-heading mp-reveal">
              <div>
                <span className="mp-eyebrow">Ready to buy now</span>
                <h2>Latest listings</h2>
                <p>Products and services already priced and published. Skip the quote and enquire directly.</p>
              </div>
              <Link to="/browse" className="btn btn-mp-outline btn-sm">
                Browse catalogue <Icon name="arrow-right" />
              </Link>
            </div>
            <div className="row g-4">
              {listings.map((listing) => (
                <div key={listing.id} className="col-sm-6 col-lg-3 mp-reveal">
                  <ListingCard listing={listing} />
                </div>
              ))}
            </div>
          </div>
        </section>
      )}

      {/* -------------------------------------------------------- Split CTA */}
      <section className="mp-section">
        <div className="mp-container">
          <div className="mp-cta-split mp-reveal">
            <div className="mp-cta-split__panel mp-cta-split__panel--customer">
              <span className="mp-eyebrow">For buyers</span>
              <h2 className="mt-2">Stop chasing five suppliers for one price</h2>
              <p>Describe the need once. Compare what comes back.</p>
              <ul className="mp-checklist">
                <li>Free to post, no commission on quotes</li>
                <li>Matched suppliers come to you</li>
                <li>Itemised quotes, compared side by side</li>
              </ul>
              <Link to={isCustomer ? '/requirements/new' : '/register'} className="btn btn-mp-accent">
                {isCustomer ? 'Post a requirement' : 'Sign up as a buyer'}
              </Link>
            </div>

            <div className="mp-cta-split__panel mp-cta-split__panel--business">
              <span className="mp-eyebrow">For businesses</span>
              <h2 className="mt-2">Win work without buying leads</h2>
              <p>Get invited to requirements that actually fit what you do.</p>
              <ul className="mp-checklist">
                <li>Matched on capability, location and track record</li>
                <li>Quote, negotiate and invoice in one place</li>
                <li>Build a verified public track record</li>
              </ul>
              <Link to={isOwner ? '/dashboard/matched-requirements' : '/register/owner'} className="btn btn-mp-primary">
                {isOwner ? 'See matched work' : 'List your business, free'}
              </Link>
            </div>
          </div>

          {!isAuthenticated && (
            <p className="text-center text-muted mp-text-sm mt-4 mb-0">
              Already have an account? <Link to="/login">Sign in</Link>
            </p>
          )}
        </div>
      </section>
    </div>
  );
}
