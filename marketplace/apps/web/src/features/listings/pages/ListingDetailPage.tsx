import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, formatPrice } from '../../../lib/api/client';
import type { ApiEnvelope, ApiListing } from '../../../types/api';
import ImageGallery from '../components/ImageGallery';
import ContactButtons from '../components/ContactButtons';
import FavoriteButton from '../components/FavoriteButton';
import EnquiryModal from '../components/EnquiryModal';
import StatusBadge from '../../../components/ui/StatusBadge';
import LoadingState from '../../../components/ui/LoadingState';
import ErrorState from '../../../components/ui/ErrorState';
import ListingCard from '../components/ListingCard';
import { useAuth } from '../../auth/AuthContext';
import { useSeo } from '../../../lib/useSeo';
import Icon from '../../../components/ui/Icon';

/**
 * Fallback label for an attribute whose definition didn't come through.
 *
 * This replaces a hardcoded `{ property_type: 'Property Type', bedrooms: ... }` map,
 * which only ever produced sensible labels for real-estate listings and showed raw
 * snake_case keys for every other industry. Labels now travel with the values in
 * `attribute_details`, straight from `category_attribute_definitions`.
 */
function humaniseKey(key: string): string {
  return key.replace(/[_-]/g, ' ').replace(/^./, (c) => c.toUpperCase());
}

export default function ListingDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { user } = useAuth();
  const [listing, setListing] = useState<ApiListing | null>(null);
  const [similar, setSimilar] = useState<ApiListing[]>([]);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [showEnquiry, setShowEnquiry] = useState(false);

  useEffect(() => {
    if (!id) return;
    setStatus('loading');
    api
      .get<ApiEnvelope<ApiListing>>(`/listings/${id}`)
      .then((res) => {
        setListing(res.data.data);
        setStatus('ready');
        const categorySlug = res.data.data.relationships.category?.attributes.slug;
        if (categorySlug) {
          api
            .get<{ data: ApiListing[] }>('/listings', { params: { 'filter[category]': categorySlug, per_page: 4 } })
            .then((r) => setSimilar(r.data.data.filter((l) => l.id !== id)))
            .catch(() => undefined);
        }
      })
      .catch(() => setStatus('error'));
  }, [id]);

  /*
   * Before the early returns — a hook cannot follow a conditional return. Runs
   * once with null data and again once the listing loads; useSeo is keyed on its
   * arguments so the second pass updates the same tags.
   */
  useSeo({
    title: listing?.attributes.title ?? 'Listing',
    description: listing
      ? (listing.attributes.description ?? 'View pricing and availability, and send an enquiry to this supplier.').slice(0, 300)
      : undefined,
    canonical: id ? `/listings/${id}` : undefined,
    // `url` is nullable on the media resource; SeoOptions.image is optional, not
    // nullable, so an absent image must be undefined rather than null.
    image: listing?.relationships.media?.[0]?.attributes.url ?? undefined,
  });

  if (status === 'loading') {
    return (
      <div className="mp-container py-4">
        <LoadingState rows={1} />
      </div>
    );
  }

  if (status === 'error' || !listing) {
    return (
      <div className="mp-container py-4">
        <ErrorState message="We couldn't load this listing." onRetry={() => window.location.reload()} />
      </div>
    );
  }

  const { attributes, relationships } = listing;
  const isOwner = user?.id === Number(relationships.user?.id);
  const business = relationships.business;

  const handleEnquire = () => setShowEnquiry(true);

  return (
    <div className="mp-container py-4">
      <nav className="small text-muted mb-3">
        <Link to="/" className="text-muted">Home</Link> /{' '}
        <Link to="/browse" className="text-muted">Catalogue</Link> /{' '}
        <span className="text-dark">{attributes.title ?? 'Untitled listing'}</span>
      </nav>

      <div className="row g-4">
        <div className="col-lg-7">
          <ImageGallery media={relationships.media ?? []} title={attributes.title ?? 'Untitled listing'} />
        </div>

        <div className="col-lg-5">
          <div className="d-flex align-items-center gap-2 mb-2">
            <StatusBadge status={attributes.system_status} />
            {attributes.availability_status !== 'available' && <StatusBadge status={attributes.availability_status} />}
          </div>
          <div className="d-flex align-items-start justify-content-between">
            <span className="mp-price fs-2">{formatPrice(attributes.price, attributes.currency)}</span>
            <FavoriteButton listingId={listing.id} className="btn btn-mp-outline" />
          </div>
          <h1 className="h4 mt-2">{attributes.title ?? 'Untitled listing'}</h1>
          {relationships.location && (
            <p className="text-muted d-flex align-items-center gap-1">
              <Icon name="map" />
              {[relationships.location.attributes.area, relationships.location.attributes.locality, relationships.location.attributes.city]
                .filter(Boolean)
                .join(', ')}
            </p>
          )}

          {/* Generic commercial facts, which every industry has. */}
          <div className="row g-2 mb-3">
            {attributes.unit && (
              <div className="col-6 col-md-4">
                <div className="mp-card p-2 px-3">
                  <div className="small text-muted">Priced per</div>
                  <div className="fw-semibold">{attributes.unit}</div>
                </div>
              </div>
            )}
            {attributes.min_order_quantity && (
              <div className="col-6 col-md-4">
                <div className="mp-card p-2 px-3">
                  <div className="small text-muted">Minimum order</div>
                  <div className="fw-semibold">
                    {attributes.min_order_quantity}
                    {attributes.unit ? ` ${attributes.unit}` : ''}
                  </div>
                </div>
              </div>
            )}

            {/* Then the category's own attributes, labelled by the database. */}
            {(attributes.attribute_details ?? []).map((detail) =>
              detail.value !== null && detail.value !== '' ? (
                <div key={detail.key} className="col-6 col-md-4">
                  <div className="mp-card p-2 px-3">
                    <div className="small text-muted">{detail.label || humaniseKey(detail.key)}</div>
                    <div className="fw-semibold">
                      {typeof detail.value === 'boolean' ? (detail.value ? 'Yes' : 'No') : String(detail.value)}
                    </div>
                  </div>
                </div>
              ) : null
            )}
          </div>

          {business && (
            <Link to={`/business/${business.attributes.slug}`} className="mp-card d-flex align-items-center gap-2 p-3 mb-3 text-decoration-none">
              <div className="fw-semibold text-dark">{business.attributes.name}</div>
              {business.attributes.is_verified && (
                <span className="mp-badge-verified">
                  <Icon name="shield" /> Verified
                </span>
              )}
            </Link>
          )}

          <div className="d-none d-md-block">
            {!isOwner && (
              <ContactButtons
                phone={business?.attributes.phone}
                whatsapp={business?.attributes.whatsapp}
                onEnquire={handleEnquire}
              />
            )}
          </div>

          {isOwner && (
            <div className="mt-3">
              <Link to={`/dashboard/listings/${listing.id}/edit`} className="btn btn-mp-outline w-100">
                Manage this listing
              </Link>
            </div>
          )}
        </div>
      </div>

      {attributes.description && (
        <section className="mt-5">
          <h2 className="h5 mb-2">Description</h2>
          <p className="text-body" style={{ whiteSpace: 'pre-line' }}>{attributes.description}</p>
        </section>
      )}

      {similar.length > 0 && (
        <section className="mt-5">
          <h2 className="h5 mb-3">Similar Listings</h2>
          <div className="row g-4">
            {similar.slice(0, 4).map((item) => (
              <div key={item.id} className="col-sm-6 col-lg-3">
                <ListingCard listing={item} />
              </div>
            ))}
          </div>
        </section>
      )}

      {!isOwner && (
        <ContactButtons
          layout="sticky"
          phone={business?.attributes.phone}
          whatsapp={business?.attributes.whatsapp}
          onEnquire={handleEnquire}
        />
      )}

      {showEnquiry && (
        <EnquiryModal listingId={listing.id} listingTitle={attributes.title ?? 'Untitled listing'} onClose={() => setShowEnquiry(false)} />
      )}
    </div>
  );
}
