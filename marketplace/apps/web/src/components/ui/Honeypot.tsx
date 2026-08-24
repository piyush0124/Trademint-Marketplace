import { HONEYPOT_FIELD } from '../../lib/antiSpam';

/**
 * The honeypot input, hidden from humans and from assistive technology.
 *
 * ---------------------------------------------------------------------------
 * WHY NOT `display: none`
 * ---------------------------------------------------------------------------
 * Because it is the first thing a bot author checks. A field that is
 * `display: none` or `type="hidden"` is trivially detected and skipped, which
 * turns the trap into dead markup.
 *
 * Instead the field is positioned off-screen while remaining, as far as the DOM
 * is concerned, an ordinary visible text input. A script that fills in every
 * input it can see fills this one.
 *
 * ---------------------------------------------------------------------------
 * WHY THE ACCESSIBILITY ATTRIBUTES ARE NOT OPTIONAL
 * ---------------------------------------------------------------------------
 * Off-screen positioning hides a field from sighted users but *not* from a screen
 * reader, which would otherwise announce an unlabelled mystery input in the
 * middle of the form, and a keyboard user would tab into something they cannot
 * see. Both are real failures, and both are why so many honeypots are quietly
 * inaccessible.
 *
 *   - `aria-hidden` takes it out of the accessibility tree entirely.
 *   - `tabIndex={-1}` takes it out of the tab order.
 *   - `autoComplete="off"` stops the browser helpfully filling it in — which
 *     would otherwise lock a real user out of the form with no explanation.
 *
 * The last one matters most in practice: a password manager or aggressive
 * autofill treating this as an address field is the single most likely cause of
 * a false positive, and it is why the field is named `contact_reference` rather
 * than anything resembling a real autofill token like `address` or `company`.
 */
export function Honeypot() {
  return (
    <div
      aria-hidden="true"
      style={{
        position: 'absolute',
        width: '1px',
        height: '1px',
        overflow: 'hidden',
        clip: 'rect(0 0 0 0)',
        whiteSpace: 'nowrap',
        border: 0,
        padding: 0,
        margin: '-1px',
      }}
    >
      <label htmlFor={HONEYPOT_FIELD}>Contact reference (leave blank)</label>
      <input
        id={HONEYPOT_FIELD}
        name={HONEYPOT_FIELD}
        type="text"
        tabIndex={-1}
        autoComplete="off"
        defaultValue=""
      />
    </div>
  );
}
