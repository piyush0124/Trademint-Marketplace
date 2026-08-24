import { FormEvent, useId, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import Icon from './Icon';

/**
 * Compact search for the navbar.
 *
 * ---------------------------------------------------------------------------
 * WHY NOT REUSE SearchBar
 * ---------------------------------------------------------------------------
 * SearchBar is the hero search: two fields, a divider and a full-width button on
 * a 12-column grid. It is the right shape for a landing page and the wrong shape
 * for a 64px navbar, and squeezing it in would either shrink the inputs below a
 * usable size or push the nav links off the row.
 *
 * It also hardcodes its input ids (`search-keyword`, `search-city`), so rendering
 * it twice on one page — hero plus navbar — would produce duplicate ids and break
 * the label associations for both. This component generates its own with useId.
 *
 * ---------------------------------------------------------------------------
 * WHY SEARCH BELONGS IN THE NAVBAR AT ALL
 * ---------------------------------------------------------------------------
 * Before this, `SearchBar` appeared on the homepage and nowhere else. Once a user
 * navigated anywhere — a listing, their dashboard, a supplier profile — there was
 * no way to search without going back to the homepage first. On a marketplace,
 * search is primary navigation, not a homepage widget.
 *
 * City is dropped here on purpose. The hero search takes both because a first-time
 * visitor is orienting; a returning user in the navbar is looking for a *thing*,
 * and the city filter is one click away on the results page. Two fields in a
 * navbar buys precision nobody asked for at the cost of the space the nav needs.
 */
export default function NavbarSearch({ compact = false }: { compact?: boolean }) {
  const navigate = useNavigate();
  const id = useId();
  const inputRef = useRef<HTMLInputElement>(null);

  const [keyword, setKeyword] = useState('');
  // Below lg the field collapses to an icon button and expands when invoked, so it
  // does not compete with the logo and the menu toggle for a phone's width.
  const [expanded, setExpanded] = useState(false);

  const submit = (event: FormEvent) => {
    event.preventDefault();

    const query = keyword.trim();

    // An empty search still navigates: landing on the unfiltered supplier
    // directory is a reasonable answer to "show me what's here", and is far less
    // confusing than a button that appears to do nothing.
    navigate(query ? `/suppliers?q=${encodeURIComponent(query)}` : '/suppliers');

    setExpanded(false);
    inputRef.current?.blur();
  };

  if (compact && !expanded) {
    return (
      <button
        type="button"
        className="mp-navsearch__icon-btn"
        aria-label="Search the marketplace"
        onClick={() => {
          setExpanded(true);
          // Focus after paint, or the input does not exist yet to receive it.
          requestAnimationFrame(() => inputRef.current?.focus());
        }}
      >
        <Icon name="search" size={18} />
      </button>
    );
  }

  return (
    <form
      className={`mp-navsearch${compact ? ' is-expanded' : ''}`}
      onSubmit={submit}
      role="search"
      aria-label="Search the marketplace"
    >
      <label className="mp-sr-only" htmlFor={id}>
        Search suppliers, products and services
      </label>

      <Icon name="search" size={16} className="mp-navsearch__glyph" />

      <input
        ref={inputRef}
        id={id}
        type="search"
        className="mp-navsearch__input"
        placeholder="Search suppliers or services"
        value={keyword}
        onChange={(event) => setKeyword(event.target.value)}
        onBlur={() => {
          // Collapse only if the user left it empty — otherwise a mistap would
          // discard what they had typed.
          if (compact && !keyword.trim()) setExpanded(false);
        }}
        // Native clear affordance on WebKit; harmless elsewhere.
        enterKeyHint="search"
      />

      <button type="submit" className="mp-navsearch__submit">
        <span className="mp-sr-only">Search</span>
        <Icon name="arrow-right" size={16} />
      </button>
    </form>
  );
}
