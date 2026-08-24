import { FormEvent, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { getErrorMessage } from '../../../lib/api/client';
import { Honeypot } from '../../../components/ui/Honeypot';
import { useSeo } from '../../../lib/useSeo';
import { useAuth } from '../AuthContext';
import AuthShell, { RoleSwitch, SELLER_PROOF } from '../components/AuthShell';
import AuthField, { PasswordStrength } from '../components/AuthField';

/**
 * Seller sign-up, split across two steps.
 *
 * ---------------------------------------------------------------------------
 * WHY TWO STEPS AND NOT ONE LONG FORM
 * ---------------------------------------------------------------------------
 * This flow needs eleven fields — account, business and location — and the
 * previous version put all eleven on one screen. That is the highest-abandonment
 * shape a sign-up form can take: the visitor's first impression of becoming a
 * supplier is a wall of inputs, and they cannot tell how much of it is mandatory.
 *
 * Splitting it does not reduce the work, and pretending otherwise would be a
 * trick. What it does is make the first step short enough to start, and show that
 * there are exactly two steps rather than an unknown amount of scrolling.
 * Committing to step one is what makes finishing step two likely.
 *
 * No field was removed and none was made optional that was not already optional —
 * the payload sent to `registerOwner` is identical to before.
 *
 * ---------------------------------------------------------------------------
 * VALIDATION BOUNDARY
 * ---------------------------------------------------------------------------
 * Step one is validated before step two is shown. Deferring all validation to the
 * final submit would send a user back two screens to fix a typo in their email,
 * which is worse than the single long form this replaced.
 */
export default function RegisterOwnerPage() {
  const { registerOwner } = useAuth();
  const navigate = useNavigate();

  useSeo({
    title: 'List your business',
    description:
      'List your business free, get verified, and receive requirements matched to what you actually do. Quote, negotiate and get paid in one place.',
    canonical: '/register/owner',
  });

  const [step, setStep] = useState<1 | 2>(1);

  // --- Account ---
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [phone, setPhone] = useState('');

  // --- Business ---
  const [businessName, setBusinessName] = useState('');
  const [businessDescription, setBusinessDescription] = useState('');

  // --- Location ---
  const [state, setState] = useState('');
  const [city, setCity] = useState('');
  const [locality, setLocality] = useState('');
  const [pincode, setPincode] = useState('');

  const [error, setError] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);

  const validateStepOne = (): boolean => {
    const problems: Record<string, string> = {};

    if (!name.trim()) {
      problems.name = 'Please enter your name.';
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email.trim())) {
      problems.email = 'Please enter a valid email address.';
    }

    /*
     * Phone is optional here because it is optional on the server, and the two must
     * agree — a field the client insists on but the API does not is a rule nobody
     * can find later. When present it is checked loosely: exactly ten digits after
     * stripping formatting, which is every Indian mobile number and rejects an
     * obvious typo without refusing a number written with spaces or a +91 prefix.
     */
    const digits = phone.replace(/\D/g, '');
    if (phone.trim() && digits.replace(/^91/, '').length !== 10) {
      problems.phone = 'Enter a 10-digit mobile number.';
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

  const validateStepTwo = (): boolean => {
    const problems: Record<string, string> = {};

    if (!businessName.trim()) {
      problems.businessName = 'Enter your registered or trading name.';
    }

    if (!city.trim()) {
      problems.city = 'Enter the city you operate from.';
    }

    if (pincode.trim() && !/^\d{6}$/.test(pincode.trim())) {
      problems.pincode = 'A PIN code is 6 digits.';
    }

    setFieldErrors(problems);

    return Object.keys(problems).length === 0;
  };

  const goToStepTwo = () => {
    setError('');

    if (validateStepOne()) {
      setFieldErrors({});
      setStep(2);
      // Return the viewport to the top of the form; otherwise a user who scrolled
      // down step one lands mid-way through step two.
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  };

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();

    if (submitting) return;

    setError('');

    if (!validateStepTwo()) return;

    setSubmitting(true);

    try {
      await registerOwner({
        name,
        email,
        password,
        passwordConfirmation,
        phone,
        businessName,
        businessDescription,
        country: 'IN',
        state,
        city,
        locality,
        pincode,
      });
      navigate('/dashboard');
    } catch (err) {
      /*
       * A server-side failure on step two may well concern a step-one field — a
       * duplicate email is the common case, and it can only be detected here. Send
       * the user back to step one so the field they need is on screen, rather than
       * showing "Email already registered" above a location form.
       */
      const message = getErrorMessage(err);
      setError(message);

      if (/email/i.test(message)) {
        setFieldErrors({ email: message });
        setStep(1);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <AuthShell
      eyebrow="Free to list"
      title="List your business"
      subtitle="Get matched to requirements in your category, then quote and get paid here."
      quote={SELLER_PROOF.quote}
      stats={SELLER_PROOF.stats}
      steps={SELLER_PROOF.steps}
    >
      <RoleSwitch active="seller" />

      <div className="mp-auth__progress">
        <div className="mp-auth__progress-bar">
          {/* role/aria on the wrapper would be redundant with the text label beside
              it, which is already announced. */}
          <span style={{ width: step === 1 ? '50%' : '100%' }} />
        </div>
        <span className="mp-auth__progress-label" aria-live="polite">
          Step {step} of 2
        </span>
      </div>

      {error && (
        <div className="alert alert-danger" role="alert" aria-live="assertive">
          {error}
        </div>
      )}

      <form onSubmit={handleSubmit} noValidate>
        <Honeypot />

        {step === 1 ? (
          <>
            <p className="mp-auth__legend">Your account</p>

            <AuthField
              label="Full name"
              value={name}
              onChange={setName}
              autoComplete="name"
              required
              error={fieldErrors.name}
              placeholder="Rohit Menon"
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

            <AuthField
              label="Phone"
              type="tel"
              value={phone}
              onChange={setPhone}
              autoComplete="tel"
              error={fieldErrors.phone}
              hint="Optional. Buyers see this only after you accept an enquiry."
              placeholder="98765 43210"
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

            {/*
              * type="button" is essential. A default submit button here would post the
              * form from step one with the business fields empty.
              */}
            <button type="button" className="btn btn-mp-primary w-100 mt-2" onClick={goToStepTwo}>
              Continue
            </button>
          </>
        ) : (
          <>
            <p className="mp-auth__legend">Your business</p>

            <AuthField
              label="Business name"
              value={businessName}
              onChange={setBusinessName}
              autoComplete="organization"
              required
              error={fieldErrors.businessName}
              placeholder="NorthStar Infra Projects"
            />

            <div className="mp-form-field mb-3">
              <label htmlFor="owner-about">What does your business do?</label>
              <textarea
                id="owner-about"
                className="form-control"
                rows={3}
                value={businessDescription}
                onChange={(event) => setBusinessDescription(event.target.value)}
                placeholder="Civil and interior contracting for commercial sites, with in-house supervision."
                aria-describedby="owner-about-hint"
              />
              <div id="owner-about-hint" className="form-text">
                Optional now — you can complete your profile later. Buyers see this on your listing.
              </div>
            </div>

            <p className="mp-auth__legend">Where you operate</p>

            <div className="mp-auth__row">
              <AuthField
                label="City"
                value={city}
                onChange={setCity}
                autoComplete="address-level2"
                required
                error={fieldErrors.city}
                placeholder="Ghaziabad"
              />
              <AuthField
                label="State"
                value={state}
                onChange={setState}
                autoComplete="address-level1"
                placeholder="Uttar Pradesh"
              />
            </div>

            <div className="mp-auth__row">
              <AuthField
                label="Locality"
                value={locality}
                onChange={setLocality}
                autoComplete="address-level3"
                placeholder="Indirapuram"
              />
              <AuthField
                label="PIN code"
                value={pincode}
                onChange={setPincode}
                autoComplete="postal-code"
                error={fieldErrors.pincode}
                placeholder="201014"
              />
            </div>

            <div className="d-grid gap-2 mt-2">
              <button type="submit" className="btn btn-mp-primary" disabled={submitting}>
                {submitting ? 'Creating account…' : 'Create business account'}
              </button>
              <button
                type="button"
                className="btn btn-mp-ghost"
                onClick={() => {
                  setError('');
                  setFieldErrors({});
                  setStep(1);
                }}
              >
                Back
              </button>
            </div>
          </>
        )}
      </form>

      <p className="mp-auth__foot mb-0">
        Already have an account? <Link to="/login">Sign in</Link>
      </p>
    </AuthShell>
  );
}
