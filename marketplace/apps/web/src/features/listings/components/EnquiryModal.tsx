import { FormEvent, useEffect, useState } from 'react';
import { api, getErrorMessage } from '../../../lib/api/client';
import { useAuth } from '../../auth/AuthContext';

interface EnquiryModalProps {
  listingId: string;
  listingTitle: string;
  onClose: () => void;
}

/** The real "Enquire Now" form — creates a lead the owner sees in their CRM inbox. */
export default function EnquiryModal({ listingId, listingTitle, onClose }: EnquiryModalProps) {
  const { user } = useAuth();
  const [name, setName] = useState(user?.attributes.name ?? '');
  const [email, setEmail] = useState(user?.attributes.email ?? '');
  const [phone, setPhone] = useState('');
  const [message, setMessage] = useState(`Hi, I'm interested in "${listingTitle}". Is it still available?`);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [sent, setSent] = useState(false);

  // Let Escape close the modal, like any well-behaved dialog.
  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [onClose]);

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    if (!email.trim() && !phone.trim()) {
      setError('Please provide an email or a phone number so the owner can reach you.');
      return;
    }
    setSubmitting(true);
    setError('');
    try {
      await api.post(`/listings/${listingId}/leads`, { name, email: email || undefined, phone: phone || undefined, message });
      setSent(true);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="mp-modal-overlay" onClick={onClose}>
      <div className="mp-modal-card mp-enquiry-modal" onClick={(e) => e.stopPropagation()}>
        <button type="button" className="mp-modal-close" onClick={onClose} aria-label="Close">
          ✕
        </button>

        {sent ? (
          <div className="mp-enquiry-success">
            <div className="mp-enquiry-success__icon">✓</div>
            <h2 className="h5 mb-1">Enquiry sent!</h2>
            <p className="text-muted mb-4">The owner has been notified and will reach out to you directly.</p>
            <button type="button" className="btn btn-mp-primary" onClick={onClose}>
              Done
            </button>
          </div>
        ) : (
          <>
            <div className="mp-enquiry-modal__header">
              <span className="mp-eyebrow">Get in touch</span>
              <h2 className="h5 mb-1">Enquire about this listing</h2>
              <p className="text-muted small mb-0 text-truncate">{listingTitle}</p>
            </div>

            {error && <div className="alert alert-danger mt-3">{error}</div>}

            <form onSubmit={handleSubmit} className="mt-3">
              <div className="mp-form-field mb-3">
                <label>Your Name</label>
                <input className="form-control" required value={name} onChange={(e) => setName(e.target.value)} />
              </div>
              <div className="row g-3 mb-3">
                <div className="col-6 mp-form-field">
                  <label>Email</label>
                  <input type="email" className="form-control" value={email} onChange={(e) => setEmail(e.target.value)} />
                </div>
                <div className="col-6 mp-form-field">
                  <label>Phone</label>
                  <input className="form-control" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="10-digit mobile" />
                </div>
              </div>
              <div className="mp-form-field mb-4">
                <label>Message</label>
                <textarea className="form-control" rows={3} value={message} onChange={(e) => setMessage(e.target.value)} />
              </div>
              <button type="submit" className="btn btn-mp-primary w-100" disabled={submitting}>
                {submitting ? 'Sending…' : 'Send Enquiry'}
              </button>
              <p className="text-muted small text-center mt-2 mb-0">The owner will see your contact details and message.</p>
            </form>
          </>
        )}
      </div>
    </div>
  );
}
