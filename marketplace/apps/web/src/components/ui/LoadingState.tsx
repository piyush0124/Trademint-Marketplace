interface LoadingStateProps {
  rows?: number;
  variant?: 'cards' | 'list';
}

/** Skeleton loader — used instead of a bare "Loading..." string anywhere data is fetched. */
export default function LoadingState({ rows = 6, variant = 'cards' }: LoadingStateProps) {
  if (variant === 'list') {
    return (
      <div className="d-flex flex-column gap-2">
        {Array.from({ length: rows }).map((_, i) => (
          <div key={i} className="mp-skeleton" style={{ height: 64 }} />
        ))}
      </div>
    );
  }

  return (
    <div className="row g-4">
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="col-sm-6 col-lg-4">
          <div className="mp-skeleton" style={{ aspectRatio: '4 / 3', marginBottom: 12 }} />
          <div className="mp-skeleton" style={{ height: 16, width: '70%', marginBottom: 8 }} />
          <div className="mp-skeleton" style={{ height: 16, width: '40%' }} />
        </div>
      ))}
    </div>
  );
}
