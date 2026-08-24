interface ErrorStateProps {
  message?: string;
  onRetry?: () => void;
}

export default function ErrorState({ message = 'Something went wrong.', onRetry }: ErrorStateProps) {
  return (
    <div className="mp-state">
      <span className="mp-state__icon" aria-hidden="true">⚠️</span>
      <h3>{message}</h3>
      {onRetry && (
        <button type="button" className="btn btn-mp-outline mt-2" onClick={onRetry}>
          Try again
        </button>
      )}
    </div>
  );
}
