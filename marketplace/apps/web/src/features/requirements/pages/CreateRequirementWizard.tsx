import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { api, getErrorMessage } from '../../../lib/api/client';
import { idempotencyHeaders, newIdempotencyKey, withAntiSpamFields } from '../../../lib/antiSpam';
import { Honeypot } from '../../../components/ui/Honeypot';
import { usePrivateSeo } from '../../../lib/useSeo';
import type { ApiCategory, ApiEnvelope, ApiRequirement } from '../../../types/api';
import StatusBadge from '../../../components/ui/StatusBadge';
import AiRequirementBuilder from '../../ai/components/AiRequirementBuilder';

const STEPS = ['Category', 'Describe', 'Quantity', 'Budget', 'Location', 'Required Date', 'Review'];

const URGENCY_OPTIONS: { value: string; label: string }[] = [
  { value: 'low', label: 'Low — no rush' },
  { value: 'normal', label: 'Normal' },
  { value: 'high', label: 'High — needed soon' },
  { value: 'urgent', label: 'Urgent' },
];

export default function CreateRequirementWizard() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();

  // A private view: never indexed, whatever the URL leaks through.
  usePrivateSeo('Post a requirement');

  const [step, setStep] = useState(0);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  /*
   * One key for the lifetime of this wizard, so every retry of the single
   * "create this requirement" intent carries the same value. useState with an
   * initialiser rather than a bare call, so a re-render does not mint a new key
   * and quietly defeat the protection.
   */
  const [idempotencyKey] = useState(newIdempotencyKey);

  const [categories, setCategories] = useState<ApiCategory[]>([]);
  const [categoryId, setCategoryId] = useState<number | null>(null);
  const [requirement, setRequirement] = useState<ApiRequirement | null>(null);

  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');

  const [quantity, setQuantity] = useState('');
  const [unit, setUnit] = useState('');

  const [budgetMin, setBudgetMin] = useState('');
  const [budgetMax, setBudgetMax] = useState('');

  // Free-text location entry — PAN-India, never a fixed city list. The
  // backend resolves/creates the matching Location row itself.
  const [city, setCity] = useState('');
  const [area, setArea] = useState('');
  const [locState, setLocState] = useState('');

  const [requiredDate, setRequiredDate] = useState('');
  const [urgency, setUrgency] = useState('normal');

  useEffect(() => {
    api.get<{ data: ApiCategory[] }>('/categories').then((res) => setCategories(res.data.data));
  }, []);

  // Load an existing draft for editing.
  useEffect(() => {
    if (!id) return;
    api.get<ApiEnvelope<ApiRequirement>>(`/requirements/${id}`).then((res) => {
      const r = res.data.data;
      setRequirement(r);
      setCategoryId(r.relationships.category?.id ?? null);
      setTitle(r.attributes.title ?? '');
      setDescription(r.attributes.description ?? '');
      setQuantity(r.attributes.quantity ?? '');
      setUnit(r.attributes.unit ?? '');
      setBudgetMin(r.attributes.budget_min ?? '');
      setBudgetMax(r.attributes.budget_max ?? '');
      if (r.relationships.location) {
        setCity(r.relationships.location.attributes.city);
        setArea(r.relationships.location.attributes.area ?? '');
        setLocState(r.relationships.location.attributes.state ?? '');
      }
      setRequiredDate(r.attributes.required_date ?? '');
      setUrgency(r.attributes.urgency ?? 'normal');
      setStep(1);
    });
  }, [id]);

  const persist = async (payload: Record<string, unknown>) => {
    setSaving(true);
    setError('');
    try {
      if (!requirement) {
        /*
         * Creation carries the anti-spam fields and an idempotency key.
         *
         * The key is generated once per wizard instance (see `idempotencyKey`
         * above) and reused for every retry of this one creation. Generating a
         * fresh key per attempt would make each retry look like a new intent,
         * which is exactly the double-submit the header exists to prevent — a
         * user who double-clicks "Continue", or whose connection drops after the
         * request lands, would otherwise end up with two requirements fanned out
         * to suppliers.
         *
         * Only the POST needs this. The PATCH below targets an existing record by
         * id, so a repeat is already idempotent by construction.
         */
        const body = await withAntiSpamFields({ category_id: categoryId, ...payload });
        const res = await api.post<ApiEnvelope<ApiRequirement>>('/requirements', body, {
          headers: idempotencyHeaders(idempotencyKey),
        });
        setRequirement(res.data.data);
        return res.data.data;
      }
      const res = await api.patch<ApiEnvelope<ApiRequirement>>(`/requirements/${requirement.id}`, payload);
      setRequirement(res.data.data);
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
        if (!requirement) await persist({});
        setStep(1);
        return;
      }
      if (step === 1) {
        await persist({ title, description: description || null });
      }
      if (step === 2) {
        await persist({ quantity: quantity ? Number(quantity) : null, unit: unit || null });
      }
      if (step === 3) {
        await persist({
          budget_min: budgetMin ? Number(budgetMin) : null,
          budget_max: budgetMax ? Number(budgetMax) : null,
        });
      }
      if (step === 4) {
        await persist({ location: city ? { city, area, state: locState, country: 'IN' } : null });
      }
      if (step === 5) {
        await persist({ required_date: requiredDate || null, urgency });
      }
      setStep((s) => Math.min(s + 1, STEPS.length - 1));
    } catch {
      // error already surfaced
    }
  };

  const handlePublish = async () => {
    if (!requirement) return;
    setSaving(true);
    setError('');
    try {
      const res = await api.post<ApiEnvelope<ApiRequirement>>(`/requirements/${requirement.id}/publish`);
      setRequirement(res.data.data);
      navigate('/dashboard/requirements');
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  const saveAndExit = () => navigate('/dashboard/requirements');

  return (
    <div className="mp-container py-4" style={{ maxWidth: 780 }}>
      <div className="d-flex justify-content-between align-items-center mb-3">
        <h1 className="h4 mb-0">{requirement ? 'Edit Requirement' : 'What are you looking for?'}</h1>
        {requirement && <StatusBadge status={requirement.attributes.status} />}
      </div>

      <div className="mp-wizard-steps">
        {STEPS.map((label, index) => (
          <div key={label} className={`mp-wizard-step ${index === step ? 'is-active' : index < step ? 'is-complete' : ''}`}>
            {index + 1}. {label}
          </div>
        ))}
      </div>

      {/*
        * Rendered once for the whole wizard rather than per step. It is off-screen
        * and out of the accessibility tree, so its position in the markup is
        * irrelevant to a human — but a bot that fills every input on the page will
        * still find it whichever step is showing.
        */}
      <Honeypot />

      {error && (
        <div className="alert alert-danger" role="alert" aria-live="polite">
          {error}
        </div>
      )}

      {/*
        AI shortcut, offered only on a new requirement — re-extracting over an existing
        draft would overwrite edits the buyer has already made by hand.

        It fills these same form fields; it does not submit. The buyer reviews every value
        and creates the requirement themselves, so the AI saves typing without taking
        ownership of what gets stored. Renders nothing when AI is unavailable.
      */}
      {step === 0 && requirement === null && (
        <AiRequirementBuilder
          onApply={(draft) => {
            // Matched against the loaded categories, so an unknown slug simply leaves the
            // picker untouched rather than setting an id that does not exist.
            const matched = categories.find((category) => category.attributes.slug === draft.category);
            if (matched) setCategoryId(matched.id);

            if (draft.title) setTitle(draft.title);
            if (draft.description) setDescription(draft.description);
            if (draft.quantity !== null) setQuantity(String(draft.quantity));
            if (draft.unit) setUnit(draft.unit);
            if (draft.budget_min !== null) setBudgetMin(String(draft.budget_min));
            if (draft.budget_max !== null) setBudgetMax(String(draft.budget_max));
            if (draft.city) setCity(draft.city);
            if (draft.state) setLocState(draft.state);
            if (draft.required_date) setRequiredDate(draft.required_date);
            if (draft.urgency) setUrgency(draft.urgency);

            // Straight to the details step so the buyer lands on what to check.
            if (matched) setStep(1);
          }}
        />
      )}

      {step === 0 && (
        <div className="mp-card p-4">
          <p className="text-muted mb-3">Pick the category that best matches what you need. No listing to choose — just tell us the category and we'll find businesses for you.</p>
          <div className="row g-3">
            {categories.map((cat) => (
              <div key={cat.id} className="col-6 col-md-4">
                <button
                  type="button"
                  className="mp-category-pill w-100"
                  style={{ borderColor: categoryId === cat.id ? 'var(--mp-primary)' : undefined }}
                  onClick={() => setCategoryId(cat.id)}
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
            <input className="form-control" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="e.g. Need 500 corrugated shipping boxes" />
          </div>
          <div className="mp-form-field mb-3">
            <label>Description</label>
            <textarea className="form-control" rows={4} value={description} onChange={(e) => setDescription(e.target.value)} placeholder="Describe what you need in your own words" />
          </div>
        </div>
      )}

      {step === 2 && (
        <div className="mp-card p-4">
          <div className="row g-3">
            <div className="col-8 mp-form-field">
              <label>Quantity</label>
              <input type="number" min={0} className="form-control" value={quantity} onChange={(e) => setQuantity(e.target.value)} />
            </div>
            <div className="col-4 mp-form-field">
              <label>Unit</label>
              <input className="form-control" value={unit} onChange={(e) => setUnit(e.target.value)} placeholder="e.g. pieces, kg" />
            </div>
          </div>
        </div>
      )}

      {step === 3 && (
        <div className="mp-card p-4">
          <p className="text-muted mb-3">Give businesses a realistic budget range so quotes come back relevant. Optional, but recommended.</p>
          <div className="row g-3">
            <div className="col-6 mp-form-field">
              <label>Minimum Budget (₹)</label>
              <input type="number" min={0} className="form-control" value={budgetMin} onChange={(e) => setBudgetMin(e.target.value)} />
            </div>
            <div className="col-6 mp-form-field">
              <label>Maximum Budget (₹)</label>
              <input type="number" min={0} className="form-control" value={budgetMax} onChange={(e) => setBudgetMax(e.target.value)} />
            </div>
          </div>
        </div>
      )}

      {step === 4 && (
        <div className="mp-card p-4">
          <div className="mp-form-field mb-3">
            <label>City</label>
            <input className="form-control" value={city} onChange={(e) => setCity(e.target.value)} placeholder="e.g. Ghaziabad, Coimbatore, Guwahati…" />
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

      {step === 5 && (
        <div className="mp-card p-4">
          <div className="row g-3">
            <div className="col-6 mp-form-field">
              <label>Required By</label>
              <input type="date" className="form-control" value={requiredDate} onChange={(e) => setRequiredDate(e.target.value)} />
            </div>
            <div className="col-6 mp-form-field">
              <label>Urgency</label>
              <select className="form-select" value={urgency} onChange={(e) => setUrgency(e.target.value)}>
                {URGENCY_OPTIONS.map((opt) => (
                  <option key={opt.value} value={opt.value}>{opt.label}</option>
                ))}
              </select>
            </div>
          </div>
        </div>
      )}

      {step === 6 && requirement && (
        <div className="mp-card p-4">
          <h5 className="mb-3">Review your requirement</h5>
          <dl className="row mb-0">
            <dt className="col-4">Category</dt>
            <dd className="col-8">{requirement.relationships.category?.attributes.name ?? categories.find((c) => c.id === categoryId)?.attributes.name}</dd>
            <dt className="col-4">Title</dt>
            <dd className="col-8">{title || '—'}</dd>
            <dt className="col-4">Quantity</dt>
            <dd className="col-8">{quantity || '—'} {unit}</dd>
            <dt className="col-4">Budget</dt>
            <dd className="col-8">{budgetMin || budgetMax ? `₹${budgetMin || '0'} – ₹${budgetMax || '—'}` : 'Not set'}</dd>
            <dt className="col-4">Location</dt>
            <dd className="col-8">{city ? `${city}${area ? ', ' + area : ''}${locState ? ', ' + locState : ''}` : '—'}</dd>
            <dt className="col-4">Required By</dt>
            <dd className="col-8">{requiredDate || '—'}</dd>
          </dl>
          <p className="text-muted small mt-3 mb-0">
            Publishing makes this requirement visible to businesses so the marketplace can start finding suitable matches. You can cancel it any time after publishing.
          </p>
        </div>
      )}

      <div className="d-flex justify-content-between mt-4">
        <div className="d-flex gap-2">
          {step > 0 && (
            <button type="button" className="btn btn-mp-outline" onClick={() => setStep((s) => Math.max(0, s - 1))} disabled={saving}>
              Back
            </button>
          )}
          <button type="button" className="btn btn-mp-ghost" onClick={saveAndExit} disabled={saving}>
            Save &amp; Exit
          </button>
        </div>

        {step < STEPS.length - 1 ? (
          <button type="button" className="btn btn-mp-primary" onClick={goNext} disabled={saving || (step === 0 && !categoryId)}>
            {saving ? 'Saving…' : 'Next'}
          </button>
        ) : (
          <button type="button" className="btn btn-mp-accent" onClick={handlePublish} disabled={saving}>
            {saving ? 'Publishing…' : 'Publish Requirement'}
          </button>
        )}
      </div>
    </div>
  );
}
