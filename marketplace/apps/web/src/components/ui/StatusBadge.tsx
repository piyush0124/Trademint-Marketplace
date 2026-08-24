import type { AvailabilityStatus, SystemStatus } from '../../types/api';

const LABELS: Record<string, string> = {
  draft: 'Draft',
  pending_review: 'Pending Review',
  published: 'Published',
  rejected: 'Rejected',
  archived: 'Archived',
  available: 'Available',
  sold: 'Sold',
  rented: 'Rented',
  expired: 'Expired',
  inactive: 'Inactive',
  matching: 'Matching',
  receiving_quotes: 'Receiving Quotes',
  closed: 'Closed',
  cancelled: 'Cancelled',

  // Orders (Phase 7)
  confirmed: 'Confirmed',
  processing: 'Processing',
  in_progress: 'In Progress',
  ready: 'Ready',
  dispatched: 'Dispatched',
  delivered: 'Delivered',
  completed: 'Completed',
  disputed: 'Disputed',

  // Payments (Phase 8). `pending`/`failed` are shared with other domains.
  unpaid: 'Unpaid',
  initiated: 'Initiated',
  partially_paid: 'Partly Paid',
  paid: 'Paid',
  failed: 'Failed',
  partially_refunded: 'Partly Refunded',
  refunded: 'Refunded',

  // Trust and disputes (Phases 9–10)
  unverified: 'Unverified',
  verified: 'Verified',
  suspended: 'Suspended',
  hidden: 'Hidden',
  flagged: 'Flagged',
  open: 'Open',
  under_review: 'Under Review',
  resolved: 'Resolved',
  approved: 'Approved',
};

interface StatusBadgeProps {
  status: SystemStatus | AvailabilityStatus | string;
}

/** Single source of truth for status -> color + label across the whole app. */
export default function StatusBadge({ status }: StatusBadgeProps) {
  return <span className={`mp-status-badge mp-status-${status}`}>{LABELS[status] ?? status}</span>;
}
