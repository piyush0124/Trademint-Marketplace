import { useEffect, useState } from 'react';
import { api } from '../../../lib/api/client';
import type { ApiListing, PaginatedResponse } from '../../../types/api';
import ListingCard from '../../listings/components/ListingCard';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';

export default function FavoritesPage() {
  const [listings, setListings] = useState<ApiListing[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api
      .get<PaginatedResponse<ApiListing>>('/favorites')
      .then((res) => setListings(res.data.data))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <LoadingState rows={4} />;

  if (listings.length === 0) {
    return <EmptyState icon="heart" title="No saved listings yet" description="Tap the heart on any listing to save it here." />;
  }

  return (
    <div className="row g-4">
      {listings.map((listing) => (
        <div key={listing.id} className="col-sm-6 col-lg-4">
          <ListingCard listing={listing} />
        </div>
      ))}
    </div>
  );
}
