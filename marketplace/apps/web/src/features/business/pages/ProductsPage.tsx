import { FormEvent, useEffect, useState } from 'react';
import { api, getErrorMessage } from '../../../lib/api/client';
import type { ApiEnvelope, ApiProduct, PaginatedResponse } from '../../../types/api';
import LoadingState from '../../../components/ui/LoadingState';
import EmptyState from '../../../components/ui/EmptyState';

interface ProductFormState {
  name: string;
  description: string;
  unit: string;
  min_order: string;
  max_order: string;
  capacity: string;
  customization_available: boolean;
}

const EMPTY_FORM: ProductFormState = {
  name: '',
  description: '',
  unit: '',
  min_order: '',
  max_order: '',
  capacity: '',
  customization_available: false,
};

export default function ProductsPage() {
  const [products, setProducts] = useState<ApiProduct[]>([]);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [form, setForm] = useState<ProductFormState>(EMPTY_FORM);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const load = () => {
    setStatus('loading');
    api
      .get<PaginatedResponse<ApiProduct> | ApiEnvelope<ApiProduct[]>>('/users/me/products')
      .then((res) => {
        setProducts(res.data.data);
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
      await api.post('/products', {
        name: form.name,
        description: form.description || null,
        unit: form.unit || null,
        min_order: form.min_order ? Number(form.min_order) : null,
        max_order: form.max_order ? Number(form.max_order) : null,
        capacity: form.capacity || null,
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

  const toggleActive = async (product: ApiProduct) => {
    await api.patch(`/products/${product.id}`, { is_active: !product.attributes.is_active });
    load();
  };

  const remove = async (product: ApiProduct) => {
    await api.delete(`/products/${product.id}`);
    load();
  };

  return (
    <div>
      <h1 className="h5 mb-3">Products</h1>
      <p className="text-muted small mb-4">
        Define what your business makes or sells — a general catalog capability, separate from individual marketplace listings.
      </p>

      <div className="mp-card p-4 mb-4">
        {error && <div className="alert alert-danger">{error}</div>}
        <form onSubmit={handleSubmit}>
          <div className="row g-3 mb-3">
            <div className="col-md-6 mp-form-field">
              <label>Product Name</label>
              <input className="form-control" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </div>
            <div className="col-md-6 mp-form-field">
              <label>Unit</label>
              <input className="form-control" placeholder="e.g. piece, kg, box" value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })} />
            </div>
          </div>
          <div className="mp-form-field mb-3">
            <label>Description</label>
            <textarea className="form-control" rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          </div>
          <div className="row g-3 mb-3">
            <div className="col-md-4 mp-form-field">
              <label>Minimum Order</label>
              <input type="number" min={1} className="form-control" value={form.min_order} onChange={(e) => setForm({ ...form, min_order: e.target.value })} />
            </div>
            <div className="col-md-4 mp-form-field">
              <label>Maximum Order</label>
              <input type="number" min={1} className="form-control" value={form.max_order} onChange={(e) => setForm({ ...form, max_order: e.target.value })} />
            </div>
            <div className="col-md-4 mp-form-field">
              <label>Capacity</label>
              <input className="form-control" placeholder="e.g. 1000 units/month" value={form.capacity} onChange={(e) => setForm({ ...form, capacity: e.target.value })} />
            </div>
          </div>
          <div className="form-check mb-3">
            <input
              type="checkbox"
              className="form-check-input"
              id="customization"
              checked={form.customization_available}
              onChange={(e) => setForm({ ...form, customization_available: e.target.checked })}
            />
            <label className="form-check-label" htmlFor="customization">Customization available</label>
          </div>
          <button type="submit" className="btn btn-mp-primary" disabled={saving}>
            {saving ? 'Adding…' : '+ Add Product'}
          </button>
        </form>
      </div>

      {status === 'loading' && <LoadingState />}
      {status === 'ready' && products.length === 0 && <EmptyState title="No products yet." />}
      {status === 'ready' && products.length > 0 && (
        <div className="mp-card">
          {products.map((product) => (
            <div key={product.id} className="d-flex justify-content-between align-items-center p-3 border-bottom">
              <div>
                <div className="fw-semibold">{product.attributes.name}</div>
                <div className="text-muted small">
                  {product.attributes.unit && `${product.attributes.unit} · `}
                  {product.attributes.min_order && `Min order ${product.attributes.min_order}`}
                  {!product.attributes.is_active && ' · Inactive'}
                </div>
              </div>
              <div className="d-flex gap-2">
                <button type="button" className="btn btn-mp-outline btn-sm" onClick={() => toggleActive(product)}>
                  {product.attributes.is_active ? 'Deactivate' : 'Activate'}
                </button>
                <button type="button" className="btn btn-mp-outline btn-sm text-danger" onClick={() => remove(product)}>
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
