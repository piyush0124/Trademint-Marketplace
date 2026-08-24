interface MatchScoreProps {
  percentage: number;
  size?: 'sm' | 'md';
}

/**
 * The match percentage as a single glanceable badge.
 *
 * The three bands are presentation only — the backend decides what actually
 * qualifies as a match (config('marketplace.matching.minimum_score')), so
 * these thresholds never gate anything, they just describe strength.
 */
function band(percentage: number): 'is-strong' | 'is-good' | 'is-partial' {
  if (percentage >= 80) return 'is-strong';
  if (percentage >= 60) return 'is-good';
  return 'is-partial';
}

export default function MatchScore({ percentage, size = 'md' }: MatchScoreProps) {
  const rounded = Math.round(percentage);

  return (
    <div
      className={`mp-match-score ${band(rounded)} ${size === 'sm' ? 'mp-match-score--sm' : ''}`}
      role="img"
      aria-label={`${rounded} percent match`}
      title={`${rounded}% match`}
    >
      <span className="mp-match-score__value">{rounded}%</span>
      {size === 'md' && <span className="mp-match-score__unit">match</span>}
    </div>
  );
}
