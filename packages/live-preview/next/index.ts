'use client';

import { useEffect, useState, useRef } from 'react';
import { subscribe, type LivePreviewOptions } from '../core/index.js';

interface UseLivePreviewArgs {
  initialData: Record<string, any>;
}

/**
 * Hook Next.js pour le Live Preview.
 *
 * Usage dans une page ou un composant client :
 *   const { data, isPreview } = useLivePreview({ initialData });
 *
 * - isPreview : true quand la page est chargée dans l'iframe admin
 * - data : fusion de initialData + mises à jour postMessage
 */
export function useLivePreview({ initialData }: UseLivePreviewArgs) {
  const [data, setData] = useState<Record<string, any>>(initialData);
  const [isPreview, setIsPreview] = useState(false);
  const initDataRef = useRef(initialData);

  useEffect(() => {
    const unsub = subscribe({
      onInit: async (ctx) => {
        const res = await fetch(
          `/api/preview/content/${ctx.collection}/${ctx.entryUuid}`,
          {
            headers: {
              Authorization: `Bearer ${ctx.token}`,
            },
          }
        );
        if (!res.ok) throw new Error(`Preview API returned ${res.status}`);
        return res.json();
      },
      onUpdate: (updatedData) => {
        setData(updatedData);
      },
      debug: process.env.NODE_ENV === 'development',
    });

    // Si jambo_preview est présent dans l'URL, c'est le mode preview
    if (typeof window !== 'undefined') {
      const params = new URLSearchParams(window.location.search);
      setIsPreview(params.has('jambo_preview'));
    }

    return unsub;
  }, []);

  return { data, isPreview };
}
