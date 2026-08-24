/**
 * Star rating, input or display.
 *
 * Accessibility: the input form is a real radio group, so it is keyboard-operable
 * and announces "3 of 5 stars" rather than presenting five unlabelled glyphs. The
 * read-only form is a single labelled image, because a screen reader should hear
 * "Rated 4 out of 5" once, not five separate stars.
 */
interface StarRatingProps {
  value: number;
  onChange?: (value: number) => void;
  readOnly?: boolean;
  /** Extra hint for the group label, e.g. a business name. */
  label?: string;
}

const STARS = [1, 2, 3, 4, 5];

export default function StarRating({ value, onChange, readOnly = false, label }: StarRatingProps) {
  if (readOnly || !onChange) {
    return (
      <span className="mp-stars" role="img" aria-label={`Rated ${value} out of 5`}>
        {STARS.map((star) => (
          <span key={star} className={star <= value ? 'is-on' : ''} aria-hidden="true">
            ★
          </span>
        ))}
      </span>
    );
  }

  return (
    <fieldset className="mp-stars-input border-0 p-0 m-0">
      <legend className="form-label small mb-1">{label ? `Rating for ${label}` : 'Your rating'}</legend>
      {STARS.map((star) => (
        <label key={star} className={`mp-stars-input__star ${star <= value ? 'is-on' : ''}`}>
          <input
            type="radio"
            name="rating"
            value={star}
            checked={value === star}
            onChange={() => onChange(star)}
            className="visually-hidden"
          />
          <span aria-hidden="true">★</span>
          <span className="visually-hidden">{star} of 5 stars</span>
        </label>
      ))}
    </fieldset>
  );
}
