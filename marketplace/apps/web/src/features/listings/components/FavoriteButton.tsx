import { useEffect, useState } from 'react';
import { api } from '../../../lib/api/client';
import { useAuth } from '../../auth/AuthContext';

interface FavoriteButtonProps {
  listingId: string;
  className?: string;
  initiallyFavorited?: boolean;
}

/** Optimistic favorite toggle — never reloads the page. */
export default function FavoriteButton({ listingId, className, initiallyFavorited = false }: FavoriteButtonProps) {
  const { isAuthenticated } = useAuth();
  const [favorited, setFavorited] = useState(initiallyFavorited);
  const [busy, setBusy] = useState(false);

  useEffect(() => setFavorited(initiallyFavorited), [initiallyFavorited]);

  if (!isAuthenticated) return null;

  const toggle = async (event: React.MouseEvent) => {
    event.preventDefault();
    event.stopPropagation();
    if (busy) return;
    setBusy(true);
    const next = !favorited;
    setFavorited(next); // optimistic
    try {
      if (next) {
        await api.post(`/listings/${listingId}/favorite`);
      } else {
        await api.delete(`/listings/${listingId}/favorite`);
      }
    } catch {
      setFavorited(!next); // revert on failure
    } finally {
      setBusy(false);
    }
  };

  return (
    <button
      type="button"
      className={className ?? ''}
      onClick={toggle}
      aria-pressed={favorited}
      aria-label={favorited ? 'Remove from favorites' : 'Save to favorites'}
      title={favorited ? 'Remove from favorites' : 'Save to favorites'}
    >
      {favorited ? '❤️' : '🤍'}
    </button>
  );
}
