import { FormEvent, useEffect, useState } from 'react';
import { api, getErrorMessage } from '../../../lib/api/client';
import type { ApiBusiness, ApiCategory, ApiEnvelope } from '../../../types/api';
import { useAuth } from '../../auth/AuthContext';

const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as const;
const BUSINESS_TYPES = ['manufacturer', 'wholesaler', 'retailer', 'distributor', 'service_provider', 'other'];

type Hours = Record<string, { open: string; close: string; closed: boolean }>;

function defaultHours(): Hours {
  return Object.fromEntries(DAYS.map((d) => [d, { open: '09:00', close: '18:00', closed: false }])) as Hours;
}

export default function BusinessSettingsPage() {
  const { user } = useAuth();
  const [name, setName] = useState('');
  const [about, setAbout] = useState('');
  const [phone, setPhone] = useState('');
  const [whatsapp, setWhatsapp] = useState('');
  const [email, setEmail] = useState('');
  const [website, setWebsite] = useState('');
  const [city, setCity] = useState('');
  const [state, setState] = useState('');
  const [businessType, setBusinessType] = useState('');
  const [yearsInBusiness, setYearsInBusiness] = useState('');
  const [tradeType, setTradeType] = useState('');
  const [categorySlug, setCategorySlug] = useState('');
  const [gstNumber, setGstNumber] = useState('');
  const [isAvailable, setIsAvailable] = useState(true);
  const [hours, setHours] = useState<Hours>(defaultHours());
  const [categories, setCategories] = useState<ApiCategory[]>([]);
  const [verification, setVerification] = useState<ApiBusiness['attributes']['verification'] | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(false);

  useEffect(() => {
    api.get<{ data: ApiCategory[] }>('/categories').then((res) => setCategories(res.data.data));
  }, []);

  useEffect(() => {
    if (!user) return;
    api.get<ApiEnvelope<ApiBusiness | null>>('/users/me/business').then((res) => {
      const business = res.data.data;
      if (!business) return;
      const attrs = business.attributes;
      setName(attrs.name);
      setAbout(attrs.about ?? '');
      setPhone(attrs.phone ?? '');
      setWhatsapp(attrs.whatsapp ?? '');
      setEmail(attrs.email ?? '');
      setWebsite(attrs.website ?? '');
      setCity(business.relationships?.location?.attributes.city ?? '');
      setState(business.relationships?.location?.attributes.state ?? '');
      setBusinessType(attrs.business_type ?? '');
      setYearsInBusiness(attrs.years_in_business ? String(attrs.years_in_business) : '');
      setTradeType(attrs.trade_type ?? '');
      setCategorySlug(business.relationships?.category?.attributes.slug ?? '');
      setGstNumber(attrs.gst_number ?? '');
      setIsAvailable(attrs.is_available);
      if (attrs.business_hours) {
        setHours({ ...defaultHours(), ...(attrs.business_hours as Hours) });
      }
      setVerification(attrs.verification);
    });
  }, [user]);

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    setSuccess(false);
    try {
      await api.post<ApiEnvelope<ApiBusiness>>('/businesses', {
        name,
        about,
        phone,
        whatsapp,
        email: email || undefined,
        website: website || undefined,
        business_type: businessType || undefined,
        years_in_business: yearsInBusiness ? Number(yearsInBusiness) : undefined,
        trade_type: tradeType || undefined,
        category_slug: categorySlug || undefined,
        gst_number: gstNumber || undefined,
        is_available: isAvailable,
        business_hours: hours,
        location: city ? { city, state: state || undefined, country: 'IN' } : undefined,
      });
      setSuccess(true);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="mp-card p-4" style={{ maxWidth: 720 }}>
      <h1 className="h5 mb-1">Business Profile</h1>
      <p className="text-muted small mb-4">
        Set this up so buyers see a verified business name instead of just your personal account.
      </p>

      {error && <div className="alert alert-danger">{error}</div>}
      {success && <div className="alert alert-success">Business profile saved.</div>}

      {verification && (
        <div className="d-flex flex-wrap gap-2 mb-4">
          {(['email', 'phone', 'business', 'address', 'gst'] as const).map((key) => (
            <span key={key} className="mp-badge-featured" style={{ opacity: verification[key] ? 1 : 0.5 }}>
              {verification[key] ? '✓' : '—'} {key.charAt(0).toUpperCase() + key.slice(1)} verified
            </span>
          ))}
        </div>
      )}

      <form onSubmit={handleSubmit}>
        <div className="mp-form-field mb-3">
          <label>Business Name</label>
          <input className="form-control" value={name} onChange={(e) => setName(e.target.value)} required />
        </div>
        <div className="mp-form-field mb-3">
          <label>About</label>
          <textarea className="form-control" rows={3} value={about} onChange={(e) => setAbout(e.target.value)} />
        </div>

        <div className="row g-3 mb-3">
          <div className="col-md-4 mp-form-field">
            <label>Business Type</label>
            <select className="form-select" value={businessType} onChange={(e) => setBusinessType(e.target.value)}>
              <option value="">Select…</option>
              {BUSINESS_TYPES.map((t) => (
                <option key={t} value={t}>{t.replace('_', ' ')}</option>
              ))}
            </select>
          </div>
          <div className="col-md-4 mp-form-field">
            <label>Years in Business</label>
            <input type="number" min={0} className="form-control" value={yearsInBusiness} onChange={(e) => setYearsInBusiness(e.target.value)} />
          </div>
          <div className="col-md-4 mp-form-field">
            <label>Trade Type</label>
            <select className="form-select" value={tradeType} onChange={(e) => setTradeType(e.target.value)}>
              <option value="">Select…</option>
              <option value="b2b">B2B</option>
              <option value="b2c">B2C</option>
              <option value="both">Both</option>
            </select>
          </div>
        </div>

        <div className="mp-form-field mb-3">
          <label>Primary Category</label>
          <select className="form-select" value={categorySlug} onChange={(e) => setCategorySlug(e.target.value)}>
            <option value="">Select…</option>
            {categories.map((cat) => (
              <option key={cat.id} value={cat.attributes.slug}>{cat.attributes.name}</option>
            ))}
          </select>
        </div>

        <div className="row g-3 mb-3">
          <div className="col-6 mp-form-field">
            <label>Phone</label>
            <input className="form-control" value={phone} onChange={(e) => setPhone(e.target.value)} />
          </div>
          <div className="col-6 mp-form-field">
            <label>WhatsApp</label>
            <input className="form-control" value={whatsapp} onChange={(e) => setWhatsapp(e.target.value)} />
          </div>
        </div>
        <div className="row g-3 mb-3">
          <div className="col-6 mp-form-field">
            <label>Email</label>
            <input type="email" className="form-control" value={email} onChange={(e) => setEmail(e.target.value)} />
          </div>
          <div className="col-6 mp-form-field">
            <label>Website</label>
            <input className="form-control" value={website} onChange={(e) => setWebsite(e.target.value)} />
          </div>
        </div>

        <div className="row g-3 mb-3">
          <div className="col-6 mp-form-field">
            <label>City</label>
            <input className="form-control" value={city} onChange={(e) => setCity(e.target.value)} placeholder="e.g. Ghaziabad" />
          </div>
          <div className="col-6 mp-form-field">
            <label>State</label>
            <input className="form-control" value={state} onChange={(e) => setState(e.target.value)} placeholder="e.g. Uttar Pradesh" />
          </div>
        </div>

        <div className="mp-form-field mb-4">
          <label>GST Number</label>
          <input className="form-control" value={gstNumber} onChange={(e) => setGstNumber(e.target.value.toUpperCase())} placeholder="e.g. 29ABCDE1234F1Z5" />
          <div className="form-text">Submitting a GST number does not verify it — verification is a separate admin review step.</div>
        </div>

        <div className="form-check mb-4">
          <input type="checkbox" className="form-check-input" id="is-available" checked={isAvailable} onChange={(e) => setIsAvailable(e.target.checked)} />
          <label className="form-check-label" htmlFor="is-available">Currently accepting new business</label>
        </div>

        <h2 className="h6 mb-2">Business Hours</h2>
        <div className="mb-4">
          {DAYS.map((day) => (
            <div className="row g-2 align-items-center mb-2" key={day}>
              <div className="col-3 text-capitalize small">{day}</div>
              <div className="col-3">
                <input
                  type="time"
                  className="form-control form-control-sm"
                  disabled={hours[day]?.closed}
                  value={hours[day]?.open ?? '09:00'}
                  onChange={(e) => setHours((prev) => ({ ...prev, [day]: { ...prev[day], open: e.target.value } }))}
                />
              </div>
              <div className="col-3">
                <input
                  type="time"
                  className="form-control form-control-sm"
                  disabled={hours[day]?.closed}
                  value={hours[day]?.close ?? '18:00'}
                  onChange={(e) => setHours((prev) => ({ ...prev, [day]: { ...prev[day], close: e.target.value } }))}
                />
              </div>
              <div className="col-3 form-check">
                <input
                  type="checkbox"
                  className="form-check-input"
                  id={`closed-${day}`}
                  checked={hours[day]?.closed ?? false}
                  onChange={(e) => setHours((prev) => ({ ...prev, [day]: { ...prev[day], closed: e.target.checked } }))}
                />
                <label className="form-check-label small" htmlFor={`closed-${day}`}>Closed</label>
              </div>
            </div>
          ))}
        </div>

        <button type="submit" className="btn btn-mp-primary" disabled={saving}>
          {saving ? 'Saving…' : 'Save Business Profile'}
        </button>
      </form>
    </div>
  );
}
