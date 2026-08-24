import { FormEvent, useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { api } from '../../../lib/api/client';
import type { ApiBusiness, ApiCategory, PaginatedResponse, SimpleListResponse } from '../../../types/api';
import SupplierCard from '../components/SupplierCard';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';
import ErrorState from '../../../components/ui/ErrorState';
import { useAuth } from '../../auth/AuthContext';
import { useSeo } from '../../../lib/useSeo';

/**
 * Supplier discovery — the primary way to find someone on this marketplace.
 *
 * Every filter here maps to a real query parameter on `GET /businesses`, and every
 * option list is loaded from the database. There is no hardcoded industry, city or
 * business type anywhere in this file, which is the whole point: adding an industry
 * to the `categories` table makes it appear here with no frontend change.
 *
 * Discovery is not verified-only. Filtering unverified suppliers out by default
 * would make a young marketplace look empty and would starve new businesses of
 * their first order, so verification is a filter and a sort instead.
 */
const SORTS: { value: string; label: string }[] = [
  { value: '', label: 'Best match' },
  { value: 'rating', label: 'Highest rated' },
  { value: 'reviews', label: 'Most reviewed' },
  { value: 'experience', label: 'Most experienced' },
  { value: 'recent', label: 'Newest' },
];

const TRADE_TYPES: { value: string; label: string }[] = [
  { value: '', label: 'Any' },
  { value: 'b2b', label: 'B2B' },
  { value: 'b2c', label: 'B2C' },
  { value: 'both', label: 'Both' },
];

export default function SuppliersPage() {
  useSeo({
    title: 'Find verified suppliers',
    description:
      'Search verified businesses across construction, technology, manufacturing, logistics, professional services and more, in 20 Indian cities.',
    // Canonical points at the bare path, not the current query string. Every
    // filter combination is the same content in a different order, and letting
    // each one be its own canonical URL splits ranking across thousands of
    // near-duplicates.
    canonical: '/suppliers',
  });

  const { isCustomer, isAuthenticated } = useAuth();
  const [params, setParams] = useSearchParams();

  const [keyword, setKeyword] = useState(params.get('q') ?? '');
  const [city, setCity] = useState(params.get('city') ?? '');
  const [category, setCategory] = useState(params.get('category') ?? '');
  const [tradeType, setTradeType] = useState(params.get('trade_type') ?? '');
  const [verifiedOnly, setVerifiedOnly] = useState(params.get('verified') === '1');
  const [availableOnly, setAvailableOnly] = useState(params.get('available') === '1');
  const [minRating, setMinRating] = useState(params.get('min_rating') ?? '');
  const [sort, setSort] = useState(params.get('sort') ?? '');

  const [industries, setIndustries] = useState<ApiCategory[]>([]);
  const [businesses, setBusinesses] = useState<ApiBusiness[]>([]);
  const [total, setTotal] = useState(0);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [showFilters, setShowFilters] = useState(false);

  // Industry list for the category filter. Failure is tolerable — the rest of the
  // page still works, you just lose one dropdown.
  useEffect(() => {
    api
      .get<SimpleListResponse<ApiCategory>>('/categories', { params: { 'filter[top_level]': 1, with_counts: 1 } })
      .then((res) => setIndustries(res.data.data))
      .catch(() => undefined);
  }, []);

  const query = useMemo(() => {
    const q: Record<string, string | number> = { per_page: 24 };
    if (keyword.trim()) q['filter[search]'] = keyword.trim();
    if (city.trim()) q['filter[city]'] = city.trim();
    if (category) q['filter[category]'] = category;
    if (tradeType) q['filter[trade_type]'] = tradeType;
    if (verifiedOnly) q['filter[verified]'] = 1;
    if (availableOnly) q['filter[available]'] = 1;
    if (minRating) q['filter[min_rating]'] = minRating;
    if (sort) q.sort = sort;

    return q;
  }, [keyword, city, category, tradeType, verifiedOnly, availableOnly, minRating, sort]);

  const load = useCallback(() => {
    setStatus('loading');
    api
      .get<PaginatedResponse<ApiBusiness>>('/businesses', { params: query })
      .then((res) => {
        setBusinesses(res.data.data);
        setTotal(res.data.meta.total);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  }, [query]);

  // Keep the URL shareable: a filtered supplier search should survive a refresh
  // and be pasteable into a colleague's chat.
  useEffect(() => {
    load();

    const next = new URLSearchParams();
    if (keyword.trim()) next.set('q', keyword.trim());
    if (city.trim()) next.set('city', city.trim());
    if (category) next.set('category', category);
    if (tradeType) next.set('trade_type', tradeType);
    if (verifiedOnly) next.set('verified', '1');
    if (availableOnly) next.set('available', '1');
    if (minRating) next.set('min_rating', minRating);
    if (sort) next.set('sort', sort);
    setParams(next, { replace: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query]);

  const activeCategory = industries.find((item) => item.attributes.slug === category);
  const hasFilters = Boolean(keyword || city || category || tradeType || verifiedOnly || availableOnly || minRating);

  const clear = () => {
    setKeyword('');
    setCity('');
    setCategory('');
    setTradeType('');
    setVerifiedOnly(false);
    setAvailableOnly(false);
    setMinRating('');
    setSort('');
  };

  const filters = (
    <div className="mp-card p-4">
      <div className="d-flex justify-content-between align-items-center mb-3">
        <h2 className="h6 mb-0">Filters</h2>
        {hasFilters && (
          <button type="button" className="btn btn-sm btn-mp-ghost px-0" onClick={clear}>Clear</button>
        )}
      </div>

      <div className="mp-form-field mb-3">
        <label htmlFor="filter-industry">Industry</label>
        <select id="filter-industry" className="form-select" value={category} onChange={(e) => setCategory(e.target.value)}>
          <option value="">All industries</option>
          {industries.map((item) => (
            <option key={item.id} value={item.attributes.slug}>
              {item.attributes.name}
              {item.attributes.businesses_count ? ` (${item.attributes.businesses_count})` : ''}
            </option>
          ))}
        </select>
        <div className="form-text small">Includes specialities beneath the industry.</div>
      </div>

      <div className="mp-form-field mb-3">
        <label htmlFor="filter-city">City</label>
        <input
          id="filter-city"
          className="form-control"
          placeholder="Anywhere in India"
          value={city}
          onChange={(e) => setCity(e.target.value)}
        />
        <div className="form-text small">Matches a supplier's own city or a declared service area.</div>
      </div>

      <div className="mp-form-field mb-3">
        <label>Sells to</label>
        <div className="d-flex gap-1 flex-wrap">
          {TRADE_TYPES.map((option) => (
            <button
              key={option.value || 'any'}
              type="button"
              className={`btn btn-sm ${tradeType === option.value ? 'btn-mp-primary' : 'btn-mp-outline'}`}
              onClick={() => setTradeType(option.value)}
            >
              {option.label}
            </button>
          ))}
        </div>
      </div>

      <div className="mp-form-field mb-3">
        <label htmlFor="filter-rating">Minimum rating</label>
        <select id="filter-rating" className="form-select" value={minRating} onChange={(e) => setMinRating(e.target.value)}>
          <option value="">Any rating</option>
          <option value="4">4.0 and above</option>
          <option value="3">3.0 and above</option>
        </select>
        <div className="form-text small">Excludes suppliers with no reviews yet.</div>
      </div>

      <div className="form-check mb-2">
        <input
          className="form-check-input"
          type="checkbox"
          id="filter-verified"
          checked={verifiedOnly}
          onChange={(e) => setVerifiedOnly(e.target.checked)}
        />
        <label className="form-check-label small" htmlFor="filter-verified">Verified suppliers only</label>
      </div>

      <div className="form-check">
        <input
          className="form-check-input"
          type="checkbox"
          id="filter-available"
          checked={availableOnly}
          onChange={(e) => setAvailableOnly(e.target.checked)}
        />
        <label className="form-check-label small" htmlFor="filter-available">Currently taking work</label>
      </div>
    </div>
  );

  const submit = (event: FormEvent) => {
    event.preventDefault();
    load();
  };

  return (
    <div className="mp-container py-4 py-lg-5">
      <div className="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
        <div>
          <span className="mp-eyebrow">Discovery</span>
          <h1 className="mt-1 mb-1" style={{ fontSize: 'var(--mp-text-h1)' }}>
            {activeCategory ? `${activeCategory.attributes.name} suppliers` : 'Find a supplier'}
            {city && ` in ${city}`}
          </h1>
          <p className="text-muted mb-0">
            {status === 'ready'
              ? `${total} ${total === 1 ? 'business' : 'businesses'} match`
              : 'Searching across every industry and city.'}
          </p>
        </div>

        <div className="d-flex gap-2 align-items-center">
          <button type="button" className="btn btn-mp-outline d-lg-none" onClick={() => setShowFilters(true)}>
            Filters
          </button>
          <label className="visually-hidden" htmlFor="supplier-sort">Sort</label>
          <select
            id="supplier-sort"
            className="form-select"
            style={{ width: 210 }}
            value={sort}
            onChange={(e) => setSort(e.target.value)}
          >
            {SORTS.map((option) => (
              <option key={option.value} value={option.value}>Sort: {option.label}</option>
            ))}
          </select>
        </div>
      </div>

      {/* Nudge toward the flow that actually gets results: describing the need
          once beats messaging suppliers one at a time. */}
      {(!isAuthenticated || isCustomer) && (
        <div className="mp-card mp-card--interactive p-4 mb-4" style={{ background: 'var(--mp-primary-soft)', borderColor: 'var(--mp-primary-light)' }}>
          <div className="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
              <div className="fw-bold" style={{ color: 'var(--mp-ink)' }}>Not sure who to ask?</div>
              <p className="small text-muted mb-0">
                Post what you need once and matched suppliers come to you with priced quotes.
              </p>
            </div>
            <Link to={isCustomer ? '/requirements/new' : '/register'} className="btn btn-mp-accent">
              Post a requirement
            </Link>
          </div>
        </div>
      )}

      <form className="mb-4" onSubmit={submit} role="search">
        <div className="input-group">
          <input
            className="form-control"
            placeholder="Search suppliers by name or what they do"
            value={keyword}
            onChange={(e) => setKeyword(e.target.value)}
            aria-label="Search suppliers"
          />
          <button type="submit" className="btn btn-mp-primary">Search</button>
        </div>
      </form>

      <div className="row g-4">
        <div className="col-lg-3 d-none d-lg-block">{filters}</div>

        <div className="col-12 col-lg-9">
          {status === 'loading' && <LoadingState variant="list" rows={6} />}
          {status === 'error' && <ErrorState onRetry={load} />}

          {status === 'ready' && businesses.length === 0 && (
            <EmptyState
              icon="building"
              title="No suppliers match that yet."
              description={
                hasFilters
                  ? 'Try widening the location or clearing a filter. Verified-only and minimum-rating are the two that narrow results most.'
                  : 'No businesses have registered yet. Be the first — listing a business is free.'
              }
              action={
                hasFilters ? (
                  <button type="button" className="btn btn-mp-outline" onClick={clear}>Clear filters</button>
                ) : (
                  <Link to="/register/owner" className="btn btn-mp-primary">List your business</Link>
                )
              }
            />
          )}

          {status === 'ready' && businesses.length > 0 && (
            <div className="row g-3">
              {businesses.map((business) => (
                <div key={business.id} className="col-12 col-md-6">
                  <SupplierCard business={business} />
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Mobile filters as a bottom sheet: thumb-reachable, not a shrunk sidebar. */}
      {showFilters && (
        <div
          className="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-end"
          style={{ background: 'var(--mp-ink-overlay)', zIndex: 60 }}
          onClick={() => setShowFilters(false)}
        >
          <div
            className="bg-white w-100 p-3"
            style={{ borderTopLeftRadius: 24, borderTopRightRadius: 24, maxHeight: '88vh', overflowY: 'auto' }}
            onClick={(event) => event.stopPropagation()}
          >
            <div className="d-flex justify-content-between align-items-center mb-2">
              <h2 className="h6 mb-0">Filters</h2>
              <button type="button" className="btn-close" aria-label="Close filters" onClick={() => setShowFilters(false)} />
            </div>
            {filters}
            <button type="button" className="btn btn-mp-primary w-100 mt-3" onClick={() => setShowFilters(false)}>
              Show {total} results
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
