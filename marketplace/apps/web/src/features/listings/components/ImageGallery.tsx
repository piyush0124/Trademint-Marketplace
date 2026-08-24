import { useState } from 'react';
import type { ApiMedia } from '../../../types/api';

interface ImageGalleryProps {
  media: ApiMedia[];
  title: string;
}

export default function ImageGallery({ media, title }: ImageGalleryProps) {
  const [activeIndex, setActiveIndex] = useState(0);

  if (media.length === 0) {
    return (
      <div className="mp-gallery-main d-flex align-items-center justify-content-center text-muted">
        No photos have been added yet
      </div>
    );
  }

  const active = media[activeIndex] ?? media[0];

  return (
    <div>
      <div className="mp-gallery-main">
        {active.attributes.url && <img src={active.attributes.url} alt={`${title} — photo ${activeIndex + 1}`} />}
      </div>
      {media.length > 1 && (
        <div className="mp-gallery-thumbs">
          {media.map((item, index) => (
            <img
              key={item.id}
              src={item.attributes.url ?? ''}
              alt=""
              className={index === activeIndex ? 'is-active' : ''}
              onClick={() => setActiveIndex(index)}
            />
          ))}
        </div>
      )}
    </div>
  );
}
