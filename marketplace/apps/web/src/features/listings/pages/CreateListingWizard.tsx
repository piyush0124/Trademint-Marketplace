import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { api, formatPrice, getErrorMessage } from '../../../lib/api/client';
import type { ApiAttributeDefinition, ApiCategory, ApiEnvelope, ApiListing } from '../../../types/api';
import MediaUploader from '../components/MediaUploader';
import StatusBadge from '../../../components/ui/StatusBadge';

const STEPS = ['Category', 'Basic Info', 'Location', 'Attributes', 'Media', 'Preview', 'Submit'];

type AttributeMap = Record<string, string | number | boolean>;

export default function CreateListingWizard() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();

  const [step, setStep] = useState(0);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const [categories, setCategories] = useState<ApiCategory[]>([]);
  const [categorySlug, setCategorySlug] = useState('');
  const [listing, setListing] = useState<ApiListing | null>(null);

  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [price, setPrice] = useState('');
  const [priceMax, setPriceMax] = useState('');
  const [unit, setUnit] = useState('');
  const [minOrderQuantity, setMinOrderQuantity] = useState('');
  const [currency, setCurrency] = useState('INR');

  const [city, setCity] = useState('');
  const [area, setArea] = useState('');
  const [locState, setLocState] = useState('');

  const [definitions, setDefinitions] = useState<ApiAttributeDefinition[]>([]);
  const [attributeValues, setAttributeValues] = useState<AttributeMap>({});

  useEffect(() => {
    api.get<{ data: ApiCategory[] }>('/categories').then((res) => setCategories(res.data.data));
  }, []);

  // Load an existing draft/rejected listing for editing.
  useEffect(() => {
    if (!id) return;
    api.get<ApiEnvelope<ApiListing>>(`/listings/${id}`).then((res) => {
      const l = res.data.data;
      setListing(l);
      setCategorySlug(l.relationships.category?.attributes.slug ?? '');
      setTitle(l.attributes.title ?? '');
      setDescription(l.attributes.description ?? '');
      setPrice(l.attributes.price ?? '');
      setPriceMax(l.attributes.price_max ?? '');
      setUnit(l.attributes.unit ?? '');
      setMinOrderQuantity(l.attributes.min_order_quantity ? String(l.attributes.min_order_quantity) : '');
      setCurrency(l.attributes.currency ?? 'INR');
      if (l.relationships.location) {
        setCity(l.relationships.location.attributes.city);
        setArea(l.relationships.location.attributes.area ?? '');
        setLocState(l.relationships.location.attributes.state ?? '');
      }
      setAttributeValues(l.attributes.attributes as AttributeMap);
      setStep(1);
    });
  }, [id]);

  useEffect(() => {
    if (!categorySlug) return;
    api
      .get<{ data: ApiAttributeDefinition[] }>(`/categories/${categorySlug}/attribute-definitions`)
      .then((res) => setDefinitions(res.data.data));
  }, [categorySlug]);

  const persist = async (payload: Record<string, unknown>) => {
    setSaving(true);
    setError('');
    try {
      if (!listing) {
        const res = await api.post<ApiEnvelope<ApiListing>>('/listings', { category_slug: categorySlug, ...payload });
        setListing(res.data.data);
        return res.data.data;
      }
      const res = await api.patch<ApiEnvelope<ApiListing>>(`/listings/${listing.id}`, payload);
      setListing(res.data.data);
      return res.data.data;
    } catch (err) {
      setError(getErrorMessage(err));
      throw err;
    } finally {
      setSaving(false);
    }
  };

  const goNext = async () => {
    try {
      if (step === 0) {
        setStep(1);
        return;
      }
      if (step === 1) {
        await persist({
          title,
          description,
          price: Number(price),
          price_max: priceMax ? Number(priceMax) : undefined,
          unit: unit || undefined,
          min_order_quantity: minOrderQuantity ? Number(minOrderQuantity) : undefined,
          currency,
        });
      }
      if (step === 2) {
        await persist({ location: { city, area, state: locState, country: 'IN' } });
      }
      if (step === 3) {
        await persist({ attributes: attributeValues });
      }
      setStep((s) => Math.min(s + 1, STEPS.length - 1));
    } catch {
      // error already surfaced
    }
  };

  const handleSubmitForReview = async () => {
    if (!listing) return;
    setSaving(true);
    setError('');
    try {
      const res = await api.post<ApiEnvelope<ApiListing>>(`/listings/${listing.id}/submit`);
      setListing(res.data.data);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  const saveAndExit = () => navigate('/dashboard/listings');

  return (
    <div className="mp-container py-4" style={{ maxWidth: 860 }}>
      <div className="d-flex justify-content-between align-items-center mb-3">
        <h1 className="h4 mb-0">{listing ? 'Edit Listing' : 'Add a New Listing'}</h1>
        {listing && <StatusBadge status={listing.attributes.system_status} />}
      </div>

      <div className="mp-wizard-steps">
        {STEPS.map((label, index) => (
          <div key={label} className={`mp-wizard-step ${index === step ? 'is-active' : index < step ? 'is-complete' : ''}`}>
            {index + 1}. {label}
          </div>
        ))}
      </div>

      {error && <div className="alert alert-danger">{error}</div>}

      {step === 0 && (
        <div className="mp-card p-4">
          <p className="text-muted mb-3">Choose the category that best fits what you're listing.</p>
          <div className="row g-3">
            {categories.map((cat) => (
              <div key={cat.id} className="col-6 col-md-4">
                <button
                  type="button"
                  className="mp-category-pill w-100"
                  style={{ borderColor: categorySlug === cat.attributes.slug ? 'var(--mp-primary)' : undefined }}
                  onClick={() => setCategorySlug(cat.attributes.slug)}
                >
                  {cat.attributes.name}
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      {step === 1 && (
        <div className="mp-card p-4">
          <div className="mp-form-field mb-3">
            <label>Title</label>
            <input
              className="form-control"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              placeholder="What are you offering? Be specific"
            />
          </div>
          <div className="mp-form-field mb-3">
            <label>Description</label>
            <textarea className="form-control" rows={4} value={description} onChange={(e) => setDescription(e.target.value)} />
          </div>
          <div className="row g-3 mb-3">
            <div className="col-8 mp-form-field">
              <label>Price</label>
              <input type="number" className="form-control" value={price} onChange={(e) => setPrice(e.target.value)} />
            </div>
            <div className="col-4 mp-form-field">
              <label>Currency</label>
              <select className="form-select" value={currency} onChange={(e) => setCurrency(e.target.value)}>
                <option value="INR">INR</option>
                <option value="USD">USD</option>
              </select>
            </div>
          </div>
          <div className="row g-3">
            <div className="col-4 mp-form-field">
              <label>Max Price (optional)</label>
              <input type="number" className="form-control" value={priceMax} onChange={(e) => setPriceMax(e.target.value)} placeholder="For a price range" />
            </div>
            <div className="col-4 mp-form-field">
              <label>Unit</label>
              <input className="form-control" value={unit} onChange={(e) => setUnit(e.target.value)} placeholder="e.g. sq ft, piece" />
            </div>
            <div className="col-4 mp-form-field">
              <label>Min Order Quantity</label>
              <input type="number" min={1} className="form-control" value={minOrderQuantity} onChange={(e) => setMinOrderQuantity(e.target.value)} />
            </div>
          </div>
        </div>
      )}

      {step === 2 && (
        <div className="mp-card p-4">
          <div className="mp-form-field mb-3">
            <label>City</label>
            <input className="form-control" value={city} onChange={(e) => setCity(e.target.value)} placeholder="e.g. Ghaziabad" />
          </div>
          <div className="row g-3">
            <div className="col-6 mp-form-field">
              <label>Area</label>
              <input
                className="form-control"
                value={area}
                onChange={(e) => setArea(e.target.value)}
                placeholder="Area or locality (optional)"
              />
            </div>
            <div className="col-6 mp-form-field">
              <label>State</label>
              <input className="form-control" value={locState} onChange={(e) => setLocState(e.target.value)} />
            </div>
          </div>
        </div>
      )}

      {step === 3 && (
        <div className="mp-card p-4">
          {definitions.length === 0 && <p className="text-muted">This category has no additional attributes.</p>}
          <div className="row g-3">
            {definitions.map((def) => {
              const key = def.attributes.key;
              const value = attributeValues[key] ?? '';
              const setValue = (v: string | number | boolean) => setAttributeValues((prev) => ({ ...prev, [key]: v }));

              return (
                <div key={def.id} className="col-md-6 mp-form-field">
                  <label>
                    {def.attributes.label}
                    {def.attributes.is_required && ' *'}
                  </label>
                  {def.attributes.type === 'select' && def.attributes.options ? (
                    <select className="form-select" value={String(value)} onChange={(e) => setValue(e.target.value)}>
                      <option value="">Select…</option>
                      {def.attributes.options.map((opt) => (
                        <option key={opt} value={opt} className="text-capitalize">{opt}</option>
                      ))}
                    </select>
                  ) : def.attributes.type === 'boolean' ? (
                    <select className="form-select" value={String(value)} onChange={(e) => setValue(e.target.value === 'true')}>
                      <option value="">Select…</option>
                      <option value="true">Yes</option>
                      <option value="false">No</option>
                    </select>
                  ) : (
                    <input
                      type={def.attributes.type === 'integer' || def.attributes.type === 'decimal' ? 'number' : 'text'}
                      className="form-control"
                      value={String(value)}
                      onChange={(e) => setValue(e.target.value)}
                    />
                  )}
                </div>
              );
            })}
          </div>
        </div>
      )}

      {step === 4 && listing && (
        <MediaUploader
          listingUuid={listing.id}
          media={listing.relationships.media ?? []}
          onChange={(media) => setListing({ ...listing, relationships: { ...listing.relationships, media } })}
        />
      )}

      {step === 5 && listing && (
        <div className="mp-card p-4">
          <h2 className="h5">{title || 'Untitled listing'}</h2>
          <p className="mp-price">{price ? formatPrice(price, currency) : '—'}</p>
          <p className="text-muted">{[area, city].filter(Boolean).join(', ')}</p>
          <p style={{ whiteSpace: 'pre-line' }}>{description}</p>
          <div className="row g-2 mb-2">
            {Object.entries(attributeValues).map(([key, value]) => (
              <div key={key} className="col-6 small text-muted">
                <strong className="text-dark">{key}:</strong> {String(value)}
              </div>
            ))}
          </div>
          <p className="small text-muted mb-0">{(listing.relationships.media ?? []).length} photo(s) attached</p>
        </div>
      )}

      {step === 6 && listing && (
        <div className="mp-card p-4 text-center">
          {listing.attributes.system_status === 'pending_review' ? (
            <>
              <div className="fs-1 mb-2">🕓</div>
              <h2 className="h5">Submitted for review</h2>
              <p className="text-muted">An admin will review your listing shortly. You'll see it go live once approved.</p>
            </>
          ) : (
            <>
              <p className="text-muted">Everything looks good? Submit your listing for admin review — it won't go live until it's approved.</p>
              <button className="btn btn-mp-primary px-4" onClick={handleSubmitForReview} disabled={saving}>
                {saving ? 'Submitting…' : 'Submit for Review'}
              </button>
            </>
          )}
        </div>
      )}

      <div className="d-flex justify-content-between mt-4">
        <div>
          {step > 0 && step < 6 && (
            <button className="btn btn-mp-ghost" onClick={() => setStep((s) => s - 1)}>
              ← Back
            </button>
          )}
        </div>
        <div className="d-flex gap-2">
          <button className="btn btn-mp-outline" onClick={saveAndExit}>
            Save Draft & Continue Later
          </button>
          {step < 6 && (
            <button className="btn btn-mp-primary" onClick={goNext} disabled={saving || (step === 0 && !categorySlug)}>
              {saving ? 'Saving…' : 'Continue →'}
            </button>
          )}
          {step === 6 && listing?.attributes.system_status === 'pending_review' && (
            <button className="btn btn-mp-primary" onClick={() => navigate('/dashboard/listings')}>
              Go to My Listings
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
