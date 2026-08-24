import { FormEvent, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { getErrorMessage } from '../../../lib/api/client';
import { Honeypot } from '../../../components/ui/Honeypot';
import { useSeo } from '../../../lib/useSeo';
import { useAuth } from '../AuthContext';
import AuthShell, { BUYER_PROOF, RoleSwitch } from '../components/AuthShell';
import AuthField, { PasswordStrength } from '../components/AuthField';

export default function RegisterPage() {
  const { registerCustomer } = useAuth();
  const navigate = useNavigate();

  useSeo({
    title: 'Create your buyer account',
    description:
      'Create a free account to post requirements, compare quotes from verified suppliers and manage orders in one place.',
    canonical: '/register',
  });

  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [error, setError] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);

  const validate = (): boolean => {
    const problems: Record<string, string> = {};

    if (!name.trim()) {
      problems.name = 'Please enter your name.';
    } else if (name.trim().length < 2) {
      problems.name = 'Please enter your full name.';
    }

    // Deliberately permissive. The server decides whether an address is usable; a
    // strict client-side pattern mostly rejects valid addresses.
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email.trim())) {
      problems.email = 'Please enter a valid email address.';
    }

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
      await registerCustomer(name, email, password, passwordConfirmation);
      // Straight to the dashboard, not the homepage. Someone who has just signed up
      // as a buyer came here to do something; the homepage is a dead end for them.
      navigate('/dashboard');
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <AuthShell
      eyebrow="Free to join"
      title="Create your buyer account"
      subtitle="Post what you need and let verified suppliers come to you."
      quote={BUYER_PROOF.quote}
      stats={BUYER_PROOF.stats}
      steps={BUYER_PROOF.steps}
    >
      <RoleSwitch active="buyer" />

      {error && (
        <div className="alert alert-danger" role="alert" aria-live="assertive">
          {error}
        </div>
      )}

      <form onSubmit={handleSubmit} noValidate>
        <Honeypot />

        <AuthField
          label="Your name"
          value={name}
          onChange={setName}
          autoComplete="name"
          required
          error={fieldErrors.name}
          placeholder="Priya Iyer"
        />

        <AuthField
          label="Work email"
          type="email"
          value={email}
          onChange={setEmail}
          autoComplete="email"
          required
          error={fieldErrors.email}
          placeholder="you@company.com"
        />

        <div>
          <AuthField
            label="Password"
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
            label="Confirm password"
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
          {submitting ? 'Creating account…' : 'Create account'}
        </button>
      </form>

      <p className="mp-auth__foot mb-0">
        Already have an account? <Link to="/login">Sign in</Link>
      </p>
    </AuthShell>
  );
}
