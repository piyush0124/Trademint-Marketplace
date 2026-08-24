import { useRef, useState } from 'react';
import { api, uploadMedia, getErrorMessage } from '../../../lib/api/client';
import type { ApiMedia } from '../../../types/api';

interface MediaUploaderProps {
  listingUuid: string;
  media: ApiMedia[];
  onChange: (media: ApiMedia[]) => void;
}

/** Drag & drop image upload with reorder, primary-image selection, and delete. */
export default function MediaUploader({ listingUuid, media, onChange }: MediaUploaderProps) {
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState('');
  const [dragOver, setDragOver] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const handleFiles = async (files: FileList | null) => {
    if (!files || files.length === 0) return;
    setUploading(true);
    setError('');
    try {
      let current = media;
      for (const file of Array.from(files)) {
        if (file.size > 5 * 1024 * 1024) {
          setError('Each image must be under 5MB.');
          continue;
        }
        if (!['image/jpeg', 'image/png', 'image/webp', 'image/jpg'].includes(file.type)) {
          setError('Only JPG, PNG and WEBP images are supported.');
          continue;
        }
        const res = await uploadMedia(listingUuid, file);
        current = [...current, res.data.data];
      }
      onChange(current);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setUploading(false);
    }
  };

  const removeImage = async (mediaItem: ApiMedia) => {
    await api.delete(`/listings/${listingUuid}/media/${mediaItem.id}`);
    onChange(media.filter((m) => m.id !== mediaItem.id));
  };

  const setPrimary = async (mediaItem: ApiMedia) => {
    const res = await api.post<{ data: ApiMedia[] }>(`/listings/${listingUuid}/media/${mediaItem.id}/primary`);
    onChange(res.data.data);
  };

  const move = async (index: number, direction: -1 | 1) => {
    const next = [...media];
    const target = index + direction;
    if (target < 0 || target >= next.length) return;
    [next[index], next[target]] = [next[target], next[index]];
    onChange(next);
    await api.post(`/listings/${listingUuid}/media/reorder`, { order: next.map((m) => m.id) });
  };

  return (
    <div>
      <div
        className="mp-card p-4 text-center mb-3"
        style={{ borderStyle: 'dashed', borderColor: dragOver ? 'var(--mp-primary)' : undefined, cursor: 'pointer' }}
        onClick={() => inputRef.current?.click()}
        onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
        onDragLeave={() => setDragOver(false)}
        onDrop={(e) => {
          e.preventDefault();
          setDragOver(false);
          handleFiles(e.dataTransfer.files);
        }}
      >
        <input
          ref={inputRef}
          type="file"
          accept="image/jpeg,image/png,image/webp"
          multiple
          hidden
          onChange={(e) => handleFiles(e.target.files)}
        />
        <div className="fs-2 mb-1">📷</div>
        <p className="mb-0 fw-semibold">Drag & drop photos here, or click to browse</p>
        <p className="small text-muted mb-0">JPG, PNG or WEBP — up to 5MB each</p>
        {uploading && <p className="small text-primary mt-2 mb-0">Uploading…</p>}
        {error && <p className="small text-danger mt-2 mb-0">{error}</p>}
      </div>

      {media.length > 0 && (
        <div className="row g-3">
          {media.map((item, index) => (
            <div key={item.id} className="col-6 col-md-3">
              <div className="mp-card overflow-hidden position-relative">
                <div style={{ aspectRatio: '4/3', overflow: 'hidden' }}>
                  {item.attributes.url && <img src={item.attributes.url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />}
                </div>
                {item.attributes.is_primary && (
                  <span className="mp-badge-featured position-absolute" style={{ top: 8, left: 8 }}>Primary</span>
                )}
                <div className="d-flex justify-content-between align-items-center p-2 gap-1">
                  <button type="button" className="btn btn-sm btn-mp-ghost" onClick={() => move(index, -1)} disabled={index === 0}>◀</button>
                  {!item.attributes.is_primary && (
                    <button type="button" className="btn btn-sm btn-mp-ghost" onClick={() => setPrimary(item)}>Set primary</button>
                  )}
                  <button type="button" className="btn btn-sm btn-mp-ghost" onClick={() => move(index, 1)} disabled={index === media.length - 1}>▶</button>
                </div>
                <button
                  type="button"
                  className="btn btn-sm position-absolute"
                  style={{ top: 6, right: 6, background: 'rgba(255,255,255,0.9)', borderRadius: '50%', width: 28, height: 28, padding: 0 }}
                  onClick={() => removeImage(item)}
                  aria-label="Remove image"
                >
                  ✕
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
