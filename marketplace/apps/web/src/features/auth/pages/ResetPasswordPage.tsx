import { FormEvent, useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { api, getErrorMessage } from '../../../lib/api/client';
import { Honeypot } from '../../../components/ui/Honeypot';
import Icon from '../../../components/ui/Icon';
import { useSeo } from '../../../lib/useSeo';
import AuthShell, { BUYER_PROOF } from '../components/AuthShell';
import AuthField, { PasswordStrength } from '../components/AuthField';

/**
 * Choose a new password, using the token from the emailed link.
 *
 * ---------------------------------------------------------------------------
 * WHY THE TOKEN IS CHECKED BEFORE THE FORM IS SHOWN
 * ---------------------------------------------------------------------------
 * Reset links expire in 60 minutes and are single-use, so arriving with a dead one
 * is common — the user opened the email the next morning, or already used it.
 *
 * Without a pre-flight check, that user chooses a password, confirms it, submits,
 * and *then* learns the link was dead. They have done the work twice and the error
 * appears to blame the password. Verifying on mount lets us show the real problem,
 * with the real remedy, before asking for anything.
 *
 * The check reveals nothing: the caller already holds both the token and the email.
 *
 * ---------------------------------------------------------------------------
 * WHY IT REDIRECTS TO SIGN IN RATHER THAN LOGGING THE USER IN
 * ---------------------------------------------------------------------------
 * The reset revokes every API token for the account, which is the correct response
 * to what is usually a suspected compromise. Auto-signing-in afterwards would
 * immediately mint a new token and quietly undo the "you are signed out
 * everywhere" guarantee. Making the user sign in once with the new password also
 * confirms to them that it works.
 */
export default function ResetPasswordPage() {
  const [params] = useSearchParams();
  const navigate = useNavigate();

  const token = params.get('token') ?? '';
  const email = params.get('email') ?? '';

  useSeo({ title: 'Choose a new password', canonical: '/reset-password', noIndex: true });

  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [done, setDone] = useState(false);
  const [tokenState, setTokenState] = useState<'checking' | 'valid' | 'invalid'>('checking');

  useEffect(() => {
    if (!token || !email) {
      setTokenState('invalid');

      return;
    }

    let cancelled = false;

    api
      .post('/auth/reset-password/verify', { email, token })
      .then((response) => {
        if (!cancelled) {
          setTokenState(response.data?.data?.valid ? 'valid' : 'invalid');
        }
      })
      .catch(() => {
        /*
         * Fail open to 'valid' rather than 'invalid'.
         *
         * If the pre-flight itself fails — offline, throttled, server hiccup — the
         * token may well be fine. Showing "your link is invalid" would send the user
         * to request a new one they do not need, and the real submit below is still
         * authoritative. A convenience check must not be able to block a valid reset.
         */
        if (!cancelled) setTokenState('valid');
      });

    return () => {
      cancelled = true;
    };
  }, [token, email]);

  const validate = (): boolean => {
    const problems: Record<string, string> = {};

    if (password.length < 8) {
      problems.password = 'Use at least 8 characters.';
    }

    if (password !== passwordConfirmation) {
      problems.passwordConfirmation = 'Both passwords must match.';
    }

    setFieldErrors(problems);

    return Object.keys(problems).length === 0;
  };

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();

    if (submitting) return;

    setError('');

    if (!validate()) return;

    setSubmitting(true);

    try {
      await api.post('/auth/reset-password', {
        token,
        email,
        password,
        password_confirmation: passwordConfirmation,
      });

      setDone(true);
      // Long enough to read the confirmation, short enough not to feel stuck.
      setTimeout(() => navigate('/login', { replace: true }), 2200);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setSubmitting(false);
    }
  };

  const shell = {
    quote: BUYER_PROOF.quote,
    stats: BUYER_PROOF.stats,
    steps: BUYER_PROOF.steps,
  };

  if (tokenState === 'checking') {
    return (
      <AuthShell eyebrow="Account recovery" title="Checking your link" subtitle="One moment." {...shell}>
        <div role="status" aria-live="polite">
          <span className="mp-sr-only">Verifying your reset link…</span>
          <div className="mp-skeleton mp-skeleton--text mb-2" />
          <div className="mp-skeleton mp-skeleton--text" style={{ width: '62%' }} />
        </div>
      </AuthShell>
    );
  }

  if (tokenState === 'invalid') {
    return (
      <AuthShell
        eyebrow="Account recovery"
        title="This link has expired"
        subtitle="Reset links last 60 minutes and can only be used once."
        {...shell}
      >
        <div className="mp-notice mp-notice--warning" role="alert">
          <Icon name="lock" size={18} />
          <div>
            <strong>We could not use that link.</strong>
            <p className="mb-0 mp-text-sm">
              It may have expired, or it may already have been used. Requesting a new one takes a moment.
            </p>
          </div>
        </div>

        <div className="d-grid gap-2 mt-4">
          <Link to="/forgot-password" className="btn btn-mp-primary">
            Request a new link
          </Link>
          <Link to="/login" className="btn btn-mp-ghost">
            Back to sign in
          </Link>
        </div>
      </AuthShell>
    );
  }

  if (done) {
    return (
      <AuthShell
        eyebrow="Account recovery"
        title="Password updated"
        subtitle="You can sign in with your new password now."
        {...shell}
      >
        <div className="mp-notice mp-notice--success" role="status" aria-live="polite">
          <Icon name="check" size={18} />
          <div>
            <strong>Your password has been changed.</strong>
            <p className="mb-0 mp-text-sm">
              For your security you have been signed out on every other device. Taking you to sign in…
            </p>
          </div>
        </div>

        <Link to="/login" className="btn btn-mp-primary w-100 mt-4">
          Sign in now
        </Link>
      </AuthShell>
    );
  }

  return (
    <AuthShell
      eyebrow="Account recovery"
      title="Choose a new password"
      subtitle={`For ${email}`}
      {...shell}
    >
      {error && (
        <div className="alert alert-danger" role="alert" aria-live="assertive">
          {error}
        </div>
      )}

      <form onSubmit={handleSubmit} noValidate>
        <Honeypot />

        {/*
          * The email is fixed by the link and shown read-only in the subtitle rather
          * than as an editable field. Letting it be edited would mean the token no
          * longer matches the account and every submission fails with "invalid link".
          */}
        <div>
          <AuthField
            label="New password"
            type="password"
            value={password}
            onChange={setPassword}
            autoComplete="new-password"
            required
            revealable
            error={fieldErrors.password}
            hint="At least 8 characters."
          />
          <PasswordStrength password={password} />
        </div>

        <div className="mt-3">
          <AuthField
            label="Confirm new password"
            type="password"
            value={passwordConfirmation}
            onChange={setPasswordConfirmation}
            autoComplete="new-password"
            required
            revealable
            error={fieldErrors.passwordConfirmation}
          />
        </div>

        <button type="submit" className="btn btn-mp-primary w-100 mt-2" disabled={submitting}>
          {submitting ? 'Updating password…' : 'Update password'}
        </button>
      </form>

      <p className="mp-auth__foot mb-0">
        Changed your mind? <Link to="/login">Back to sign in</Link>
      </p>
    </AuthShell>
  );
}
