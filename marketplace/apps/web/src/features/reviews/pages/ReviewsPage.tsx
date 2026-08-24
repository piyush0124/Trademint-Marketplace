import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, getErrorMessage } from '../../../lib/api/client';
import type { ApiEnvelope, ApiReview, PaginatedResponse } from '../../../types/api';
import StarRating from '../components/StarRating';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';
import ErrorState from '../../../components/ui/ErrorState';
import { useAuth } from '../../auth/AuthContext';

/**
 * Reviews written (customer) or received (business).
 *
 * The business can reply here, once, which is the one action available on this
 * screen — a business can never edit or remove a review, and the API enforces that
 * independently of this UI.
 */
export default function ReviewsPage() {
  const { isOwner } = useAuth();
  const [reviews, setReviews] = useState<ApiReview[]>([]);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [replyTo, setReplyTo] = useState<string | null>(null);
  const [response, setResponse] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const load = () => {
    setStatus('loading');
    api
      .get<PaginatedResponse<ApiReview>>('/reviews', { params: { per_page: 50 } })
      .then((res) => {
        setReviews(res.data.data);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  useEffect(load, []);

  const submitResponse = async (review: ApiReview) => {
    setError('');
    setBusy(true);
    try {
      const res = await api.post<ApiEnvelope<ApiReview>>(`/reviews/${review.id}/response`, { response: response.trim() });
      setReviews((current) => current.map((item) => (item.id === review.id ? res.data.data : item)));
      setReplyTo(null);
      setResponse('');
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const average =
    reviews.length > 0
      ? (reviews.reduce((sum, review) => sum + review.attributes.rating, 0) / reviews.length).toFixed(1)
      : null;

  return (
    <div>
      <div className="mb-3">
        <h1 className="h5 mb-1">Reviews</h1>
        <p className="text-muted small mb-0">
          {isOwner
            ? 'What customers said after completed orders. You can reply once to each.'
            : 'Reviews you have written. Each is tied to an order you completed.'}
        </p>
      </div>

      {error && <div className="alert alert-danger py-2 small" role="alert">{error}</div>}

      {status === 'ready' && reviews.length > 0 && (
        <div className="row g-3 mb-4">
          <div className="col-6 col-md-3">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{average}</div>
              <div className="mp-stat-card__label">Average Rating</div>
            </div>
          </div>
          <div className="col-6 col-md-3">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value">{reviews.length}</div>
              <div className="mp-stat-card__label">Total Reviews</div>
            </div>
          </div>
          {isOwner && (
            <div className="col-6 col-md-3">
              <div className="mp-stat-card">
                <div className="mp-stat-card__value">{reviews.filter((r) => r.attributes.can_respond).length}</div>
                <div className="mp-stat-card__label">Awaiting Reply</div>
              </div>
            </div>
          )}
        </div>
      )}

      {status === 'loading' && <LoadingState variant="list" rows={4} />}
      {status === 'error' && <ErrorState onRetry={load} />}

      {status === 'ready' && reviews.length === 0 && (
        <EmptyState
          icon="star"
          title={isOwner ? 'No reviews yet.' : "You haven't reviewed anyone yet."}
          description={
            isOwner
              ? 'Complete orders well and customers can review you. Reviews only come from real completed orders, so they carry weight.'
              : 'Once an order is completed you can rate the business you worked with.'
          }
          action={<Link to="/dashboard/orders" className="btn btn-mp-primary">Go to orders</Link>}
        />
      )}

      {status === 'ready' && reviews.length > 0 && (
        <div className="d-flex flex-column gap-2">
          {reviews.map((review) => {
            const a = review.attributes;
            const business = review.relationships.business ?? null;
            const order = review.relationships.order ?? null;

            return (
              <div key={review.id} className="mp-card p-3">
                <div className="d-flex justify-content-between align-items-start flex-wrap gap-2">
                  <div className="min-width-0">
                    <StarRating value={a.rating} readOnly />
                    <div className="small text-muted mt-1">
                      {isOwner ? a.reviewer_name : business?.attributes.name ?? 'Business'}
                      {a.created_at && ` · ${a.created_at.slice(0, 10)}`}
                      {order && (
                        <>
                          {' · '}
                          <Link to={`/dashboard/orders/${order.id}`} className="text-decoration-none">
                            {order.attributes.order_number}
                          </Link>
                        </>
                      )}
                    </div>
                  </div>
                  {a.status !== 'published' && (
                    <span className={`mp-status-badge mp-status-${a.status}`}>{a.status_label}</span>
                  )}
                </div>

                {a.comment && <p className="small mt-2 mb-0">{a.comment}</p>}

                {a.status === 'hidden' && (
                  <p className="small text-muted mt-2 mb-0">
                    This review has been hidden by a moderator and no longer counts towards the rating.
                  </p>
                )}

                {a.business_response && (
                  <div className="mt-3 p-3 mp-counter-summary">
                    <div className="small fw-semibold">{isOwner ? 'Your response' : 'Response from the business'}</div>
                    <div className="small">{a.business_response}</div>
                  </div>
                )}

                {a.can_respond && replyTo !== review.id && (
                  <button type="button" className="btn btn-sm btn-mp-outline mt-2" onClick={() => setReplyTo(review.id)}>
                    Reply
                  </button>
                )}

                {a.can_respond && replyTo === review.id && (
                  <div className="mt-2">
                    <label className="form-label small" htmlFor={`response-${review.id}`}>
                      Your public reply (once only)
                    </label>
                    <textarea
                      id={`response-${review.id}`}
                      className="form-control form-control-sm"
                      rows={3}
                      maxLength={2000}
                      value={response}
                      onChange={(event) => setResponse(event.target.value)}
                    />
                    <div className="d-flex gap-2 mt-2">
                      <button
                        type="button"
                        className="btn btn-sm btn-mp-primary"
                        disabled={busy || response.trim().length < 2}
                        onClick={() => submitResponse(review)}
                      >
                        {busy ? 'Publishing…' : 'Publish reply'}
                      </button>
                      <button type="button" className="btn btn-sm btn-mp-ghost" onClick={() => setReplyTo(null)}>Cancel</button>
                    </div>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
