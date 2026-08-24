import { FormEvent, useEffect, useState } from 'react';
import { api, getErrorMessage } from '../../../lib/api/client';
import type { ApiEnvelope, ApiService, PaginatedResponse } from '../../../types/api';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';

interface ServiceFormState {
  name: string;
  description: string;
  min_order: string;
  estimated_delivery_time: string;
  customization_available: boolean;
}

const EMPTY_FORM: ServiceFormState = {
  name: '',
  description: '',
  min_order: '',
  estimated_delivery_time: '',
  customization_available: false,
};

export default function ServicesPage() {
  const [services, setServices] = useState<ApiService[]>([]);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [form, setForm] = useState<ServiceFormState>(EMPTY_FORM);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const load = () => {
    setStatus('loading');
    api
      .get<PaginatedResponse<ApiService> | ApiEnvelope<ApiService[]>>('/users/me/services')
      .then((res) => {
        setServices(res.data.data);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  };

  useEffect(load, []);

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    try {
      await api.post('/services', {
        name: form.name,
        description: form.description || null,
        min_order: form.min_order ? Number(form.min_order) : null,
        estimated_delivery_time: form.estimated_delivery_time || null,
        customization_available: form.customization_available,
      });
      setForm(EMPTY_FORM);
      load();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  const toggleActive = async (service: ApiService) => {
    await api.patch(`/services/${service.id}`, { is_active: !service.attributes.is_active });
    load();
  };

  const remove = async (service: ApiService) => {
    await api.delete(`/services/${service.id}`);
    load();
  };

  return (
    <div>
      <h1 className="h5 mb-3">Services</h1>
      <p className="text-muted small mb-4">Define the services your business offers.</p>

      <div className="mp-card p-4 mb-4">
        {error && <div className="alert alert-danger">{error}</div>}
        <form onSubmit={handleSubmit}>
          <div className="row g-3 mb-3">
            <div className="col-md-6 mp-form-field">
              <label>Service Name</label>
              <input className="form-control" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </div>
            <div className="col-md-6 mp-form-field">
              <label>Estimated Delivery Time</label>
              <input className="form-control" placeholder="e.g. 3-5 days" value={form.estimated_delivery_time} onChange={(e) => setForm({ ...form, estimated_delivery_time: e.target.value })} />
            </div>
          </div>
          <div className="mp-form-field mb-3">
            <label>Description</label>
            <textarea className="form-control" rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          </div>
          <div className="mp-form-field mb-3" style={{ maxWidth: 220 }}>
            <label>Minimum Order</label>
            <input type="number" min={1} className="form-control" value={form.min_order} onChange={(e) => setForm({ ...form, min_order: e.target.value })} />
          </div>
          <div className="form-check mb-3">
            <input
              type="checkbox"
              className="form-check-input"
              id="service-customization"
              checked={form.customization_available}
              onChange={(e) => setForm({ ...form, customization_available: e.target.checked })}
            />
            <label className="form-check-label" htmlFor="service-customization">Customization available</label>
          </div>
          <button type="submit" className="btn btn-mp-primary" disabled={saving}>
            {saving ? 'Adding…' : '+ Add Service'}
          </button>
        </form>
      </div>

      {status === 'loading' && <LoadingState />}
      {status === 'ready' && services.length === 0 && <EmptyState title="No services yet." />}
      {status === 'ready' && services.length > 0 && (
        <div className="mp-card">
          {services.map((service) => (
            <div key={service.id} className="d-flex justify-content-between align-items-center p-3 border-bottom">
              <div>
                <div className="fw-semibold">{service.attributes.name}</div>
                <div className="text-muted small">
                  {service.attributes.estimated_delivery_time && `${service.attributes.estimated_delivery_time} · `}
                  {!service.attributes.is_active && 'Inactive'}
                </div>
              </div>
              <div className="d-flex gap-2">
                <button type="button" className="btn btn-mp-outline btn-sm" onClick={() => toggleActive(service)}>
                  {service.attributes.is_active ? 'Deactivate' : 'Activate'}
                </button>
                <button type="button" className="btn btn-mp-outline btn-sm text-danger" onClick={() => remove(service)}>
                  Delete
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
