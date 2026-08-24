import { Link } from 'react-router-dom';
import type { ApiBusiness } from '../../../types/api';

/**
 * A supplier, as it appears in discovery.
 *
 * Deliberately shows absence honestly: an unrated business reads "No ratings yet",
 * never "0.0", and an unverified one simply has no badge rather than a red one. A
 * new supplier on this marketplace is unproven, not bad, and the passport on their
 * profile is where the full evidence lives.
 */
interface SupplierCardProps {
  business: ApiBusiness;
}

export default function SupplierCard({ business }: SupplierCardProps) {
  const a = business.attributes;
  const location = business.relationships?.location?.attributes;
  const category = business.relationships?.category?.attributes;
  const areas = business.relationships?.service_areas ?? [];

  const place = [location?.city, location?.state].filter(Boolean).join(', ');

  return (
    <Link to={`/business/${a.slug}`} className="mp-supplier-card text-decoration-none">
      <div className="d-flex align-items-start gap-3">
        {a.logo_url ? (
          <img src={a.logo_url} alt="" className="mp-supplier-card__logo" loading="lazy" />
        ) : (
          <span className="mp-supplier-card__logo" aria-hidden="true">
            {a.name.charAt(0).toUpperCase()}
          </span>
        )}
        <div className="min-width-0 flex-grow-1">
          <div className="mp-supplier-card__name">{a.name}</div>
          <div className="mp-supplier-card__meta">
            {category?.name ?? 'General supplier'}
            {place && ` · ${place}`}
          </div>
          <div className="d-flex gap-2 flex-wrap mt-2">
            {a.verification_status === 'verified' && <span className="mp-badge-verified">✓ Verified</span>}
            {a.trade_type && (
              <span className="mp-status-badge mp-status-draft text-uppercase">{a.trade_type}</span>
            )}
            {!a.is_available && <span className="mp-status-badge mp-status-archived">Not taking work</span>}
          </div>
        </div>
      </div>

      {a.about && (
        <p className="mp-supplier-card__meta mb-0" style={{ display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>
          {a.about}
        </p>
      )}

      {areas.length > 0 && (
        <div className="mp-supplier-card__meta">
          Serves {areas.slice(0, 3).map((area) => area.attributes.city).join(', ')}
          {areas.length > 3 && ` +${areas.length - 3} more`}
        </div>
      )}

      <div className="mp-supplier-card__stats">
        <span>
          {a.rating !== null ? (
            <>
              <b>★ {Number(a.rating).toFixed(1)}</b> ({a.review_count})
            </>
          ) : (
            'No ratings yet'
          )}
        </span>
        {a.years_in_business !== null && <span><b>{a.years_in_business}</b> yrs</span>}
        {(a.listings_count ?? 0) > 0 && <span><b>{a.listings_count}</b> listings</span>}
      </div>
    </Link>
  );
}
