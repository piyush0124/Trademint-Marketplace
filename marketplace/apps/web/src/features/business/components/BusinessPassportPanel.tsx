import { useEffect, useState } from 'react';
import { api } from '../../../lib/api/client';
import type { ApiBusinessPassport, ApiEnvelope, ApiReview, PaginatedResponse } from '../../../types/api';
import StarRating from '../../reviews/components/StarRating';
import LoadingState from '../../../components/ui/LoadingState';
import Icon from '../../../components/ui/Icon';

/**
 * The Business Passport, as a customer sees it.
 *
 * Two rules shape this component, both inherited from the API rather than invented
 * here:
 *
 *  - A null metric is rendered as "not enough data yet", never as a zero. A new
 *    business is unproven, not bad, and showing "0%" would quietly libel it.
 *  - Every factor shows its own evidence string, so the score is auditable by the
 *    person reading it. Trust that can't be inspected isn't trust.
 */
interface BusinessPassportPanelProps {
  slug: string;
}

interface ReviewsMeta {
  rating: string | null;
  review_count: number;
  rating_breakdown: Record<string, number>;
}

export default function BusinessPassportPanel({ slug }: BusinessPassportPanelProps) {
  const [passport, setPassport] = useState<ApiBusinessPassport | null>(null);
  const [reviews, setReviews] = useState<ApiReview[]>([]);
  const [meta, setMeta] = useState<ReviewsMeta | null>(null);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [showAll, setShowAll] = useState(false);

  useEffect(() => {
    let cancelled = false;

    Promise.all([
      api.get<ApiEnvelope<ApiBusinessPassport>>(`/businesses/${slug}/passport`),
      api.get<PaginatedResponse<ApiReview, never> & { meta: ReviewsMeta }>(`/businesses/${slug}/reviews`, {
        params: { per_page: 20 },
      }),
    ])
      .then(([passportRes, reviewsRes]) => {
        if (cancelled) return;
        setPassport(passportRes.data.data);
        setReviews(reviewsRes.data.data);
        setMeta(reviewsRes.data.meta);
        setStatus('ready');
      })
      .catch(() => {
        if (!cancelled) setStatus('error');
      });

    return () => {
      cancelled = true;
    };
  }, [slug]);

  if (status === 'loading') return <div className="mp-card p-4 mt-4"><LoadingState rows={3} /></div>;
  // A passport failure must not take the profile down with it.
  if (status === 'error' || passport === null) return null;

  const a = passport.attributes;

  /*
   * The five independent verification checks, read from the business relationship
   * the passport already returns.
   *
   * GST is conditional: it is only a meaningful check for a GST-registered
   * business. Listing "GST not yet confirmed" against a small supplier below the
   * registration threshold would report a legitimate absence as a shortfall — and
   * Business::isFullyVerified() agrees, treating a null gst_number as satisfied.
   */
  const verification = passport.relationships.business?.attributes.verification;

  /*
   * `gst_applicable`, not `gst_number`.
   *
   * The number is deliberately withheld from anyone but the owner and admins, so a
   * buyer's copy of this payload never has it — keying off the number meant the GST
   * row vanished from the public checklist even when the check had passed.
   */
  const hasGstNumber = Boolean(verification?.gst_applicable);

  const checks = verification
    ? [
        { key: 'business', label: 'Business registration', done: verification.business },
        { key: 'address', label: 'Registered address', done: verification.address },
        { key: 'phone', label: 'Phone number', done: verification.phone },
        { key: 'email', label: 'Email address', done: verification.email },
        ...(hasGstNumber ? [{ key: 'gst', label: 'GST registration', done: verification.gst }] : []),
      ]
    : [];

  const visible = showAll ? reviews : reviews.slice(0, 5);
  const breakdown = meta?.rating_breakdown ?? {};
  const maxBucket = Math.max(1, ...Object.values(breakdown));

  return (
    <div className="mt-5">
      <h2 className="h5 mb-3">Business Passport</h2>

      {a.is_suspended && (
        <div className="alert alert-danger py-2 small" role="alert">
          This business is currently suspended and cannot take new orders.
        </div>
      )}

      <div className="row g-3">
        <div className="col-12 col-lg-5">
          <div className="mp-card p-4 h-100">
            <div className="d-flex align-items-center gap-3">
              <div className="mp-trust-score" aria-hidden={a.trust_score === null}>
                {a.trust_score === null ? '—' : a.trust_score}
              </div>
              <div>
                <div className="fw-semibold">Trust score</div>
                <div className="small text-muted">
                  {a.trust_score === null
                    ? 'Not enough activity to score yet.'
                    : `${a.trust_score} out of 100, from verified activity.`}
                </div>
              </div>
            </div>

            <div className="mt-3">
              <span className={`mp-status-badge mp-status-${a.verification_status}`}>{a.verification_status_label}</span>
              {a.verified_at && <span className="small text-muted ms-2">since {a.verified_at}</span>}
            </div>

            {/*
              * The per-check breakdown.
              *
              * This data was already being fetched — `relationships.business` carries
              * a `verification` map of five independent checks — and was rendered
              * nowhere except the owner's own settings page. So a buyer, the one
              * person who needs it to decide whether to send money, saw only the
              * single aggregate badge above.
              *
              * A binary "Verified / Unverified" also collapses a genuinely useful
              * middle: a supplier with a confirmed business registration and address
              * but no GST filing yet is very different from one with nothing checked,
              * and the aggregate badge calls both "Unverified".
              */}
            {checks.length > 0 && (
              <div className="mt-4">
                <div className="mp-eyebrow mb-2">What has been checked</div>
                <ul className="mp-checks" aria-label="Verification checks">
                  {checks.map((check) => (
                    <li key={check.key} className={check.done ? 'is-done' : 'is-pending'}>
                      <Icon name={check.done ? 'check' : 'lock'} size={14} />
                      <span className="mp-checks__label">{check.label}</span>
                      {/*
                        * State is written as a word, not conveyed by the tick and the
                        * colour alone — those are unavailable to a screen reader and
                        * ambiguous to a colour-blind reader.
                        */}
                      <span className="mp-checks__state">{check.done ? 'Confirmed' : 'Not yet'}</span>
                    </li>
                  ))}
                </ul>
                <p className="mp-text-micro text-muted mt-2 mb-0">
                  Checks are confirmed by our team against documents the business supplied. The documents
                  themselves are never published.
                </p>
              </div>
            )}

            {/* The breakdown. Unavailable factors are stated, not hidden — that's
                the difference between a transparent score and a black box. */}
            <ul className="list-unstyled small mt-3 mb-0">
              {a.trust_factors.map((factor) => (
                <li key={factor.key} className="mp-trust-factor">
                  <div className="d-flex justify-content-between gap-2">
                    <span className="fw-semibold">{factor.label}</span>
                    <span className={factor.available ? '' : 'text-muted'}>
                      {factor.available && factor.score !== null ? `${Math.round(factor.score * 100)}%` : 'No data yet'}
                    </span>
                  </div>
                  <div className="text-muted">{factor.evidence}</div>
                  <div
                    className="mp-trust-factor__bar"
                    role="img"
                    aria-label={`${factor.label}: ${factor.available && factor.score !== null ? `${Math.round(factor.score * 100)} percent` : 'no data yet'}`}
                  >
                    <span style={{ width: `${factor.available && factor.score !== null ? Math.round(factor.score * 100) : 0}%` }} />
                  </div>
                </li>
              ))}
            </ul>
          </div>
        </div>

        <div className="col-12 col-lg-7">
          <div className="row g-3">
            {[
              { label: 'Completed Orders', value: a.completed_orders },
              { label: 'Reviews', value: a.review_count },
              {
                label: 'Response Rate',
                value: a.response_rate === null ? 'No data' : `${a.response_rate}%`,
              },
              {
                label: 'Typical Reply',
                value: a.response_time_hours === null ? 'No data' : `${a.response_time_hours} h`,
              },
              {
                label: 'Years Trading',
                value: a.years_in_business === null ? 'No data' : a.years_in_business,
              },
              { label: 'Documents Approved', value: `${a.document_status.approved}/${a.document_status.total}` },
            ].map((stat) => (
              <div key={stat.label} className="col-6 col-md-4">
                <div className="mp-stat-card">
                  <div className="mp-stat-card__value" style={{ fontSize: '1.35rem' }}>{stat.value}</div>
                  <div className="mp-stat-card__label">{stat.label}</div>
                </div>
              </div>
            ))}
          </div>

          <div className="mp-card p-4 mt-3">
            <div className="d-flex justify-content-between align-items-center flex-wrap gap-2">
              <h3 className="h6 mb-0">Customer reviews</h3>
              {meta?.rating ? (
                <div className="d-flex align-items-center gap-2">
                  <StarRating value={Math.round(Number(meta.rating))} readOnly />
                  <span className="small text-muted">{Number(meta.rating).toFixed(1)} · {meta.review_count} reviews</span>
                </div>
              ) : (
                <span className="small text-muted">No ratings yet</span>
              )}
            </div>

            {meta && meta.review_count > 0 && (
              <div className="mt-3">
                {[5, 4, 3, 2, 1].map((star) => (
                  <div key={star} className="d-flex align-items-center gap-2 small">
                    <span style={{ width: 12 }}>{star}</span>
                    <span aria-hidden="true">★</span>
                    <div className="mp-rating-bar flex-grow-1">
                      <span style={{ width: `${((breakdown[String(star)] ?? 0) / maxBucket) * 100}%` }} />
                    </div>
                    <span className="text-muted" style={{ width: 24 }}>{breakdown[String(star)] ?? 0}</span>
                  </div>
                ))}
              </div>
            )}

            {reviews.length === 0 && (
              <p className="small text-muted mt-3 mb-0">
                No reviews yet. Reviews here only come from customers who completed an order, so they take time to appear.
              </p>
            )}

            <div className="d-flex flex-column gap-3 mt-3">
              {visible.map((review) => (
                <div key={review.id} className="border-top pt-3">
                  <div className="d-flex align-items-center gap-2 flex-wrap">
                    <StarRating value={review.attributes.rating} readOnly />
                    <span className="small text-muted">
                      {review.attributes.reviewer_name}
                      {review.attributes.created_at && ` · ${review.attributes.created_at.slice(0, 10)}`}
                    </span>
                  </div>
                  {review.attributes.comment && <p className="small mt-2 mb-0">{review.attributes.comment}</p>}
                  {review.attributes.business_response && (
                    <div className="mt-2 p-3 mp-counter-summary">
                      <div className="small fw-semibold">Response from the business</div>
                      <div className="small">{review.attributes.business_response}</div>
                    </div>
                  )}
                </div>
              ))}
            </div>

            {reviews.length > 5 && !showAll && (
              <button type="button" className="btn btn-sm btn-mp-ghost px-0 mt-2" onClick={() => setShowAll(true)}>
                Show all {reviews.length} reviews
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
