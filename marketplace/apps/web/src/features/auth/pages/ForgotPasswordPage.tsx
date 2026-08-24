import { FormEvent, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, getErrorMessage } from '../../../lib/api/client';
import { Honeypot } from '../../../components/ui/Honeypot';
import Icon from '../../../components/ui/Icon';
import { useSeo } from '../../../lib/useSeo';
import AuthShell, { BUYER_PROOF } from '../components/AuthShell';
import AuthField from '../components/AuthField';

/**
 * Request a password reset link.
 *
 * ---------------------------------------------------------------------------
 * THE SUCCESS SCREEN IS THE IMPORTANT PART
 * ---------------------------------------------------------------------------
 * The API answers identically for a registered and an unregistered address, on
 * purpose — telling the caller which is which would let anyone test an address
 * list against the platform (see PasswordResetController).
 *
 * That means this screen cannot promise "we sent you an email", because sometimes
 * nothing was sent. It has to be honest about the conditional without sounding
 * evasive, and it has to give the user somewhere to go when no email arrives —
 * otherwise the security property just reads as a broken feature.
 *
 * Hence: "if an account exists", an explicit spam-folder prompt, and a way back
 * to try a different address.
 */
export default function ForgotPasswordPage() {
  useSeo({
    title: 'Reset your password',
    description: 'Request a link to reset your Trademint password.',
    canonical: '/forgot-password',
    // Nothing to rank for, and indexing it only attracts automated traffic.
    noIndex: true,
  });

  const [email, setEmail] = useState('');
  const [fieldError, setFieldError] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [sent, setSent] = useState(false);

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();

    if (submitting) return;

    setError('');
    setFieldError('');

    // Deliberately permissive, matching the server. A strict pattern here mostly
    // rejects valid addresses.
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email.trim())) {
      setFieldError('Please enter a valid email address.');

      return;
    }

    setSubmitting(true);

    try {
      await api.post('/auth/forgot-password', { email: email.trim() });
      setSent(true);
    } catch (err) {
      /*
       * The only failures reaching here are a 429 from the rate limiter or a
       * network error. Both are worth showing: a user who is being throttled needs
       * to know to wait rather than assume the feature is broken.
       */
      setError(getErrorMessage(err));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <AuthShell
      eyebrow="Account recovery"
      title={sent ? 'Check your inbox' : 'Reset your password'}
      subtitle={
        sent
          ? 'We have done our part. The next step is in your email.'
          : 'Enter the email you signed up with and we will send you a link to choose a new password.'
      }
      quote={BUYER_PROOF.quote}
      stats={BUYER_PROOF.stats}
      steps={BUYER_PROOF.steps}
    >
      {sent ? (
        <div>
          <div className="mp-notice mp-notice--success" role="status" aria-live="polite">
            <Icon name="check" size={18} />
            <div>
              <strong>If an account exists for {email.trim()}, a reset link is on its way.</strong>
              <p className="mb-0 mp-text-sm">
                The link expires in 60 minutes and can only be used once.
              </p>
            </div>
          </div>

          <ul className="mp-hint-list">
            <li>Not there yet? Give it a minute, then check your spam or promotions folder.</li>
            <li>Still nothing? The address may not be registered, or you may have signed up with a different one.</li>
          </ul>

          <div className="d-grid gap-2 mt-4">
            <button
              type="button"
              className="btn btn-mp-outline"
              onClick={() => {
                setSent(false);
                setEmail('');
              }}
            >
              Try a different email
            </button>
            <Link to="/login" className="btn btn-mp-ghost">
              Back to sign in
            </Link>
          </div>
        </div>
      ) : (
        <>
          {error && (
            <div className="alert alert-danger" role="alert" aria-live="assertive">
              {error}
            </div>
          )}

          <form onSubmit={handleSubmit} noValidate>
            <Honeypot />

            <AuthField
              label="Email"
              type="email"
              value={email}
              onChange={setEmail}
              autoComplete="email"
              required
              error={fieldError}
              placeholder="you@company.com"
              hint="The address you used when you created your account."
            />

            <button type="submit" className="btn btn-mp-primary w-100" disabled={submitting}>
              {submitting ? 'Sending link…' : 'Send reset link'}
            </button>
          </form>

          <p className="mp-auth__foot mb-0">
            Remembered it? <Link to="/login">Sign in</Link>
          </p>
        </>
      )}
    </AuthShell>
  );
}
