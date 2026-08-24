import type { ApiRequirementMatch, MatchDimension } from '../../../types/api';

const DIMENSION_ORDER: MatchDimension[] = ['category', 'capability', 'location', 'budget', 'availability', 'trust'];

const DIMENSION_LABELS: Record<MatchDimension, string> = {
  category: 'Category',
  capability: 'Capability',
  location: 'Location',
  budget: 'Budget',
  availability: 'Availability',
  trust: 'Trust',
};

interface MatchBreakdownProps {
  breakdown: ApiRequirementMatch['attributes']['score_breakdown'];
}

/**
 * The per-dimension detail behind a match percentage.
 *
 * Each bar shows how much of that dimension's available weight was actually
 * earned, so "Location 20/20" and "Budget 7.5/15" are directly comparable —
 * which is what makes the score explainable rather than a black box.
 */
export default function MatchBreakdown({ breakdown }: MatchBreakdownProps) {
  const rows = DIMENSION_ORDER.map((dimension) => ({ dimension, detail: breakdown[dimension] })).filter(
    (row) => row.detail !== undefined
  );

  if (rows.length === 0) return null;

  return (
    <div className="mp-match-breakdown">
      {rows.map(({ dimension, detail }) => {
        const available = detail!.weight_percentage;
        const earned = detail!.points;
        const filled = available > 0 ? Math.max(0, Math.min(100, (earned / available) * 100)) : 0;

        return (
          <div key={dimension} className="mp-match-breakdown__row">
            <span className="mp-match-breakdown__label">{DIMENSION_LABELS[dimension]}</span>
            <span className="mp-match-breakdown__track">
              <span
                className="mp-match-breakdown__fill"
                style={{ width: `${filled}%` }}
                role="img"
                aria-label={`${DIMENSION_LABELS[dimension]}: ${earned.toFixed(1)} of ${available.toFixed(0)} points`}
              />
            </span>
            <span className="mp-match-breakdown__value">
              {earned.toFixed(1)}/{available.toFixed(0)}
            </span>
          </div>
        );
      })}
    </div>
  );
}
