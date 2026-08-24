interface ContactButtonsProps {
  phone?: string | null;
  whatsapp?: string | null;
  onEnquire: () => void;
  layout?: 'inline' | 'sticky';
}

/** Shared Call / WhatsApp / Enquire action row — used both inline and as the mobile sticky CTA. */
export default function ContactButtons({ phone, whatsapp, onEnquire, layout = 'inline' }: ContactButtonsProps) {
  const wrapperClass = layout === 'sticky' ? 'mp-sticky-cta' : 'd-flex gap-2 flex-wrap';

  return (
    <div className={wrapperClass}>
      {phone && (
        <a href={`tel:${phone}`} className="btn btn-mp-outline flex-fill">
          📞 Call
        </a>
      )}
      {whatsapp && (
        <a
          href={`https://wa.me/${whatsapp.replace(/\D/g, '')}`}
          target="_blank"
          rel="noreferrer"
          className="btn btn-mp-outline flex-fill"
        >
          💬 WhatsApp
        </a>
      )}
      <button type="button" className="btn btn-mp-primary flex-fill" onClick={onEnquire}>
        Enquire Now
      </button>
    </div>
  );
}
