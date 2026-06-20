'use client';

import { useEffect, useState } from 'react';
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
 * - isPreview : true quand la page est chargee dans l'iframe admin
 * - data : fusion de initialData + mises a jour postMessage
 */
export function useLivePreview({ initialData }: UseLivePreviewArgs) {
  const [data, setData] = useState<Record<string, any>>(initialData);
  const [isPreview, setIsPreview] = useState(false);

  useEffect(() => {
    const unsub = subscribe({
      onInit: async (ctx) => {
        const projectUuid = ctx.projectUuid || '';
        const res = await fetch(
          `/api/projects/${encodeURIComponent(projectUuid)}/preview/content/${encodeURIComponent(ctx.collection)}/${encodeURIComponent(ctx.entryUuid)}`,
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

    // Si jambo_preview est present dans l'URL, c'est le mode preview
    if (typeof window !== 'undefined') {
      const params = new URLSearchParams(window.location.search);
      setIsPreview(params.has('jambo_preview'));
    }

    return unsub;
  }, []);

  return { data, isPreview };
}
