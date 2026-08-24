import { FormEvent, useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router-dom';
import { getErrorMessage } from '../../../lib/api/client';
import { Honeypot } from '../../../components/ui/Honeypot';
import { useSeo } from '../../../lib/useSeo';
import { useAuth } from '../AuthContext';
import AuthShell, { BUYER_PROOF } from '../components/AuthShell';
import AuthField from '../components/AuthField';

export default function LoginPage() {
  const { login, isAuthenticated, isLoading } = useAuth();
  const navigate = useNavigate();

  useSeo({
    title: 'Sign in',
    description: 'Sign in to manage your requirements, quotes, orders and payments.',
    canonical: '/login',
    // A sign-in form has nothing to rank for, and indexing it invites credential
    // -stuffing traffic from search rather than users.
    noIndex: true,
  });

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);

  if (isLoading) {
    return (
      <div className="mp-container py-5" role="status" aria-live="polite">
        <span className="mp-sr-only">Checking your session…</span>
        <div className="mp-skeleton mp-skeleton--title mb-3" />
        <div className="mp-skeleton mp-skeleton--text" />
      </div>
    );
  }

  if (isAuthenticated) {
    return <Navigate to="/dashboard" replace />;
  }

  const validate = (): boolean => {
    const problems: Record<string, string> = {};

    if (!email.trim()) {
      problems.email = 'Enter the email you signed up with.';
    }

    if (!password) {
      problems.password = 'Enter your password.';
    }

    setFieldErrors(problems);

    return Object.keys(problems).length === 0;
  };

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();

    // Beats the disabled attribute: a second Enter keypress can fire before React
    // has re-rendered the button.
    if (submitting) return;

    setError('');

    if (!validate()) return;

    setSubmitting(true);

    try {
      const user = await login(email, password);
      const role = user.attributes.role;
      navigate(role === 'admin' ? '/admin' : '/dashboard');
    } catch (err) {
      /*
       * One neutral message for every failure, and no field-level error.
       *
       * Distinguishing "no such account" from "wrong password" hands an attacker a
       * free account-enumeration oracle: they learn which addresses are registered
       * without ever guessing a password. The backend already returns a neutral
       * error for this reason (see AuthTest::test_invalid_login_returns_neutral_error);
       * surfacing it against the email field would undo that on the client.
       */
      setError(getErrorMessage(err));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <AuthShell
      eyebrow="Welcome back"
      title="Sign in to your account"
      subtitle="Pick up where you left off — your requirements, quotes and orders."
      quote={BUYER_PROOF.quote}
      stats={BUYER_PROOF.stats}
      steps={BUYER_PROOF.steps}
    >
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
          error={fieldErrors.email}
          placeholder="you@company.com"
        />

        <AuthField
          label="Password"
          type="password"
          value={password}
          onChange={setPassword}
          autoComplete="current-password"
          required
          revealable
          error={fieldErrors.password}
        />

        {/*
          * Placed directly beneath the password field, before the submit button.
          *
          * The conventional spot is at the very bottom of the page under the sign-up
          * link, which is exactly where a user who has just failed to sign in is not
          * looking. Putting it next to the field that failed is what makes it findable
          * at the moment it is needed.
          */}
        <div className="d-flex justify-content-end mb-3" style={{ marginTop: 'calc(-1 * var(--mp-space-2))' }}>
          <Link to="/forgot-password" className="mp-text-caption">
            Forgot your password?
          </Link>
        </div>

        <button type="submit" className="btn btn-mp-primary w-100" disabled={submitting}>
          {submitting ? 'Signing in…' : 'Sign in'}
        </button>
      </form>

      <div className="mp-auth__divider">New here</div>

      <div className="d-grid gap-2">
        <Link to="/register" className="btn btn-mp-outline">
          Create a buyer account
        </Link>
        <Link to="/register/owner" className="btn btn-mp-ghost">
          List your business instead
        </Link>
      </div>
    </AuthShell>
  );
}
