import { useId, useState } from 'react';

interface AuthFieldProps {
  label: string;
  value: string;
  onChange: (value: string) => void;
  type?: 'text' | 'email' | 'password' | 'tel';
  autoComplete?: string;
  required?: boolean;
  error?: string;
  hint?: string;
  placeholder?: string;
  /** Render a show/hide toggle. Password fields only. */
  revealable?: boolean;
}

/**
 * One text field, with the accessibility wiring done once instead of per form.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS CENTRALISES
 * ---------------------------------------------------------------------------
 * Every one of these was previously either missing or hand-rolled per input:
 *
 *   - `aria-invalid` and `aria-describedby` pointing at the error, so a screen
 *     reader announces *what* is wrong rather than just that something is.
 *   - A generated id linking label to input, so tapping the label focuses the
 *     field. Hand-written ids are the usual source of duplicates once a component
 *     appears twice on a page.
 *   - The required marker split into a visual `*` (aria-hidden) and the word
 *     "required" for assistive tech, because an asterisk alone is meaningless
 *     when read aloud.
 *   - `role="alert"` on the error, so it is announced when it appears rather than
 *     only when focus happens to land there.
 *
 * ---------------------------------------------------------------------------
 * PASSWORD REVEAL
 * ---------------------------------------------------------------------------
 * A show/hide toggle materially reduces failed sign-ups — most password errors are
 * typos in a field the user cannot read. It defaults to hidden, and the button
 * announces its action rather than its state, so it is unambiguous when read out.
 */
export default function AuthField({
  label,
  value,
  onChange,
  type = 'text',
  autoComplete,
  required = false,
  error,
  hint,
  placeholder,
  revealable = false,
}: AuthFieldProps) {
  const id = useId();
  const [revealed, setRevealed] = useState(false);

  const errorId = `${id}-error`;
  const hintId = `${id}-hint`;
  const describedBy = error ? errorId : hint ? hintId : undefined;

  const inputType = revealable && revealed ? 'text' : type;

  const input = (
    <input
      id={id}
      type={inputType}
      className={`form-control${error ? ' is-invalid' : ''}`}
      value={value}
      onChange={(event) => onChange(event.target.value)}
      autoComplete={autoComplete}
      placeholder={placeholder}
      // `required` is passed to assistive tech but the form is noValidate, so the
      // browser's own bubble never appears — validation stays ours and the server's.
      aria-required={required || undefined}
      aria-invalid={error ? true : undefined}
      aria-describedby={describedBy}
    />
  );

  return (
    <div className="mp-form-field mb-3">
      <label htmlFor={id}>
        {label}
        {required && (
          <>
            {' '}
            <span aria-hidden="true">*</span>
            <span className="mp-sr-only">(required)</span>
          </>
        )}
      </label>

      {revealable ? (
        <div className="mp-field-affix">
          {input}
          <button
            type="button"
            className="mp-field-affix__btn"
            onClick={() => setRevealed((shown) => !shown)}
            // Names the action, not the state. "Password shown" leaves a
            // screen-reader user guessing what pressing it will do.
            aria-label={revealed ? 'Hide password' : 'Show password'}
          >
            {revealed ? 'Hide' : 'Show'}
          </button>
        </div>
      ) : (
        input
      )}

      {error ? (
        <div id={errorId} className="invalid-feedback d-block" role="alert">
          {error}
        </div>
      ) : (
        hint && (
          <div id={hintId} className="form-text">
            {hint}
          </div>
        )
      )}
    </div>
  );
}

/**
 * Password strength, shown as four segments.
 *
 * Scored on length and character variety only. Deliberately advisory — it never
 * blocks submission, because a strength meter that vetoes a passphrase a user has
 * chosen (and a password manager has generated) is a meter that gets worked around
 * with a worse password. The server enforces the actual 8-character minimum.
 */
export function PasswordStrength({ password }: { password: string }) {
  if (!password) {
    return null;
  }

  let score = 0;
  if (password.length >= 8) score++;
  if (password.length >= 12) score++;
  if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score++;
  if (/\d/.test(password) || /[^\w\s]/.test(password)) score++;

  const label = score <= 1 ? 'Weak' : score === 2 ? 'Fair' : score === 3 ? 'Good' : 'Strong';
  const state = score <= 1 ? 'is-weak' : score === 2 ? 'is-fair' : 'is-good';

  return (
    <>
      <div className="mp-strength" aria-hidden="true">
        {[0, 1, 2, 3].map((index) => (
          <span key={index} className={`mp-strength__seg${index < score ? ` ${state}` : ''}`} />
        ))}
      </div>
      {/* The visual meter is aria-hidden; the assessment is announced as text so it
          is available without relying on colour, which also covers the colour-blind
          case the four coloured bars alone would fail. */}
      <div className="form-text" aria-live="polite">
        Password strength: {label}
      </div>
    </>
  );
}
