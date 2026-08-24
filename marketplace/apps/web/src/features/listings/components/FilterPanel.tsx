import { useEffect, useState } from 'react';
import { api } from '../../../lib/api/client';
import type { ApiAttributeDefinition, ApiCategory, SimpleListResponse } from '../../../types/api';

/**
 * Catalogue filters.
 *
 * The important design change from the original: there are no industry-specific
 * fields in this file. The old version hardcoded `PROPERTY_TYPES` and a "Buy / Rent"
 * toggle, which meant the catalogue could only ever be filtered like a property
 * portal.
 *
 * Now the category list comes from `/categories`, and once a category is chosen its
 * *own* filterable attributes are fetched from
 * `/categories/{slug}/attribute-definitions` and rendered dynamically. Real estate
 * still gets property type and buy/rent — because those are rows in its attribute
 * table — and a steel supplier gets grade and finish, with no code change.
 */
export interface ListingFilters {
  category: string;
  city: string;
  minPrice: string;
  maxPrice: string;
  availability: string;
  /** Category attribute values, keyed by definition key. Sent as filter[attr][key]. */
  attributes: Record<string, string>;
}

export const EMPTY_FILTERS: ListingFilters = {
  category: '',
  city: '',
  minPrice: '',
  maxPrice: '',
  availability: '',
  attributes: {},
};

interface FilterPanelProps {
  filters: ListingFilters;
  onChange: (filters: ListingFilters) => void;
  onApply: () => void;
}

const AVAILABILITY = [
  { value: '', label: 'Any' },
  { value: 'available', label: 'Available' },
  { value: 'sold', label: 'Sold' },
  { value: 'rented', label: 'Rented' },
];

/** `min_order_quantity` -> `Min order quantity`. Presentation only. */
function humanise(key: string): string {
  return key.replace(/[_-]/g, ' ').replace(/^./, (c) => c.toUpperCase());
}

export default function FilterPanel({ filters, onChange, onApply }: FilterPanelProps) {
  const [categories, setCategories] = useState<ApiCategory[]>([]);
  const [definitions, setDefinitions] = useState<ApiAttributeDefinition[]>([]);

  const set = (patch: Partial<ListingFilters>) => onChange({ ...filters, ...patch });

  useEffect(() => {
    api
      .get<SimpleListResponse<ApiCategory>>('/categories', { params: { with_counts: 1 } })
      .then((res) => setCategories(res.data.data.filter((c) => (c.attributes.listings_count ?? 1) >= 0)))
      .catch(() => undefined);
  }, []);

  // Attribute definitions are per-category, so they reload whenever it changes.
  useEffect(() => {
    if (!filters.category) {
      setDefinitions([]);

      return;
    }

    api
      .get<SimpleListResponse<ApiAttributeDefinition>>(`/categories/${filters.category}/attribute-definitions`)
      .then((res) => setDefinitions(res.data.data.filter((d) => d.attributes.is_filterable)))
      .catch(() => setDefinitions([]));
  }, [filters.category]);

  const setAttribute = (key: string, value: string) => {
    const next = { ...filters.attributes };
    if (value === '') delete next[key];
    else next[key] = value;
    set({ attributes: next });
  };

  // Industries first, then their specialities indented — a flat alphabetical list
  // of 90 categories is unusable.
  const industries = categories.filter((c) => c.attributes.is_top_level);
  const childrenOf = (id: number) => categories.filter((c) => c.attributes.parent_id === id);

  return (
    <div className="mp-card p-4">
      <h3 className="h6 mb-3">Filters</h3>

      <div className="mp-form-field mb-3">
        <label htmlFor="listing-category">Category</label>
        <select
          id="listing-category"
          className="form-select"
          value={filters.category}
          onChange={(event) => set({ category: event.target.value, attributes: {} })}
        >
          <option value="">All categories</option>
          {industries.map((industry) => (
            <optgroup key={industry.id} label={industry.attributes.name}>
              <option value={industry.attributes.slug}>
                All {industry.attributes.name}
                {industry.attributes.listings_count ? ` (${industry.attributes.listings_count})` : ''}
              </option>
              {childrenOf(industry.id).map((child) => (
                <option key={child.id} value={child.attributes.slug}>
                  {child.attributes.name}
                  {child.attributes.listings_count ? ` (${child.attributes.listings_count})` : ''}
                </option>
              ))}
            </optgroup>
          ))}
        </select>
      </div>

      <div className="mp-form-field mb-3">
        <label htmlFor="listing-city">City</label>
        <input
          id="listing-city"
          className="form-control"
          value={filters.city}
          onChange={(event) => set({ city: event.target.value })}
          placeholder="Anywhere in India"
        />
      </div>

      <div className="row g-2 mb-3">
        <div className="col-6 mp-form-field">
          <label htmlFor="listing-min-price">Min price</label>
          <input
            id="listing-min-price"
            type="number"
            min="0"
            className="form-control"
            value={filters.minPrice}
            onChange={(event) => set({ minPrice: event.target.value })}
          />
        </div>
        <div className="col-6 mp-form-field">
          <label htmlFor="listing-max-price">Max price</label>
          <input
            id="listing-max-price"
            type="number"
            min="0"
            className="form-control"
            value={filters.maxPrice}
            onChange={(event) => set({ maxPrice: event.target.value })}
          />
        </div>
      </div>

      <div className="mp-form-field mb-3">
        <label>Availability</label>
        <div className="d-flex gap-1 flex-wrap">
          {AVAILABILITY.map((option) => (
            <button
              key={option.value || 'any'}
              type="button"
              className={`btn btn-sm ${filters.availability === option.value ? 'btn-mp-primary' : 'btn-mp-outline'}`}
              onClick={() => set({ availability: option.value })}
            >
              {option.label}
            </button>
          ))}
        </div>
      </div>

      {/* Category-specific filters, straight from the database. */}
      {definitions.length > 0 && (
        <div className="pt-2 mb-3" style={{ borderTop: '1px dashed var(--mp-border)' }}>
          <div className="small fw-bold mb-2" style={{ color: 'var(--mp-ink)' }}>
            Specific to this category
          </div>
          {definitions.map((definition) => {
            const a = definition.attributes;
            const value = filters.attributes[a.key] ?? '';
            const id = `attr-${a.key}`;

            return (
              <div key={a.key} className="mp-form-field mb-3">
                <label htmlFor={id}>{a.label || humanise(a.key)}</label>

                {a.type === 'select' && a.options ? (
                  <select id={id} className="form-select" value={value} onChange={(e) => setAttribute(a.key, e.target.value)}>
                    <option value="">Any</option>
                    {a.options.map((option) => (
                      <option key={option} value={option}>{humanise(option)}</option>
                    ))}
                  </select>
                ) : a.type === 'boolean' ? (
                  <select id={id} className="form-select" value={value} onChange={(e) => setAttribute(a.key, e.target.value)}>
                    <option value="">Any</option>
                    <option value="1">Yes</option>
                    <option value="0">No</option>
                  </select>
                ) : (
                  <input
                    id={id}
                    className="form-control"
                    type={a.type === 'integer' || a.type === 'decimal' ? 'number' : 'text'}
                    value={value}
                    onChange={(e) => setAttribute(a.key, e.target.value)}
                  />
                )}
              </div>
            );
          })}
        </div>
      )}

      {filters.category === '' && (
        <p className="form-text small">Pick a category to unlock filters specific to it.</p>
      )}

      <button type="button" className="btn btn-mp-primary w-100" onClick={onApply}>
        Apply filters
      </button>
    </div>
  );
}
