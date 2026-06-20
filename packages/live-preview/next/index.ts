'use client';

import { useEffect, useState, useRef } from 'react';
import { subscribe, initVisualEditing } from '../core/index.js';

interface UseLivePreviewArgs {
  initialData: Record<string, any>;
}

/**
 * Hook Next.js pour le Live Preview avec edition visuelle.
 *
 * Usage :
 *   const { data, isPreview, fieldProps } = useLivePreview({ initialData });
 *
 *   <h1 {...fieldProps('title', 'text')}>{data.title}</h1>
 */
export function useLivePreview({ initialData }: UseLivePreviewArgs) {
  const [data, setData] = useState<Record<string, any>>(initialData);
  const [isPreview, setIsPreview] = useState(false);
  const collectionRef = useRef<string>('');

  useEffect(() => {
    if (typeof window !== 'undefined') {
      const params = new URLSearchParams(window.location.search);
      setIsPreview(params.has('jambo_preview'));
      collectionRef.current = params.get('jambo_collection') || '';
    }
  }, []);

  useEffect(() => {
    if (!isPreview) return;

    const unsub = subscribe({
      onInit: async (ctx) => {
        const projectUuid = ctx.projectUuid || '';
        const res = await fetch(
          `/api/projects/${encodeURIComponent(projectUuid)}/preview/content/${encodeURIComponent(ctx.collection)}/${encodeURIComponent(ctx.entryUuid)}`,
          {
            headers: { Authorization: `Bearer ${ctx.token}` },
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

    // Initialize visual editing
    const visCleanup = initVisualEditing({
      allowedOrigin: window.location.ancestorOrigins?.[0] || (document.referrer ? new URL(document.referrer).origin : ''),
      inlineEditEnabled: true,
      debug: process.env.NODE_ENV === 'development',
    });

    return () => {
      unsub();
      visCleanup();
    };
  }, [isPreview]);

  /**
   * Generate data-jambo-* attributes for visual editing.
   */
  const fieldProps = (slug: string, type?: string) => ({
    'data-jambo-field': slug,
    'data-jambo-collection': collectionRef.current,
    ...(type ? { 'data-jambo-type': type } : {}),
  });

  return { data, isPreview, fieldProps };
}
