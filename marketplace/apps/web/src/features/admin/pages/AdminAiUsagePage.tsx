import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../../lib/api/client';
import type { AiUsageReport } from '../../../types/ai';
import type { AiStatus } from '../../../types/ai';
import LoadingState from '../../../components/ui/LoadingState';
import ErrorState from '../../../components/ui/ErrorState';

/**
 * AI usage and spend, for whoever owns the budget.
 *
 * Reads the `ai_requests` ledger, which holds no prompt or reply text — so this page can
 * be shown to a finance or ops reader without also showing them customers' requirements.
 * That separation was the reason for keeping content out of the ledger in the first place.
 *
 * A zero cost on a local-Ollama deployment is truthful rather than a placeholder: local
 * inference genuinely costs nothing, and the per-1K rates are set from the deployment's
 * own billing page when a hosted provider is configured.
 */
const WINDOWS = [1, 7, 30, 90];

export default function AdminAiUsagePage() {
  const [report, setReport] = useState<AiUsageReport | null>(null);
  const [status, setStatus] = useState<AiStatus | null>(null);
  const [days, setDays] = useState(7);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');

  const load = () => {
    setState('loading');

    Promise.all([
      api.get<{ data: AiUsageReport }>('/admin/ai/usage', { params: { days } }),
      api.get<{ data: AiStatus }>('/ai/status').catch(() => null),
    ])
      .then(([usageRes, statusRes]) => {
        setReport(usageRes.data.data);
        setStatus(statusRes?.data.data ?? null);
        setState('ready');
      })
      .catch(() => setState('error'));
  };

  useEffect(load, [days]);

  if (state === 'loading') return <div className="mp-container py-4"><LoadingState variant="list" rows={5} /></div>;
  if (state === 'error' || report === null) return <div className="mp-container py-4"><ErrorState onRetry={load} /></div>;

  const successRate = report.totals.requests > 0
    ? Math.round((report.totals.successful / report.totals.requests) * 100)
    : null;

  return (
    <div className="mp-container py-4">
      <div className="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
        <div>
          <span className="mp-eyebrow">AI operations</span>
          <h1 className="mt-1 mb-1" style={{ fontSize: 'var(--mp-text-h1)' }}>Usage &amp; spend</h1>
          <p className="text-muted mb-0">
            No prompt or reply text is stored in this ledger, so nothing here exposes a conversation.
          </p>
        </div>
        <div className="d-flex gap-2 align-items-center">
          <Link to="/admin" className="btn btn-sm btn-mp-outline">Overview</Link>
          <label className="visually-hidden" htmlFor="usage-window">Window</label>
          <select id="usage-window" className="form-select" style={{ width: 150 }} value={days} onChange={(e) => setDays(Number(e.target.value))}>
            {WINDOWS.map((option) => (
              <option key={option} value={option}>Last {option} {option === 1 ? 'day' : 'days'}</option>
            ))}
          </select>
        </div>
      </div>

      {/* Provider state up front — a healthy-looking cost report on a dead provider is
          misleading. */}
      {status !== null && (
        <div className={`alert py-2 small ${status.available ? 'alert-success' : 'alert-warning'}`} role="status">
          {status.enabled
            ? status.available
              ? `Provider ${status.provider} is reachable, using model ${status.model}.`
              : `AI is enabled but provider ${status.provider} is not reachable. Users are seeing the fallback, and the marketplace is unaffected.`
            : 'AI is switched off. Every marketplace feature continues to work normally.'}
        </div>
      )}

      <div className="row g-3 mb-4">
        {[
          { label: 'Requests', value: report.totals.requests },
          { label: 'Success Rate', value: successRate === null ? '—' : `${successRate}%` },
          { label: 'Tokens', value: report.totals.tokens.toLocaleString('en-IN') },
          { label: 'Estimated Cost', value: report.totals.estimated_cost },
          { label: 'Avg Latency', value: `${report.totals.average_latency_ms} ms` },
          { label: 'Failed', value: report.totals.failed },
        ].map((stat) => (
          <div key={stat.label} className="col-6 col-md-4 col-lg-2">
            <div className="mp-stat-card">
              <div className="mp-stat-card__value" style={{ fontSize: '1.4rem' }}>{stat.value}</div>
              <div className="mp-stat-card__label">{stat.label}</div>
            </div>
          </div>
        ))}
      </div>

      <div className="row g-3">
        <div className="col-12 col-lg-7">
          <div className="mp-card p-4 h-100">
            <h2 className="h6 mb-1">By feature</h2>
            <p className="small text-muted">Which surfaces are spending the budget. This is where a cheaper model or a cache pays off.</p>
            <div className="table-responsive">
              <table className="table table-sm mb-0 align-middle">
                <thead>
                  <tr>
                    <th scope="col">Feature</th>
                    <th scope="col" className="text-end">Requests</th>
                    <th scope="col" className="text-end">Tokens</th>
                    <th scope="col" className="text-end">Cost</th>
                    <th scope="col" className="text-end">Latency</th>
                  </tr>
                </thead>
                <tbody>
                  {report.by_feature.length === 0 && (
                    <tr><td colSpan={5} className="text-muted small">No AI requests in this window.</td></tr>
                  )}
                  {report.by_feature.map((row) => (
                    <tr key={row.feature}>
                      <td className="small">{row.feature.replace(/_/g, ' ')}</td>
                      <td className="small text-end">{row.requests}</td>
                      <td className="small text-end">{row.tokens.toLocaleString('en-IN')}</td>
                      <td className="small text-end">{row.estimated_cost}</td>
                      <td className="small text-end">{row.average_latency_ms} ms</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div className="col-12 col-lg-5">
          <div className="mp-card p-4 mb-3">
            <h2 className="h6 mb-3">By outcome</h2>
            <dl className="mp-quote-totals mb-0">
              {report.by_status.map((row) => (
                <div key={row.status}>
                  <dt>{row.status.replace(/_/g, ' ')}</dt>
                  <dd>{row.requests}</dd>
                </div>
              ))}
              {report.by_status.length === 0 && <div><dt>No data</dt><dd>—</dd></div>}
            </dl>
          </div>

          <div className="mp-card p-4">
            <h2 className="h6 mb-1">Heaviest users</h2>
            {/* By id, not name — a distribution does not need to identify anyone. */}
            <p className="small text-muted">Shown by account id; a usage report does not need to name people.</p>
            <dl className="mp-quote-totals mb-0">
              {report.top_users.map((row) => (
                <div key={row.user_id}>
                  <dt>#{row.user_id} ({row.role ?? 'system'})</dt>
                  <dd>{row.requests}</dd>
                </div>
              ))}
              {report.top_users.length === 0 && <div><dt>No data</dt><dd>—</dd></div>}
            </dl>
          </div>
        </div>
      </div>
    </div>
  );
}
