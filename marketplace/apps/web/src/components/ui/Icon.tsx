/**
 * Inline SVG icon set.
 *
 * ---------------------------------------------------------------------------
 * WHY NOT EMOJI
 * ---------------------------------------------------------------------------
 * The previous pages used emoji as icons. Three problems, in order of severity:
 *
 * 1. They render as a different picture on every platform — a colourful cartoon
 *    on one OS, a flat monochrome glyph on another — so the page cannot have a
 *    consistent visual identity. On a B2B product asking to be trusted with
 *    payments, an emoji shield is the single cheapest-looking element on screen.
 *
 * 2. They cannot be styled. Size, weight and colour are decided by the font, so
 *    an emoji never matches the text beside it and never respects the palette.
 *
 * 3. They are non-ASCII, which makes every file containing them fragile to
 *    tooling. That is not theoretical: an encoding slip while editing imports
 *    corrupted the emoji in six page files in this codebase, and the placeholder
 *    boxes shipped in the markup. An inline SVG is plain ASCII and cannot break
 *    that way.
 *
 * ---------------------------------------------------------------------------
 * WHY NOT AN ICON LIBRARY
 * ---------------------------------------------------------------------------
 * The app needs roughly a dozen icons. Adding a dependency for that costs a
 * package, a bundle entry and a version to keep current, to replace this file.
 *
 * ---------------------------------------------------------------------------
 * ACCESSIBILITY
 * ---------------------------------------------------------------------------
 * `aria-hidden` by default, because an icon beside a text label is decoration and
 * announcing it duplicates the label. Pass a `title` only when the icon carries
 * meaning no adjacent text conveys — then it becomes `role="img"` with an
 * accessible name.
 *
 * All paths are drawn on a 24x24 grid with `currentColor` and a 1.6 stroke, so an
 * icon inherits the colour and optical weight of its context.
 */

export type IconName =
  | 'shield'
  | 'calculator'
  | 'star'
  | 'scale'
  | 'ledger'
  | 'map'
  | 'search'
  | 'check'
  | 'arrow-right'
  | 'building'
  | 'spark'
  | 'lock'
  | 'heart'
  | 'receipt'
  | 'inbox'
  | 'chat'
  | 'package'
  | 'card'
  | 'chart'
  | 'clipboard'
  | 'handshake'
  | 'compass';

const PATHS: Record<IconName, JSX.Element> = {
  // Verification — a shield with a tick.
  shield: (
    <>
      <path d="M12 3 5 6v5.5c0 4.3 2.9 8.2 7 9.5 4.1-1.3 7-5.2 7-9.5V6l-7-3Z" />
      <path d="m9 12 2.2 2.2L15.5 10" />
    </>
  ),
  // Server-side money — a calculator.
  calculator: (
    <>
      <rect x="5" y="3" width="14" height="18" rx="2" />
      <path d="M8 7h8M8.5 12h.01M12 12h.01M15.5 12h.01M8.5 16h.01M12 16h.01M15.5 16h3" />
    </>
  ),
  star: <path d="m12 4 2.5 5.2 5.5.8-4 3.9 1 5.6-5-2.7-5 2.7 1-5.6-4-3.9 5.5-.8L12 4Z" />,
  // Disputes — a balance scale.
  scale: (
    <>
      <path d="M12 4v16M7 20h10M5 8h14M5 8l-2.5 5.5h5L5 8Zm14 0-2.5 5.5h5L19 8Z" />
    </>
  ),
  // Audit trail — stacked records.
  ledger: (
    <>
      <rect x="4" y="4" width="16" height="16" rx="2" />
      <path d="M8 9h8M8 13h8M8 17h5" />
    </>
  ),
  map: (
    <>
      <path d="M12 21s-6.5-5.5-6.5-10.5A6.5 6.5 0 0 1 12 4a6.5 6.5 0 0 1 6.5 6.5C18.5 15.5 12 21 12 21Z" />
      <circle cx="12" cy="10.5" r="2.4" />
    </>
  ),
  search: (
    <>
      <circle cx="11" cy="11" r="6.5" />
      <path d="m16 16 4.5 4.5" />
    </>
  ),
  check: <path d="m5 12.5 4.5 4.5L19 7.5" />,
  'arrow-right': <path d="M5 12h13m0 0-5.5-5.5M18 12l-5.5 5.5" />,
  building: (
    <>
      <path d="M4 21V6a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v15M14 10h5a1 1 0 0 1 1 1v10M4 21h17" />
      <path d="M7.5 9h3M7.5 13h3M7.5 17h3M17 14h.01M17 17.5h.01" />
    </>
  ),
  spark: <path d="M12 3v4m0 10v4M3 12h4m10 0h4M5.6 5.6l2.8 2.8m7.2 7.2 2.8 2.8m0-12.8-2.8 2.8m-7.2 7.2-2.8 2.8" />,
  lock: (
    <>
      <rect x="5" y="11" width="14" height="10" rx="2" />
      <path d="M8.5 11V8a3.5 3.5 0 0 1 7 0v3" />
    </>
  ),
  // Favourites.
  heart: <path d="M12 20s-7-4.4-7-9.2A4 4 0 0 1 12 8a4 4 0 0 1 7 2.8C19 15.6 12 20 12 20Z" />,
  // Quotes — a document with a torn edge and line items.
  receipt: (
    <>
      <path d="M6 3h12v18l-3-1.6-3 1.6-3-1.6L6 21V3Z" />
      <path d="M9.5 8h5M9.5 12h5M9.5 16h3" />
    </>
  ),
  // Leads and matched requirements.
  inbox: (
    <>
      <path d="M4 13h4l1.5 2.5h5L16 13h4" />
      <path d="M4 13 6.5 5h11L20 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-5Z" />
    </>
  ),
  chat: <path d="M20 12c0 3.9-3.6 7-8 7a9 9 0 0 1-2.6-.4L5 20l1.2-3.2A6.6 6.6 0 0 1 4 12c0-3.9 3.6-7 8-7s8 3.1 8 7Z" />,
  // Orders.
  package: (
    <>
      <path d="M20 8.5v7a1.5 1.5 0 0 1-.8 1.3l-6.5 3.4a1.5 1.5 0 0 1-1.4 0L4.8 16.8A1.5 1.5 0 0 1 4 15.5v-7" />
      <path d="m4 8.5 7.3-3.8a1.5 1.5 0 0 1 1.4 0L20 8.5l-8 4.2-8-4.2Z" />
      <path d="M12 12.7V20" />
    </>
  ),
  card: (
    <>
      <rect x="3" y="6" width="18" height="12" rx="2" />
      <path d="M3 10.5h18M6.5 14.5h3" />
    </>
  ),
  // Quote comparison.
  chart: <path d="M5 20V10m4.7 10V5m4.6 15v-7m4.7 7V8" />,
  clipboard: (
    <>
      <path d="M9 4h6v2.5H9V4Z" />
      <path d="M9 5H7a1 1 0 0 0-1 1v13a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1h-2" />
      <path d="M9 11h6M9 15h4" />
    </>
  ),
  // Negotiations and disputes. Two arrows meeting — a handshake at this size is
  // an unreadable smudge, and two converging arrows says "agreement" more clearly.
  handshake: <path d="M3 9h6l2 2 2-2h6M3 9v6h5m13-6v6h-5m-6 0 2.5 2.5L16 15" />,
  compass: (
    <>
      <circle cx="12" cy="12" r="8.5" />
      <path d="m14.8 9.2-1.6 4.2-4.2 1.6 1.6-4.2 4.2-1.6Z" />
    </>
  ),
};

interface IconProps {
  name: IconName;
  /** Pixel size. Defaults to 1em so the icon tracks the surrounding font size. */
  size?: number | string;
  /** Supply only when the icon is the sole carrier of meaning. */
  title?: string;
  className?: string;
  strokeWidth?: number;
}

export default function Icon({ name, size = '1em', title, className, strokeWidth = 1.6 }: IconProps) {
  return (
    <svg
      className={className}
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={strokeWidth}
      strokeLinecap="round"
      strokeLinejoin="round"
      // Decoration unless named. A titled icon is exposed as an image with that
      // name; an untitled one is skipped entirely by assistive technology.
      aria-hidden={title ? undefined : true}
      role={title ? 'img' : undefined}
      focusable="false"
    >
      {title && <title>{title}</title>}
      {PATHS[name]}
    </svg>
  );
}
