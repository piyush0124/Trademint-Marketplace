import { Link } from 'react-router-dom';
import { formatPrice } from '../../../lib/api/client';
import type { ApiListing } from '../../../types/api';
import Badge from '../../../components/ui/Badge';
import FavoriteButton from './FavoriteButton';
import { listingPath } from '../../../lib/urls';

interface ListingCardProps {
  listing: ApiListing;
  featured?: boolean;
}

/** `area_sqft` -> `Area sqft`. Attribute keys are database-defined, so labels are derived. */
function humaniseKey(key: string): string {
  return key.replace(/[_-]/g, ' ').replace(/^./, (c) => c.toUpperCase());
}

function formatValue(value: string | number | boolean | null): string | null {
  if (value === null || value === '') return null;
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';

  return String(value);
}

/**
 * A catalogue item.
 *
 * The spec line used to read `bedrooms`, `bathrooms` and `furnished` directly, which
 * silently made this a property card — every non-property listing rendered a blank
 * strip. It now shows the *generic* commercial facts the marketplace actually models
 * (unit, minimum order, price range) and then up to three of whatever
 * category-specific attributes the listing happens to carry, whose keys and labels
 * are defined in the database.
 */
export default function ListingCard({ listing, featured = false }: ListingCardProps) {
  const { attributes, relationships } = listing;
  const location = relationships.location?.attributes;
  const category = relationships.category?.attributes;
  const media = relationships.media ?? [];
  const primaryImage = media.find((m) => m.attributes.is_primary) ?? media[0];
  const business = relationships.business;

  // Generic commercial facts first — these apply to steel, software and studios
  // alike, which is the test any "spec" on this card has to pass.
  const specs: string[] = [];
  if (attributes.unit) specs.push(`per ${attributes.unit}`);
  if (attributes.min_order_quantity) {
    specs.push(`min ${attributes.min_order_quantity}${attributes.unit ? ` ${attributes.unit}` : ''}`);
  }

  // Then whatever the category defines, capped so the card cannot grow unevenly.
  Object.entries(attributes.attributes ?? {})
    .filter(([, value]) => formatValue(value) !== null)
    .slice(0, 3)
    .forEach(([key, value]) => specs.push(`${humaniseKey(key)}: ${formatValue(value)}`));

  const priceLabel =
    attributes.price_max && attributes.price_max !== attributes.price
      ? `${formatPrice(attributes.price, attributes.currency)} – ${formatPrice(attributes.price_max, attributes.currency)}`
      : formatPrice(attributes.price, attributes.currency);

  return (
    <div className="mp-listing-card">
      <Link to={listingPath(listing)} className="mp-listing-card__media">
        {primaryImage?.attributes.url ? (
          <img src={primaryImage.attributes.url} alt={attributes.title ?? 'Listing image'} loading="lazy" />
        ) : (
          <div className="d-flex align-items-center justify-content-center h-100 text-muted small">No image yet</div>
        )}
        <div className="mp-listing-card__badges">
          {featured && <Badge variant="featured">Featured</Badge>}
          {business?.attributes.verification_status === 'verified' && <Badge variant="verified">✓ Verified</Badge>}
        </div>
        {media.length > 1 && <span className="mp-listing-card__image-count">{media.length} images</span>}
      </Link>
      <FavoriteButton listingId={listing.id} className="mp-listing-card__favorite" />

      <div className="mp-listing-card__body">
        <span className="mp-price">{priceLabel}</span>
        <Link to={listingPath(listing)} className="mp-listing-card__title text-decoration-none">
          {attributes.title}
        </Link>
        <div className="mp-listing-card__location">
          {category?.name}
          {location && ` · ${[location.area, location.city].filter(Boolean).join(', ')}`}
        </div>
        {specs.length > 0 && <div className="mp-listing-card__specs">{specs.join(' • ')}</div>}
      </div>

      <div className="mp-listing-card__footer">
        {business ? (
          <Link
            to={`/business/${business.attributes.slug}`}
            className="small text-truncate text-decoration-none"
            style={{ color: 'var(--mp-muted)' }}
          >
            {business.attributes.name}
            {business.attributes.rating !== null && ` · ★ ${Number(business.attributes.rating).toFixed(1)}`}
          </Link>
        ) : (
          <span className="small text-muted text-truncate">{relationships.user?.attributes.name}</span>
        )}
        <Link to={listingPath(listing)} className="btn btn-mp-outline btn-sm">View</Link>
      </div>
    </div>
  );
}
