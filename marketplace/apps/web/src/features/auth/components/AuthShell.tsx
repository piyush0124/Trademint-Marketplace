import { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { BRAND } from '../../../config/brand';

export interface ProofStat {
  value: string;
  label: string;
}

export interface ProofStep {
  title: string;
  body: string;
}

interface AuthShellProps {
  eyebrow: string;
  title: string;
  subtitle: string;
  /** The right-hand panel headline. Written as a plain claim, not a testimonial. */
  quote: string;
  stats: ProofStat[];
  steps: ProofStep[];
  children: ReactNode;
}

/**
 * Split-screen shell for every auth screen.
 *
 * ---------------------------------------------------------------------------
 * WHY THE RIGHT PANEL EXISTS
 * ---------------------------------------------------------------------------
 * Sign-up is where a visitor has the least reason to trust the product and the
 * most reason to leave. A form alone answers none of the questions they are
 * actually asking: who else is here, what happens after I submit, is my data safe.
 *
 * It deliberately contains no photography and no testimonials. Invented quotes
 * from invented companies occupy the same space and earn none of the same trust —
 * and on a marketplace whose entire value proposition is verification, faking
 * social proof on the sign-up page is the wrong first impression. What is there is
 * the real process and honest counts.
 *
 * ---------------------------------------------------------------------------
 * RESPONSIVE BEHAVIOUR
 * ---------------------------------------------------------------------------
 * The panel is display:none below lg rather than stacked beneath the form.
 * Stacking would push the actual task below a screen of marketing on a phone,
 * which is how a responsive auth page ends up worse on mobile than on desktop.
 * A condensed trust strip appears under the form instead.
 *
 * Because it is decorative-but-informative rather than essential, and the same
 * facts are represented by the strip on small screens, hiding it costs no
 * information.
 */
export default function AuthShell({
  eyebrow,
  title,
  subtitle,
  quote,
  stats,
  steps,
  children,
}: AuthShellProps) {
  return (
    <div className="mp-auth">
      <div className="mp-auth__form-col">
        <div className="mp-auth__form">
          <span className="mp-auth__eyebrow">{eyebrow}</span>
          <h1 className="mp-auth__title">{title}</h1>
          <p className="mp-auth__subtitle">{subtitle}</p>

          {children}

          {/*
            * Shown only below lg, where the proof panel is hidden. Carries the same
            * three assurances in one line so a mobile user is not asked to trust the
            * form with strictly less context than a desktop user.
            */}
          <div className="mp-auth__trust-strip">
            <span>Verified businesses</span>
            <span>No charge to post</span>
            <span>Your details stay private</span>
          </div>
        </div>
      </div>

      {/*
        * aria-hidden: every fact here is either repeated in the trust strip or is
        * marketing context, and the panel sits *after* the form in the DOM so a
        * screen-reader user reaches the task first. Announcing a decorative stats
        * panel before or after the form adds nothing they can act on.
        */}
      <aside className="mp-auth__proof" aria-hidden="true">
        <div className="mp-auth__proof-inner">
          <p className="mp-auth__quote">{quote}</p>

          <div className="mp-auth__stats">
            {stats.map((stat) => (
              <div key={stat.label}>
                <div className="mp-auth__stat-value">{stat.value}</div>
                <div className="mp-auth__stat-label">{stat.label}</div>
              </div>
            ))}
          </div>

          <div className="mp-auth__steps">
            {steps.map((step, index) => (
              <div className="mp-auth__step" key={step.title}>
                <span className="mp-auth__step-num">{index + 1}</span>
                <span className="mp-auth__step-text">
                  <strong>{step.title}</strong>
                  {step.body}
                </span>
              </div>
            ))}
          </div>
        </div>
      </aside>
    </div>
  );
}

/** Buyer / seller switch, shown on both sign-up screens. */
export function RoleSwitch({ active }: { active: 'buyer' | 'seller' }) {
  return (
    /*
     * A marketplace has two entirely different sign-up intents, and the usual
     * treatment — one form with the other offered as a text link at the bottom —
     * routes buyers into the seller flow and back out again. Making the choice the
     * first thing on the page costs one row and removes that whole detour.
     */
    <div className="mp-auth__switch" role="group" aria-label="Account type">
      <Link to="/register" className={active === 'buyer' ? 'is-active' : ''} aria-current={active === 'buyer' ? 'page' : undefined}>
        I&apos;m buying
        <small>Post needs, get quotes</small>
      </Link>
      <Link to="/register/owner" className={active === 'seller' ? 'is-active' : ''} aria-current={active === 'seller' ? 'page' : undefined}>
        I&apos;m selling
        <small>List your business</small>
      </Link>
    </div>
  );
}

export const BUYER_PROOF = {
  quote: `Tell ${BRAND.name} what your business needs. Verified suppliers come to you with real quotes.`,
  stats: [
    { value: '200+', label: 'Live listings' },
    { value: '20', label: 'Cities' },
    { value: '12', label: 'Industries' },
  ] satisfies ProofStat[],
  steps: [
    { title: 'Describe what you need', body: 'One form. Quantity, budget, timeline, city.' },
    { title: 'Suppliers are matched to you', body: 'Scored on category, location and capability.' },
    { title: 'Compare, negotiate, order', body: 'Quotes side by side, then pay in one place.' },
  ] satisfies ProofStep[],
};

export const SELLER_PROOF = {
  quote: 'Buyers are posting requirements in your category right now. Get matched and quote for the ones worth your time.',
  stats: [
    { value: 'Free', label: 'To list' },
    { value: '20', label: 'Cities' },
    { value: '12', label: 'Industries' },
  ] satisfies ProofStat[],
  steps: [
    { title: 'List your business', body: 'Category, service areas, products and services.' },
    { title: 'Get verified', body: 'A verified badge is what buyers filter on.' },
    { title: 'Receive matched requirements', body: 'Quote, negotiate and get paid on the platform.' },
  ] satisfies ProofStep[],
};
