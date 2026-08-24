/**
 * Brand identity, in one place.
 *
 * The app was originally scaffolded as a real-estate portal ("Ghar360 — Real Estate
 * in Kolkata"). It is now a general-purpose business operating marketplace, and the
 * naming has to say so: nothing user-facing may imply a single industry or city,
 * because the data model deliberately doesn't.
 *
 * Change `name` here and it changes everywhere — navbar, footer, page title, meta
 * description, auth screens.
 */
export const BRAND = {
  name: 'Trademint',
  /** Rendered in a contrasting colour after `namePrefix`. Keep both halves short. */
  namePrefix: 'Trade',
  nameSuffix: 'mint',
  tagline: 'India’s business marketplace',
  /** One sentence. Used in the hero sub-head and the meta description. */
  promise:
    'Post what your business needs. Get real quotes from verified suppliers. Negotiate, order and pay in one place.',
  supportEmail: 'support@trademint.test',
} as const;

/**
 * Icons for top-level categories, keyed by the seeded slug.
 *
 * Not a category list — the categories themselves come from the API, and any slug
 * missing here simply falls back to a neutral glyph. Adding an industry to the
 * database therefore needs no frontend change; adding an icon for it is optional
 * polish.
 */
const CATEGORY_ICONS: Record<string, string> = {
  real_estate: '🏢',
  construction: '🏗️',
  manufacturing: '🏭',
  it_software: '💻',
  logistics: '🚚',
  professional_services: '📊',
  home_services: '🔧',
  healthcare: '🩺',
  education: '🎓',
  agriculture: '🌾',
  automotive: '🚗',
  cars: '🚗',
  textiles: '🧵',
  food_beverage: '🍽️',
  electronics: '🔌',
  chemicals: '⚗️',
  energy: '⚡',
  hospitality: '🏨',
  media: '🎬',
  events: '🎪',
  security: '🛡️',
  packaging: '📦',
  printing: '🖨️',
  furniture: '🪑',
  retail: '🏪',
  finance: '💳',
  legal: '⚖️',
  marketing: '📣',
  travel: '✈️',
  sports: '🏅',
};

export function categoryIcon(slug: string): string {
  return CATEGORY_ICONS[slug] ?? '🗂️';
}
