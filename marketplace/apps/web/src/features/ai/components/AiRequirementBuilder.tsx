import { useState } from 'react';
import { api, getErrorMessage } from '../../../lib/api/client';
import type { AiRequirementDraft, AiRequirementMeta } from '../../../types/ai';
import { useAiStatus } from '../useAiStatus';

/**
 * "Describe it and I'll fill the form."
 *
 * Sits above the manual requirement wizard rather than replacing it. The extraction is
 * applied to the form fields the buyer then edits and submits themselves — so the AI
 * saves typing, and the buyer still owns every value that gets stored.
 *
 * Fields the extractor was unsure about are flagged, because the honest failure mode here
 * is a confidently wrong budget. A buyer who can see "check this" next to ₹15,00,000 will
 * catch it; one shown a filled form with no signal will not.
 */
interface AiRequirementBuilderProps {
  /** Applies the extracted values to the parent form. The parent owns the state. */
  onApply: (draft: AiRequirementDraft, meta: AiRequirementMeta) => void;
}

export default function AiRequirementBuilder({ onApply }: AiRequirementBuilderProps) {
  const { isFeatureReady } = useAiStatus();

  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [draft, setDraft] = useState<AiRequirementDraft | null>(null);
  const [meta, setMeta] = useState<AiRequirementMeta | null>(null);

  // Hidden entirely when unavailable. The manual wizard below is untouched.
  if (!isFeatureReady('requirement_builder')) return null;

  const extract = async () => {
    setError('');
    setBusy(true);
    try {
      const res = await api.post<{ data: AiRequirementDraft; meta: AiRequirementMeta }>(
        '/ai/requirement-builder',
        { input: input.trim() }
      );
      setDraft(res.data.data);
      setMeta(res.data.meta);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const money = (value: number | null, currency: string): string =>
    value === null ? '—' : new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 0 }).format(value);

  /** Below 0.7 the field is worth a second look. */
  const isLowConfidence = (field: string): boolean =>
    meta !== null && meta.confidence[field] !== undefined && meta.confidence[field] < 0.7;

  return (
    <div className="mp-ai-builder mb-4">
      <div className="d-flex align-items-center gap-2 mb-2">
        <span className="mp-ai-badge" aria-hidden="true">✦ AI</span>
        <strong className="small">Describe what you need</strong>
      </div>
      <p className="small text-muted mb-2">
        Write it however you like, in English or Hindi. We'll fill in the form below and you can correct anything.
      </p>

      <textarea
        className="form-control"
        rows={3}
        maxLength={4000}
        placeholder="e.g. I need office interior for around 3000 sq ft in Noida. Budget around 15 lakh. Needed in 3 months."
        value={input}
        onChange={(event) => setInput(event.target.value)}
        aria-label="Describe your requirement"
      />

      {error && <div className="alert alert-danger py-2 small mt-2 mb-0" role="alert">{error}</div>}

      <button
        type="button"
        className="btn btn-mp-primary btn-sm mt-2"
        disabled={busy || input.trim().length < 5}
        onClick={extract}
      >
        {busy ? 'Reading…' : 'Fill the form for me'}
      </button>

      {draft !== null && meta !== null && (
        <div className="mp-ai-builder__result mt-3">
          <div className="row g-2 small">
            {[
              { label: 'Category', value: draft.category_name ?? '—', field: 'category' },
              { label: 'City', value: draft.city ?? '—', field: 'location' },
              {
                label: 'Quantity',
                value: draft.quantity !== null ? `${draft.quantity}${draft.unit ? ` ${draft.unit}` : ''}` : '—',
                field: 'quantity',
              },
              {
                label: 'Budget',
                value: draft.budget_min !== null
                  ? `${money(draft.budget_min, draft.currency)} – ${money(draft.budget_max, draft.currency)}`
                  : `up to ${money(draft.budget_max, draft.currency)}`,
                field: 'budget',
              },
              { label: 'Needed by', value: draft.required_date ?? '—', field: 'required_date' },
              { label: 'Urgency', value: draft.urgency, field: 'urgency' },
            ].map((row) => (
              <div key={row.label} className="col-6 col-md-4">
                <div className={`mp-ai-field ${isLowConfidence(row.field) ? 'is-uncertain' : ''}`}>
                  <div className="mp-ai-field__label">
                    {row.label}
                    {isLowConfidence(row.field) && <span className="mp-ai-field__flag"> · check</span>}
                  </div>
                  <div className="mp-ai-field__value">{row.value}</div>
                </div>
              </div>
            ))}
          </div>

          {/* Ambiguous category — real rows only, so a pick is always valid. */}
          {meta.category_options.length > 0 && (
            <div className="mt-3">
              <div className="small fw-semibold mb-1">Which category did you mean?</div>
              <div className="d-flex gap-2 flex-wrap">
                {meta.category_options.map((option) => (
                  <button
                    key={option.slug}
                    type="button"
                    className="btn btn-sm btn-mp-outline"
                    onClick={() => {
                      const chosen = { ...draft, category: option.slug, category_name: option.name };
                      setDraft(chosen);
                      onApply(chosen, meta);
                    }}
                  >
                    {option.name}
                  </button>
                ))}
              </div>
            </div>
          )}

          {meta.questions.length > 0 && (
            <ul className="mp-ai-questions mt-3">
              {meta.questions.map((question) => (
                <li key={question}>{question}</li>
              ))}
            </ul>
          )}

          {!meta.resolution.location_known && draft.city !== null && (
            <p className="small text-muted mt-2 mb-0">
              No supplier is registered in {draft.city} yet. You can still post — matching also
              covers suppliers who serve your city from elsewhere.
            </p>
          )}

          <div className="d-flex gap-2 mt-3 flex-wrap">
            <button type="button" className="btn btn-sm btn-mp-accent" onClick={() => onApply(draft, meta)}>
              Use these details
            </button>
            <button type="button" className="btn btn-sm btn-mp-ghost" onClick={() => { setDraft(null); setMeta(null); }}>
              Discard
            </button>
          </div>

          <p className="small text-muted mt-2 mb-0">{meta.note}</p>
        </div>
      )}
    </div>
  );
}
