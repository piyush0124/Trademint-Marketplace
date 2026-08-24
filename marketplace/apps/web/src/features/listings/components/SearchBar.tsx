import { FormEvent, useState } from 'react';
import { useNavigate } from 'react-router-dom';

interface SearchBarProps {
  initialKeyword?: string;
  initialCity?: string;
  /**
   * Where a search lands. Suppliers is the default because this is a marketplace
   * for finding businesses; the listings catalogue is the secondary surface.
   */
  target?: '/suppliers' | '/browse';
  ctaLabel?: string;
}

/**
 * The primary marketplace search.
 *
 * The placeholders are deliberately industry-neutral. The original versions
 * ("Try '3 BHK apartment'", "e.g. Indirapuram, Ghaziabad") quietly told every
 * visitor this was a Delhi-NCR property site, which the data model is not.
 */
export default function SearchBar({
  initialKeyword = '',
  initialCity = '',
  target = '/suppliers',
  ctaLabel = 'Search',
}: SearchBarProps) {
  const navigate = useNavigate();
  const [keyword, setKeyword] = useState(initialKeyword);
  const [city, setCity] = useState(initialCity);

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault();
    const params = new URLSearchParams();
    if (keyword.trim()) params.set('q', keyword.trim());
    if (city.trim()) params.set('city', city.trim());
    navigate(`${target}?${params.toString()}`);
  };

  return (
    <form className="mp-search-bar" onSubmit={handleSubmit} role="search">
      <div className="row g-2 align-items-center">
        <div className="col-12 col-md-6">
          <label className="visually-hidden" htmlFor="search-keyword">What do you need?</label>
          <input
            id="search-keyword"
            className="form-control"
            placeholder="What do you need? Product, service or supplier"
            value={keyword}
            onChange={(event) => setKeyword(event.target.value)}
          />
        </div>
        <div className="col-12 col-md-4 mp-search-bar__divider">
          <label className="visually-hidden" htmlFor="search-city">City</label>
          <input
            id="search-city"
            className="form-control"
            placeholder="City — anywhere in India"
            value={city}
            onChange={(event) => setCity(event.target.value)}
          />
        </div>
        <div className="col-12 col-md-2">
          <button type="submit" className="btn btn-mp-primary w-100">{ctaLabel}</button>
        </div>
      </div>
    </form>
  );
}
