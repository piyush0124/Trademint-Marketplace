import type { ReactNode } from 'react';
import Icon, { type IconName } from './Icon';

interface EmptyStateProps {
  /**
   * Icon name from the shared set.
   *
   * Was previously a raw string holding an emoji, which is how the emoji ended up
   * inline in half a dozen pages. Typing it as IconName means an unknown icon is a
   * compile error rather than a box glyph shipped to production.
   */
  icon?: IconName;
  title: string;
  description?: string;
  action?: ReactNode;
}

export default function EmptyState({ icon = 'search', title, description, action }: EmptyStateProps) {
  return (
    <div className="mp-state">
      <span className="mp-state__icon">
        <Icon name={icon} size={32} />
      </span>
      <h3>{title}</h3>
      {description && <p className="mb-3">{description}</p>}
      {action}
    </div>
  );
}
