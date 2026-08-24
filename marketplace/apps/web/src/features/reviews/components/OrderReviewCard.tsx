import { useState } from 'react';
import { api, getErrorMessage } from '../../../lib/api/client';
import type { ApiEnvelope, ApiOrder, ApiReview } from '../../../types/api';
import StarRating from './StarRating';

/**
 * The review block on an order.
 *
 * Which of the three states it shows is decided entirely by the API: `can_review`
 * for the form, `review_blocked_reason` for the explanation, and the loaded review
 * for the result. The frontend never works out eligibility itself, so it cannot
 * offer a review the backend would reject — or hide one it would allow.
 *
 * The business side of the same card is the reply box, shown only when the API says
 * `can_respond`.
 */
interface OrderReviewCardProps {
  order: ApiOrder;
  onChange?: () => void;
}

export default function OrderReviewCard({ order, onChange }: OrderReviewCardProps) {
  const existing = order.relationships.review ?? null;
  const a = order.attributes;

  const [rating, setRating] = useState(0);
  const [comment, setComment] = useState('');
  const [response, setResponse] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [review, setReview] = useState<ApiReview | null>(existing);

  // A business with no review yet doesn't need an empty card taking up space.
  if (review === null && a.viewer_party === 'business') {
    return null;
  }

  // Nothing to say: not the customer, and nothing reviewable.
  if (review === null && !a.can_review && !a.review_blocked_reason) {
    return null;
  }

  /*
   * The API explains ineligibility for every state, but "you can review once this is
   * completed" is only worth saying when completion is in sight. On a freshly placed
   * order it would be premature noise, so the card stays away until the work has at
   * least been handed over.
   */
  const nearlyDone = ['ready', 'dispatched', 'delivered', 'completed'].includes(a.status);
  if (review === null && !a.can_review && !nearlyDone) {
    return null;
  }

  const submit = async () => {
    setError('');
    setBusy(true);
    try {
      const res = await api.post<ApiEnvelope<ApiReview>>(`/orders/${order.id}/review`, {
        rating,
        ...(comment.trim() ? { comment: comment.trim() } : {}),
      });
      setReview(res.data.data);
      onChange?.();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const submitResponse = async () => {
    if (review === null) return;
    setError('');
    setBusy(true);
    try {
      const res = await api.post<ApiEnvelope<ApiReview>>(`/reviews/${review.id}/response`, { response: response.trim() });
      setReview(res.data.data);
      setResponse('');
      onChange?.();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mp-card p-4 mb-3">
      <h2 className="h6 mb-2">{review ? 'Review' : 'Rate this business'}</h2>

      {error && <div className="alert alert-danger py-2 small" role="alert">{error}</div>}

      {review === null && a.can_review && (
        <>
          <p className="small text-muted">
            Your rating is public and permanent. It counts because it is tied to a completed order.
          </p>
          <StarRating value={rating} onChange={setRating} />
          <label className="form-label small mt-3" htmlFor="review-comment">
            What was it like to work with them? (optional)
          </label>
          <textarea
            id="review-comment"
            className="form-control form-control-sm"
            rows={3}
            maxLength={2000}
            value={comment}
            onChange={(event) => setComment(event.target.value)}
          />
          <button type="button" className="btn btn-mp-primary btn-sm mt-2" disabled={busy || rating < 1} onClick={submit}>
            {busy ? 'Publishing…' : 'Publish review'}
          </button>
          {rating < 1 && <div className="form-text small">Pick a rating to continue.</div>}
        </>
      )}

      {/* Why the form isn't here. Better than a silently missing button. */}
      {review === null && !a.can_review && a.review_blocked_reason && (
        <p className="small text-muted mb-0">{a.review_blocked_reason}</p>
      )}

      {review !== null && (
        <div>
          <div className="d-flex align-items-center gap-2 flex-wrap">
            <StarRating value={review.attributes.rating} readOnly />
            <span className="small text-muted">
              {review.attributes.reviewer_name}
              {review.attributes.created_at && ` · ${review.attributes.created_at.slice(0, 10)}`}
            </span>
            {review.attributes.status !== 'published' && (
              <span className={`mp-status-badge mp-status-${review.attributes.status}`}>{review.attributes.status_label}</span>
            )}
          </div>
          {review.attributes.comment && <p className="small mt-2 mb-0">{review.attributes.comment}</p>}

          {review.attributes.business_response && (
            <div className="mt-3 p-3 mp-counter-summary">
              <div className="small fw-semibold">Response from the business</div>
              <div className="small">{review.attributes.business_response}</div>
            </div>
          )}

          {review.attributes.can_respond && (
            <div className="mt-3">
              <label className="form-label small" htmlFor="review-response">Reply publicly (once)</label>
              <textarea
                id="review-response"
                className="form-control form-control-sm"
                rows={3}
                maxLength={2000}
                value={response}
                onChange={(event) => setResponse(event.target.value)}
              />
              <button
                type="button"
                className="btn btn-sm btn-mp-outline mt-2"
                disabled={busy || response.trim().length < 2}
                onClick={submitResponse}
              >
                {busy ? 'Publishing…' : 'Publish response'}
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
