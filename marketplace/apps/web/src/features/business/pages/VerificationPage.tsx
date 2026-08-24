import { useEffect, useRef, useState } from 'react';
import { api, getAuthToken, getErrorMessage } from '../../../lib/api/client';
import type { VerificationStateResponse } from '../../../types/api';
import LoadingState from '../../../components/ui/LoadingState';
import ErrorState from '../../../components/ui/ErrorState';

type VerificationState = VerificationStateResponse['data'];

/**
 * The business owner's side of verification: upload documents, ask for review, see
 * the outcome.
 *
 * Nothing here grants verification — that is an admin action, and the API refuses it
 * from this role no matter what this page sends. The document type list, accepted
 * MIME types and size cap all come from the API so this form can't drift from the
 * validator.
 *
 * Documents are never linked publicly. Download goes through an authorised route and
 * is fetched with the bearer token, so a leaked URL is worthless on its own.
 */
function humanise(value: string): string {
  return value.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
}

export default function VerificationPage() {
  const [state, setState] = useState<VerificationState | null>(null);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error' | 'no-business'>('loading');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [docType, setDocType] = useState('');
  const [docTitle, setDocTitle] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const fileInput = useRef<HTMLInputElement>(null);

  const load = () => {
    setStatus('loading');
    api
      .get<VerificationStateResponse>('/businesses/verification')
      .then((res) => {
        setState(res.data.data);
        setDocType((current) => current || res.data.data.document_types[0] || '');
        setStatus('ready');
      })
      .catch((err) => setStatus(err?.response?.status === 404 ? 'no-business' : 'error'));
  };

  useEffect(load, []);

  const upload = async () => {
    if (!file || !docType) return;
    setError('');
    setBusy(true);
    try {
      const form = new FormData();
      form.append('type', docType);
      form.append('file', file);
      if (docTitle.trim()) form.append('title', docTitle.trim());

      await api.post('/businesses/documents', form, { headers: { 'Content-Type': 'multipart/form-data' } });

      setFile(null);
      setDocTitle('');
      if (fileInput.current) fileInput.current.value = '';
      load();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const requestReview = async () => {
    setError('');
    setBusy(true);
    try {
      await api.post('/businesses/verification');
      load();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  /**
   * Documents live on a private disk, so we fetch with the auth header and hand the
   * browser a blob rather than pointing an <a> at a URL that would 403.
   */
  const download = async (id: string, label: string) => {
    setError('');
    try {
      const res = await api.get(`/business-documents/${id}/download`, {
        responseType: 'blob',
        headers: { Authorization: `Bearer ${getAuthToken() ?? ''}` },
      });
      const url = URL.createObjectURL(res.data as Blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = label;
      link.click();
      URL.revokeObjectURL(url);
    } catch (err) {
      setError(getErrorMessage(err));
    }
  };

  if (status === 'loading') return <LoadingState variant="list" rows={4} />;
  if (status === 'no-business') {
    return <ErrorState message="Create your business profile before requesting verification." />;
  }
  if (status === 'error' || state === null) return <ErrorState onRetry={load} />;

  const maxMb = (state.max_file_size_kb / 1024).toFixed(0);

  return (
    <div>
      <div className="mb-3">
        <h1 className="h5 mb-1">Verification</h1>
        <p className="text-muted small mb-0">
          Verified businesses rank higher in discovery and matching, and customers can see they were vetted.
        </p>
      </div>

      {error && <div className="alert alert-danger py-2 small" role="alert">{error}</div>}

      <div className="mp-card p-4 mb-3">
        <div className="d-flex justify-content-between align-items-start flex-wrap gap-2">
          <div>
            <div className="small text-muted">Current status</div>
            <span className={`mp-status-badge mp-status-${state.status}`}>{state.status_label}</span>
          </div>
          <div className="text-end small text-muted">
            {state.requested_at && <div>Requested {state.requested_at.slice(0, 10)}</div>}
            {state.verified_at && <div>Verified {state.verified_at.slice(0, 10)}</div>}
          </div>
        </div>

        {state.status === 'suspended' && (
          <div className="alert alert-danger py-2 small mt-3 mb-0" role="alert">
            <strong>Suspended.</strong> {state.suspension_reason ?? 'Contact support for details.'}
          </div>
        )}

        {state.status === 'rejected' && state.notes && (
          <div className="alert alert-warning py-2 small mt-3 mb-0">
            <strong>Not approved.</strong> {state.notes} You can upload corrected documents and request review again.
          </div>
        )}

        {state.status === 'pending' && (
          <p className="small text-muted mt-3 mb-0">
            Your documents are queued for review. You can still add more while you wait.
          </p>
        )}

        {state.can_request && (
          <button type="button" className="btn btn-mp-primary btn-sm mt-3" disabled={busy || state.documents.length === 0} onClick={requestReview}>
            {busy ? 'Submitting…' : 'Request verification'}
          </button>
        )}
        {state.can_request && state.documents.length === 0 && (
          <div className="form-text small">Upload at least one document first.</div>
        )}
      </div>

      <div className="mp-card p-4 mb-3">
        <h2 className="h6 mb-3">Upload a document</h2>
        <div className="row g-2">
          <div className="col-12 col-sm-4">
            <label className="form-label small" htmlFor="doc-type">Type</label>
            <select id="doc-type" className="form-select form-select-sm" value={docType} onChange={(e) => setDocType(e.target.value)}>
              {state.document_types.map((type) => (
                <option key={type} value={type}>{humanise(type)}</option>
              ))}
            </select>
          </div>
          <div className="col-12 col-sm-8">
            <label className="form-label small" htmlFor="doc-title">Label (optional)</label>
            <input id="doc-title" className="form-control form-control-sm" maxLength={150} value={docTitle} onChange={(e) => setDocTitle(e.target.value)} />
          </div>
          <div className="col-12">
            <label className="form-label small" htmlFor="doc-file">File</label>
            <input
              id="doc-file"
              ref={fileInput}
              type="file"
              className="form-control form-control-sm"
              accept={state.accepted_mime_types.join(',')}
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            />
            <div className="form-text small">PDF, JPG or PNG, up to {maxMb} MB. Only you and an administrator can ever open it.</div>
          </div>
        </div>
        <button type="button" className="btn btn-mp-outline btn-sm mt-3" disabled={busy || !file} onClick={upload}>
          {busy ? 'Uploading…' : 'Upload document'}
        </button>
      </div>

      <div className="mp-card p-4">
        <h2 className="h6 mb-3">Your documents</h2>
        {state.documents.length === 0 ? (
          <p className="small text-muted mb-0">Nothing uploaded yet.</p>
        ) : (
          <div className="d-flex flex-column gap-2">
            {state.documents.map((document) => (
              <div key={document.id} className="d-flex justify-content-between align-items-start flex-wrap gap-2 border-top pt-2">
                <div className="min-width-0">
                  <div className="fw-semibold small">{document.title || humanise(document.type)}</div>
                  <div className="small text-muted">
                    {humanise(document.type)}
                    {document.uploaded_at && ` · uploaded ${document.uploaded_at.slice(0, 10)}`}
                  </div>
                  {document.review_notes && <div className="small text-muted">Reviewer: {document.review_notes}</div>}
                </div>
                <div className="d-flex align-items-center gap-2">
                  <span className={`mp-status-badge mp-status-${document.status}`}>{humanise(document.status)}</span>
                  <button
                    type="button"
                    className="btn btn-sm btn-mp-ghost"
                    onClick={() => download(document.id, `${document.type}-${document.id}`)}
                  >
                    Download
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
