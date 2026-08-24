import type { ApiNegotiationMessage, TimelineEntryType } from '../../../types/api';
import { formatMoney } from '../../../lib/money';

/**
 * The negotiation as a conversation, oldest first.
 *
 * Each entry says who spoke, what they said, and — when the entry is an offer —
 * what the figures were at that moment. Because revisions are immutable, this is
 * a faithful record rather than a reconstruction.
 *
 * Entry type is conveyed by an icon *and* a text label, never by colour alone
 * (WCAG 1.4.1).
 */
const ENTRY_META: Record<TimelineEntryType, { icon: string; label: string }> = {
  counter: { icon: '↔', label: 'Counter offer' },
  accept: { icon: '✓', label: 'Accepted' },
  reject: { icon: '✕', label: 'Rejected' },
  withdraw: { icon: '↩', label: 'Withdrawn' },
  close: { icon: '■', label: 'Closed' },
  message: { icon: '💬', label: 'Message' },
  system: { icon: 'ⓘ', label: 'System' },
};

interface NegotiationTimelineProps {
  entries: ApiNegotiationMessage[];
  viewerParty: 'customer' | 'business' | null;
}

function formatWhen(iso: string | null): string {
  if (!iso) return '';
  const date = new Date(iso);

  return date.toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' });
}

export default function NegotiationTimeline({ entries, viewerParty }: NegotiationTimelineProps) {
  if (entries.length === 0) {
    return <p className="text-muted small mb-0">No activity yet.</p>;
  }

  return (
    <ol className="mp-timeline" aria-label="Negotiation history">
      {entries.map((entry) => {
        const meta = ENTRY_META[entry.attributes.entry_type] ?? ENTRY_META.system;
        const party = entry.attributes.author_party;
        const isMine = viewerParty !== null && party === viewerParty;
        const revision = entry.relationships.revision;

        return (
          <li key={entry.id} className={`mp-timeline__item ${isMine ? 'is-mine' : ''}`}>
            <span className="mp-timeline__marker" aria-hidden="true">{meta.icon}</span>
            <div className="mp-timeline__body">
              <div className="d-flex justify-content-between align-items-baseline flex-wrap gap-2">
                <span className="fw-semibold small">
                  {meta.label}
                  <span className="text-muted fw-normal">
                    {' · '}
                    {party === 'system' ? 'System' : isMine ? 'You' : party === 'customer' ? 'Customer' : 'Business'}
                  </span>
                </span>
                <span className="text-muted" style={{ fontSize: '0.75rem' }}>{formatWhen(entry.attributes.created_at)}</span>
              </div>

              {entry.attributes.body && <p className="small mb-1 mt-1">{entry.attributes.body}</p>}

              {/* The figures as they stood for this entry — from the immutable
                  revision, never recomputed here. */}
              {revision && entry.attributes.entry_type === 'counter' && (
                <div className="small text-muted">
                  Offer #{revision.attributes.revision_number}:{' '}
                  <span className="fw-semibold text-dark">
                    {formatMoney(revision.attributes.total_amount, revision.attributes.currency)}
                  </span>
                  {revision.attributes.is_final_offer && <span className="mp-badge-featured ms-2">Final offer</span>}
                </div>
              )}
            </div>
          </li>
        );
      })}
    </ol>
  );
}
