import { FormEvent, useEffect, useState } from 'react';
import { api, getErrorMessage } from '../../../lib/api/client';
import type { ApiBusiness, ApiEnvelope, ApiIndustry } from '../../../types/api';
import LoadingState from '../../../components/ui/LoadingState';

export default function CapabilitiesPage() {
  const [business, setBusiness] = useState<ApiBusiness | null>(null);
  const [allIndustries, setAllIndustries] = useState<ApiIndustry[]>([]);
  const [selectedIndustryIds, setSelectedIndustryIds] = useState<number[]>([]);
  const [areas, setAreas] = useState<{ city: string; state: string }[]>([{ city: '', state: '' }]);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [savingAreas, setSavingAreas] = useState(false);
  const [savingIndustries, setSavingIndustries] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    Promise.all([
      api.get<ApiEnvelope<ApiBusiness | null>>('/users/me/business'),
      api.get<ApiEnvelope<ApiIndustry[]>>('/industries'),
    ])
      .then(([businessRes, industriesRes]) => {
        setBusiness(businessRes.data.data);
        setAllIndustries(industriesRes.data.data);
        setSelectedIndustryIds((businessRes.data.data?.relationships?.industries ?? []).map((i) => i.id));
        const existingAreas = businessRes.data.data?.relationships?.service_areas ?? [];
        setAreas(
          existingAreas.length > 0
            ? existingAreas.map((a) => ({ city: a.attributes.city, state: a.attributes.state ?? '' }))
            : [{ city: '', state: '' }],
        );
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  }, []);

  const saveServiceAreas = async (event: FormEvent) => {
    event.preventDefault();
    setSavingAreas(true);
    setError('');
    try {
      const locations = areas.filter((a) => a.city.trim() !== '').map((a) => ({ city: a.city, state: a.state || undefined }));
      const res = await api.put<ApiEnvelope<ApiBusiness>>('/businesses/service-areas', { locations });
      setBusiness(res.data.data);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setSavingAreas(false);
    }
  };

  const saveIndustries = async () => {
    setSavingIndustries(true);
    setError('');
    try {
      const res = await api.put<ApiEnvelope<ApiBusiness>>('/businesses/industries', { industry_ids: selectedIndustryIds });
      setBusiness(res.data.data);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setSavingIndustries(false);
    }
  };

  const toggleIndustry = (id: number) => {
    setSelectedIndustryIds((prev) => (prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]));
  };

  if (status === 'loading') return <LoadingState />;

  if (!business) {
    return <p className="text-muted">Create your business profile first before setting capabilities.</p>;
  }

  return (
    <div>
      <h1 className="h5 mb-3">Capabilities</h1>
      {error && <div className="alert alert-danger">{error}</div>}

      <div className="mp-card p-4 mb-4">
        <h2 className="h6 mb-1">Service Areas</h2>
        <p className="text-muted small mb-3">Cities and states your business delivers to or serves — add as many as apply.</p>
        <form onSubmit={saveServiceAreas}>
          {areas.map((area, index) => (
            <div className="row g-2 mb-2" key={index}>
              <div className="col-5">
                <input
                  className="form-control"
                  placeholder="City"
                  value={area.city}
                  onChange={(e) => setAreas((prev) => prev.map((a, i) => (i === index ? { ...a, city: e.target.value } : a)))}
                />
              </div>
              <div className="col-5">
                <input
                  className="form-control"
                  placeholder="State"
                  value={area.state}
                  onChange={(e) => setAreas((prev) => prev.map((a, i) => (i === index ? { ...a, state: e.target.value } : a)))}
                />
              </div>
              <div className="col-2">
                <button
                  type="button"
                  className="btn btn-mp-outline btn-sm w-100"
                  onClick={() => setAreas((prev) => prev.filter((_, i) => i !== index))}
                  disabled={areas.length === 1}
                >
                  ✕
                </button>
              </div>
            </div>
          ))}
          <div className="d-flex gap-2 mt-2">
            <button type="button" className="btn btn-mp-outline btn-sm" onClick={() => setAreas((prev) => [...prev, { city: '', state: '' }])}>
              + Add City
            </button>
            <button type="submit" className="btn btn-mp-primary btn-sm" disabled={savingAreas}>
              {savingAreas ? 'Saving…' : 'Save Service Areas'}
            </button>
          </div>
        </form>
      </div>

      <div className="mp-card p-4">
        <h2 className="h6 mb-1">Industries Served</h2>
        <p className="text-muted small mb-3">Select the industries your business primarily serves.</p>
        <div className="d-flex flex-wrap gap-2 mb-3">
          {allIndustries.map((industry) => {
            const selected = selectedIndustryIds.includes(industry.id);
            return (
              <button
                key={industry.id}
                type="button"
                className={selected ? 'btn btn-mp-primary btn-sm' : 'btn btn-mp-outline btn-sm'}
                onClick={() => toggleIndustry(industry.id)}
              >
                {industry.attributes.name}
              </button>
            );
          })}
        </div>
        <button type="button" className="btn btn-mp-primary btn-sm" onClick={saveIndustries} disabled={savingIndustries}>
          {savingIndustries ? 'Saving…' : 'Save Industries'}
        </button>
      </div>
    </div>
  );
}
