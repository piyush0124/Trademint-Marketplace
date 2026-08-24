import { useEffect } from 'react';

/**
 * Reveals `.mp-reveal` elements as they scroll into view.
 *
 * Written as progressive enhancement on purpose. The CSS keeps `.mp-reveal`
 * fully visible by default and only hides it once this hook adds `is-armed`, so
 * a JS failure, an old browser or a crawler sees the complete page rather than a
 * blank one. Users who ask for reduced motion are skipped entirely — the hook
 * never arms, so nothing ever fades.
 */
export function useScrollReveal(dependency: unknown = null): void {
  useEffect(() => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    if (typeof IntersectionObserver === 'undefined') return;

    const elements = Array.from(document.querySelectorAll<HTMLElement>('.mp-reveal:not(.is-visible)'));
    if (elements.length === 0) return;

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          entry.target.classList.add('is-visible');
          // One-shot: content should not fade back out when scrolled past.
          observer.unobserve(entry.target);
        });
      },
      { rootMargin: '0px 0px -8% 0px', threshold: 0.08 }
    );

    elements.forEach((element, index) => {
      element.classList.add('is-armed');
      // Small stagger so a row of cards arrives in sequence, not as one block.
      element.style.transitionDelay = `${Math.min(index % 4, 3) * 70}ms`;
      observer.observe(element);
    });

    return () => observer.disconnect();
  }, [dependency]);
}
