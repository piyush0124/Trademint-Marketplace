import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { api } from '../../../lib/api/client';
import type { ApiBusiness, ApiEnvelope, ApiListing, ApiProduct, ApiService, PaginatedResponse } from '../../../types/api';
import ListingCard from '../../listings/components/ListingCard';
import LoadingState from '../../../components/ui/LoadingState';
import ErrorState from '../../../components/ui/ErrorState';
import BusinessPassportPanel from '../components/BusinessPassportPanel';
import { useSeo } from '../../../lib/useSeo';
import Icon from '../../../components/ui/Icon';

export default function BusinessProfilePage() {
  const { slug } = useParams<{ slug: string }>();
  const [business, setBusiness] = useState<ApiBusiness | null>(null);
  const [listings, setListings] = useState<ApiListing[]>([]);
  const [products, setProducts] = useState<ApiProduct[]>([]);
  const [services, setServices] = useState<ApiService[]>([]);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');

  useEffect(() => {
    if (!slug) return;
    api
      .get<ApiEnvelope<ApiBusiness>>(`/businesses/${slug}`)
      .then((res) => {
        setBusiness(res.data.data);
        setStatus('ready');
        return Promise.all([
          api.get<PaginatedResponse<ApiListing>>('/listings', { params: { 'filter[business]': slug, per_page: 20 } }),
          api.get<ApiEnvelope<ApiProduct[]>>(`/businesses/${slug}/products`),
          api.get<ApiEnvelope<ApiService[]>>(`/businesses/${slug}/services`),
        ]);
      })
      .then((results) => {
        if (!results) return;
        const [listingsRes, productsRes, servicesRes] = results;
        setListings(listingsRes.data.data);
        setProducts(productsRes.data.data);
        setServices(servicesRes.data.data);
      })
      .catch(() => setStatus('error'));
  }, [slug]);

  /*
   * Called before the early returns below, because a hook cannot sit after a
   * conditional return — React requires the same hooks in the same order on
   * every render.
   *
   * That means it runs while `business` is still null, so the title falls back to
   * a generic one and is replaced when the record arrives. useSeo is keyed on its
   * arguments, so that second render updates the tags rather than duplicating them.
   */
  useSeo({
    title: business?.attributes.name ?? 'Business profile',
    description: business
      ? `${business.attributes.name}${business.attributes.is_verified ? ' — verified supplier' : ''}. ${
          business.attributes.about ?? 'View services, products and reviews, and send an enquiry.'
        }`.slice(0, 300)
      : undefined,
    canonical: slug ? `/business/${slug}` : undefined,
    type: 'profile',
  });

  if (status === 'loading') return <div className="mp-container py-4"><LoadingState /></div>;
  if (status === 'error' || !business) return <div className="mp-container py-4"><ErrorState message="Business not found." /></div>;

  const attrs = business.attributes;
  const industries = business.relationships?.industries ?? [];
  const serviceAreas = business.relationships?.service_areas ?? [];

  return (
    <div>
      <div style={{ background: 'linear-gradient(135deg, var(--mp-primary-dark), var(--mp-primary))', height: 160 }} />
      <div className="mp-container">
        <div className="mp-card p-4" style={{ marginTop: -60 }}>
          <div className="d-flex align-items-center gap-3 flex-wrap">
            <div>
              <h1 className="h4 mb-1">{attrs.name}</h1>
              <div className="d-flex flex-wrap gap-2">
                {attrs.is_verified && (
                  <span className="mp-badge-verified">
                    <Icon name="shield" /> Verified business
                  </span>
                )}
                {attrs.business_type && <span className="text-muted small text-capitalize">{attrs.business_type.replace('_', ' ')}</span>}
                {attrs.years_in_business != null && <span className="text-muted small">{attrs.years_in_business} yrs in business</span>}
                {attrs.trade_type && <span className="text-muted small text-uppercase">{attrs.trade_type}</span>}
              </div>
            </div>
            <div className="ms-auto d-flex gap-2">
              {attrs.phone && (
                <a href={`tel:${attrs.phone}`} className="btn btn-mp-outline">
                  Call
                </a>
              )}
              {attrs.whatsapp && (
                <a href={`https://wa.me/${attrs.whatsapp.replace(/\D/g, '')}`} target="_blank" rel="noreferrer" className="btn btn-mp-outline">
                  WhatsApp
                </a>
              )}
            </div>
          </div>
          {attrs.rating !== null ? (
            <div className="mp-text-caption mt-2 d-flex align-items-center gap-1">
              <Icon name="star" className="text-warning" />
              <span className="mp-tabular">{Number(attrs.rating).toFixed(1)}</span>
              <span className="text-muted">
                from {attrs.review_count} completed {attrs.review_count === 1 ? 'order' : 'orders'}
              </span>
            </div>
          ) : (
            <div className="small text-muted mt-2">No ratings yet</div>
          )}

          {attrs.about && <p className="text-muted mt-3 mb-0">{attrs.about}</p>}

          {(industries.length > 0 || serviceAreas.length > 0) && (
            <div className="mt-3 d-flex flex-wrap gap-3">
              {industries.length > 0 && (
                <div className="small text-muted">
                  <strong>Industries:</strong> {industries.map((i) => i.attributes.name).join(', ')}
                </div>
              )}
              {serviceAreas.length > 0 && (
                <div className="small text-muted">
                  <strong>Serves:</strong> {serviceAreas.map((a) => a.attributes.city).join(', ')}
                </div>
              )}
            </div>
          )}
        </div>

        {/* Phase 9: the evidence behind the badge, not just the badge. */}
        {slug && <BusinessPassportPanel slug={slug} />}

        {products.length > 0 && (
          <>
            <h2 className="h5 mt-5 mb-3">Products</h2>
            <div className="row g-3 pb-3">
              {products.map((p) => (
                <div key={p.id} className="col-sm-6 col-lg-4">
                  <div className="mp-card p-3">
                    <div className="fw-semibold">{p.attributes.name}</div>
                    {p.attributes.description && <div className="text-muted small mt-1">{p.attributes.description}</div>}
                    {p.attributes.min_order && <div className="text-muted small mt-1">Min order: {p.attributes.min_order} {p.attributes.unit}</div>}
                  </div>
                </div>
              ))}
            </div>
          </>
        )}

        {services.length > 0 && (
          <>
            <h2 className="h5 mt-4 mb-3">Services</h2>
            <div className="row g-3 pb-3">
              {services.map((s) => (
                <div key={s.id} className="col-sm-6 col-lg-4">
                  <div className="mp-card p-3">
                    <div className="fw-semibold">{s.attributes.name}</div>
                    {s.attributes.description && <div className="text-muted small mt-1">{s.attributes.description}</div>}
                    {s.attributes.estimated_delivery_time && <div className="text-muted small mt-1">Delivery: {s.attributes.estimated_delivery_time}</div>}
                  </div>
                </div>
              ))}
            </div>
          </>
        )}

        <h2 className="h5 mt-5 mb-3">Listings</h2>
        {listings.length === 0 ? (
          <p className="text-muted">This business hasn't published any listings yet.</p>
        ) : (
          <div className="row g-4 pb-5">
            {listings.map((l) => (
              <div key={l.id} className="col-sm-6 col-lg-3">
                <ListingCard listing={l} />
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
