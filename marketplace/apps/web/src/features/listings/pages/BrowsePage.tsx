import { FormEvent, useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { api } from '../../../lib/api/client';
import type { ApiCategory, ApiListing, PaginatedResponse, SimpleListResponse } from '../../../types/api';
import ListingCard from '../components/ListingCard';
import FilterPanel, { EMPTY_FILTERS, ListingFilters } from '../components/FilterPanel';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';
import ErrorState from '../../../components/ui/ErrorState';
import { useAuth } from '../../auth/AuthContext';
import { categoryIcon } from '../../../config/brand';
import { useSeo } from '../../../lib/useSeo';

/**
 * The catalogue: products and services already priced and published.
 *
 * This is the *secondary* discovery surface. Most buying on this marketplace starts
 * with a requirement, because most B2B purchases need a quote — so this page
 * consistently offers that route as the alternative when the catalogue comes up
 * short, rather than leaving a dead end.
 *
 * Nothing here is property-specific any more. The heading, filters and empty states
 * are all category-driven, and the category itself comes from the database.
 */
const SORT_OPTIONS = [
  { value: '-created_at', label: 'Newest' },
  { value: 'price', label: 'Price: low to high' },
  { value: '-price', label: 'Price: high to low' },
  { value: 'title', label: 'Name: A–Z' },
];

export default function BrowsePage() {
  useSeo({
    title: 'Browse products and services',
    description:
      'Browse listings from verified businesses across every industry. Filter by category, city, price and availability, then send an enquiry.',
    // See the note on SuppliersPage: filters are not separate canonical URLs.
    canonical: '/browse',
  });

  const { isOwner, isCustomer, isAuthenticated } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();

  const [keyword, setKeyword] = useState(searchParams.get('q') ?? '');
  const [sort, setSort] = useState(searchParams.get('sort') ?? '-created_at');
  const [filters, setFilters] = useState<ListingFilters>(() => ({
    ...EMPTY_FILTERS,
    category: searchParams.get('category') ?? '',
    city: searchParams.get('city') ?? '',
    minPrice: searchParams.get('min_price') ?? '',
    maxPrice: searchParams.get('max_price') ?? '',
    availability: searchParams.get('availability') ?? '',
    // Legacy real-estate links (?property_type=villa) keep working: they are
    // simply read as category attributes, which is what they always were.
    attributes: Object.fromEntries(
      (['property_type', 'listing_type'] as const)
        .map((key) => [key, searchParams.get(key) ?? ''])
        .filter(([, value]) => value !== '')
    ) as Record<string, string>,
  }));

  const [showMobileFilters, setShowMobileFilters] = useState(false);
  const [listings, setListings] = useState<ApiListing[]>([]);
  const [categories, setCategories] = useState<ApiCategory[]>([]);
  const [total, setTotal] = useState(0);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');

  useEffect(() => {
    api
      .get<SimpleListResponse<ApiCategory>>('/categories', { params: { 'filter[top_level]': 1, with_counts: 1 } })
      .then((res) =>
        setCategories(
          [...res.data.data].sort((a, b) => (b.attributes.listings_count ?? 0) - (a.attributes.listings_count ?? 0))
        )
      )
      .catch(() => undefined);
  }, []);

  const params = useMemo(() => {
    const p: Record<string, string> = { sort, per_page: '12' };
    if (keyword.trim()) p['filter[q]'] = keyword.trim();
    if (filters.category) p['filter[category]'] = filters.category;
    if (filters.city.trim()) p['filter[city]'] = filters.city.trim();
    if (filters.minPrice) p['filter[min_price]'] = filters.minPrice;
    if (filters.maxPrice) p['filter[max_price]'] = filters.maxPrice;
    if (filters.availability) p['filter[availability]'] = filters.availability;
    Object.entries(filters.attributes).forEach(([key, value]) => {
      if (value !== '') p[`filter[attr][${key}]`] = value;
    });

    return p;
  }, [keyword, filters, sort]);

  const fetchListings = useCallback(() => {
    setStatus('loading');
    api
      .get<PaginatedResponse<ApiListing>>('/listings', { params })
      .then((res) => {
        setListings(res.data.data);
        setTotal(res.data.meta.total);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  }, [params]);

  useEffect(() => {
    fetchListings();

    const next = new URLSearchParams();
    if (keyword.trim()) next.set('q', keyword.trim());
    if (filters.category) next.set('category', filters.category);
    if (filters.city.trim()) next.set('city', filters.city.trim());
    if (filters.minPrice) next.set('min_price', filters.minPrice);
    if (filters.maxPrice) next.set('max_price', filters.maxPrice);
    if (filters.availability) next.set('availability', filters.availability);
    if (sort !== '-created_at') next.set('sort', sort);
    Object.entries(filters.attributes).forEach(([key, value]) => {
      if (value !== '') next.set(key, value);
    });
    setSearchParams(next, { replace: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params]);

  const activeCategory = categories.find((c) => c.attributes.slug === filters.category);
  const hasFilters =
    Boolean(keyword || filters.city || filters.category || filters.minPrice || filters.maxPrice || filters.availability) ||
    Object.keys(filters.attributes).length > 0;

  const clearFilters = () => {
    setKeyword('');
    setFilters(EMPTY_FILTERS);
  };

  const heading = activeCategory
    ? activeCategory.attributes.name
    : filters.category
      ? 'Catalogue'
      : 'Products & services';

  return (
    <div className="mp-container py-4 py-lg-5">
      <div className="d-flex align-items-end justify-content-between flex-wrap gap-3 mb-4">
        <div>
          <span className="mp-eyebrow">Catalogue</span>
          <h1 className="mt-1 mb-1" style={{ fontSize: 'var(--mp-text-h1)' }}>
            {heading}
            {filters.city && ` in ${filters.city}`}
          </h1>
          <p className="text-muted mb-0">
            {status === 'ready'
              ? `${total} published ${total === 1 ? 'listing' : 'listings'} you can enquire about right away`
              : 'Loading published listings…'}
          </p>
        </div>

        <div className="d-flex gap-2 align-items-center">
          <button type="button" className="btn btn-mp-outline d-lg-none" onClick={() => setShowMobileFilters(true)}>
            Filters
          </button>
          <label className="visually-hidden" htmlFor="listing-sort">Sort</label>
          <select
            id="listing-sort"
            className="form-select"
            style={{ width: 210 }}
            value={sort}
            onChange={(event) => setSort(event.target.value)}
          >
            {SORT_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>Sort: {option.label}</option>
            ))}
          </select>
        </div>
      </div>

      {/* Category rail. Horizontal scroll on mobile beats a 90-item select. */}
      {categories.length > 0 && (
        <div className="d-flex gap-2 mb-4 pb-2" style={{ overflowX: 'auto' }}>
          <button
            type="button"
            className={`mp-chip flex-shrink-0 ${filters.category === '' ? 'border-0' : ''}`}
            style={filters.category === '' ? { background: 'var(--mp-primary)', color: '#fff' } : undefined}
            onClick={() => setFilters({ ...filters, category: '', attributes: {} })}
          >
            All
          </button>
          {categories.map((category) => {
            const a = category.attributes;
            const active = filters.category === a.slug;

            return (
              <button
                key={category.id}
                type="button"
                className={`mp-chip flex-shrink-0 ${active ? 'border-0' : ''}`}
                style={active ? { background: 'var(--mp-primary)', color: '#fff' } : undefined}
                onClick={() => setFilters({ ...filters, category: a.slug, attributes: {} })}
              >
                <span aria-hidden="true">{categoryIcon(a.slug)}</span>
                {a.name}
                {(a.listings_count ?? 0) > 0 && (
                  <span className="mp-chip__count" style={active ? { color: 'rgba(255,255,255,0.75)' } : undefined}>
                    {a.listings_count}
                  </span>
                )}
              </button>
            );
          })}
        </div>
      )}

      <form className="mb-4" onSubmit={(event: FormEvent) => { event.preventDefault(); fetchListings(); }} role="search">
        <div className="input-group">
          <input
            className="form-control"
            placeholder="Search the catalogue by name or description"
            value={keyword}
            onChange={(event) => setKeyword(event.target.value)}
            aria-label="Search listings"
          />
          <button type="submit" className="btn btn-mp-primary">Search</button>
        </div>
      </form>

      <div className="row g-4">
        <div className="col-lg-3 d-none d-lg-block">
          <FilterPanel filters={filters} onChange={setFilters} onApply={fetchListings} />

          {/* The alternative route, offered where it is useful rather than only
              after a failed search. */}
          {(!isAuthenticated || isCustomer) && (
            <div className="mp-card p-4 mt-3" style={{ background: 'var(--mp-primary-soft)', borderColor: 'var(--mp-primary-light)' }}>
              <h3 className="h6">Can't find it listed?</h3>
              <p className="small text-muted">
                Most business purchases need a quote. Describe what you need and matched suppliers will price it for you.
              </p>
              <Link to={isCustomer ? '/requirements/new' : '/register'} className="btn btn-mp-accent btn-sm w-100">
                Post a requirement
              </Link>
            </div>
          )}
        </div>

        <div className="col-12 col-lg-9">
          {status === 'loading' && <LoadingState rows={9} />}
          {status === 'error' && <ErrorState onRetry={fetchListings} />}

          {status === 'ready' && listings.length === 0 && (
            <EmptyState
              icon="search"
              title={hasFilters ? 'Nothing matches those filters' : 'The catalogue is empty so far'}
              description={
                hasFilters
                  ? 'Try widening the price range or clearing the category. Or describe what you need and let suppliers quote it.'
                  : isOwner
                    ? 'No business has published a listing yet. Yours could be the first.'
                    : 'No listings have been published yet. You can still post a requirement and suppliers will quote for it.'
              }
              action={
                <div className="d-flex gap-2 justify-content-center flex-wrap">
                  {hasFilters && (
                    <button type="button" className="btn btn-mp-outline" onClick={clearFilters}>Clear filters</button>
                  )}
                  {isOwner ? (
                    <Link to="/listings/new" className="btn btn-mp-primary">Create a listing</Link>
                  ) : (
                    <Link to={isCustomer ? '/requirements/new' : '/register'} className="btn btn-mp-primary">
                      Post a requirement
                    </Link>
                  )}
                  <Link to="/suppliers" className="btn btn-mp-ghost">Browse suppliers</Link>
                </div>
              }
            />
          )}

          {status === 'ready' && listings.length > 0 && (
            <div className="row g-4">
              {listings.map((listing) => (
                <div key={listing.id} className="col-sm-6 col-xl-4">
                  <ListingCard listing={listing} />
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      {showMobileFilters && (
        <div
          className="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-end"
          style={{ background: 'var(--mp-ink-overlay)', zIndex: 60 }}
          onClick={() => setShowMobileFilters(false)}
        >
          <div
            className="bg-white w-100 p-3"
            style={{ borderTopLeftRadius: 24, borderTopRightRadius: 24, maxHeight: '88vh', overflowY: 'auto' }}
            onClick={(event) => event.stopPropagation()}
          >
            <div className="d-flex justify-content-between align-items-center mb-2">
              <h2 className="h6 mb-0">Filters</h2>
              <button type="button" className="btn-close" aria-label="Close filters" onClick={() => setShowMobileFilters(false)} />
            </div>
            <FilterPanel
              filters={filters}
              onChange={setFilters}
              onApply={() => {
                fetchListings();
                setShowMobileFilters(false);
              }}
            />
          </div>
        </div>
      )}
    </div>
  );
}
