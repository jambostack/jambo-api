import React, { useState, useEffect, useRef, useCallback } from 'react';
import { Loader2, ExternalLink, Monitor, Tablet, Smartphone, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import type { Collection } from '@/types';

type Device = 'desktop' | 'tablet' | 'mobile';

interface LivePreviewPanelProps {
  projectUuid: string;
  collection: Collection;
  entryUuid?: string;
  locale: string;
  formData: Record<string, any>;
  previewUrl: string;
  onClose?: () => void;
  onFieldHover?: (fieldSlug: string) => void;
  onFieldSelect?: (fieldSlug: string) => void;
}

const DEVICE_WIDTHS: Record<Device, string> = {
  desktop: '100%',
  tablet: '768px',
  mobile: '375px',
};

export default function LivePreviewPanel({
  projectUuid,
  collection,
  entryUuid,
  locale,
  formData,
  previewUrl,
  onClose,
  onFieldHover,
  onFieldSelect,
}: LivePreviewPanelProps) {
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [device, setDevice] = useState<Device>('desktop');
  const [iframeReady, setIframeReady] = useState(false);
  const [token, setToken] = useState<string | null>(null);

  // Recuperer le token au montage
  useEffect(() => {
    if (!entryUuid) {
      setLoading(false);
      return;
    }

    fetch(`/api/projects/${encodeURIComponent(projectUuid)}/preview/token/${encodeURIComponent(entryUuid)}`)
      .then(r => r.json())
      .then(data => {
        if (data.token) {
          setToken(data.token);
        } else {
          setError('Token preview non disponible');
          setLoading(false);
        }
      })
      .catch(() => {
        setError('Erreur lors de la recuperation du token');
        setLoading(false);
      });
  }, [projectUuid, entryUuid]);

  // Construire l'URL de l'iframe
  const iframeUrl = entryUuid && token
    ? `${previewUrl}?jambo_preview=${encodeURIComponent(token)}&jambo_entry=${encodeURIComponent(entryUuid)}&jambo_collection=${encodeURIComponent(collection.slug)}&jambo_locale=${encodeURIComponent(locale)}&jambo_project=${encodeURIComponent(projectUuid)}`
    : null;

  // Ecouter les messages postMessage de l'iframe
  useEffect(() => {
    const handler = (event: MessageEvent) => {
      if (!event.data || typeof event.data !== 'object') return;

      switch (event.data.type) {
        case 'jambo-ready':
          setIframeReady(true);
          setLoading(false);
          // Envoyer jambo-init en reponse
          if (iframeRef.current?.contentWindow && token) {
            const targetOrigin = new URL(previewUrl).origin;
            iframeRef.current.contentWindow.postMessage({
              type: 'jambo-init',
              collection: collection.slug,
              entryUuid,
              locale,
              previewToken: token,
              projectUuid,
            }, targetOrigin);
          }
          break;

        case 'jambo-error':
          setError(event.data.error || 'Erreur dans le frontend');
          break;

        // -- v1.14b : Visual Editing ------------------------------------------
        case 'jambo-hover-field':
          onFieldHover?.(event.data.fieldSlug);
          break;

        case 'jambo-select-field':
          onFieldSelect?.(event.data.fieldSlug);
          // Enlever le hover apres 1s
          setTimeout(() => onFieldHover?.(''), 1000);
          break;

        case 'jambo-inline-update':
          // Relayer la mise a jour partielle au ContentForm
          // via le meme mecanisme que les autres changements
          // (sera gere par Show.tsx)
          break;
      }
    };

    window.addEventListener('message', handler);
    return () => window.removeEventListener('message', handler);
  }, [token, collection.slug, entryUuid, locale]);

  // Envoyer jambo-update a chaque changement de formData (debounce 500ms)
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const prevDataRef = useRef<string>('');

  useEffect(() => {
    if (!iframeReady || !iframeRef.current?.contentWindow) return;

    const currentData = JSON.stringify(formData);

    // Sauvegarder le snapshot precedent AVANT de mettre a jour le ref
    const previousData = prevDataRef.current;

    if (currentData === previousData) return;
    prevDataRef.current = currentData;

    if (debounceRef.current) clearTimeout(debounceRef.current);

    debounceRef.current = setTimeout(() => {
      // Comparer avec le snapshot sauvegarde au moment du declenchement
      const changedFields: string[] = [];
      if (previousData) {
        const prevParsed = JSON.parse(previousData);
        for (const key of Object.keys(formData)) {
          if (JSON.stringify(formData[key]) !== JSON.stringify(prevParsed[key])) {
            changedFields.push(key);
          }
        }
      }

      const targetOrigin = new URL(previewUrl).origin;
      iframeRef.current!.contentWindow!.postMessage({
        type: 'jambo-update',
        fields: formData,
        changedFields,
      }, targetOrigin);
    }, 500);

    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, [formData, iframeReady, previewUrl]);

  // Ouvrir dans un nouvel onglet
  const openInNewTab = useCallback(() => {
    if (iframeUrl) window.open(iframeUrl, '_blank');
  }, [iframeUrl]);

  // Pas d'entryUuid = creation
  if (!entryUuid) {
    return (
      <Card className="p-6 text-center text-muted-foreground relative">
        <p>La preview sera disponible apres la creation de l'entree.</p>
        {onClose && (
          <Button variant="ghost" size="icon" className="absolute top-2 right-2" onClick={onClose}>
            <X className="h-4 w-4" />
          </Button>
        )}
      </Card>
    );
  }

  return (
    <Card className="flex flex-col overflow-hidden relative">
      {/* Toolbar */}
      <div className="flex items-center justify-between px-4 py-2 border-b bg-muted/30">
        <div className="flex items-center gap-1">
          <Button
            variant={device === 'desktop' ? 'secondary' : 'ghost'}
            size="icon"
            onClick={() => setDevice('desktop')}
          >
            <Monitor className="h-4 w-4" />
          </Button>
          <Button
            variant={device === 'tablet' ? 'secondary' : 'ghost'}
            size="icon"
            onClick={() => setDevice('tablet')}
          >
            <Tablet className="h-4 w-4" />
          </Button>
          <Button
            variant={device === 'mobile' ? 'secondary' : 'ghost'}
            size="icon"
            onClick={() => setDevice('mobile')}
          >
            <Smartphone className="h-4 w-4" />
          </Button>
        </div>

        <div className="flex items-center gap-1">
          <Button variant="ghost" size="icon" onClick={openInNewTab} title="Ouvrir dans un nouvel onglet">
            <ExternalLink className="h-4 w-4" />
          </Button>
          {onClose && (
            <Button variant="ghost" size="icon" onClick={onClose}>
              <X className="h-4 w-4" />
            </Button>
          )}
        </div>
      </div>

      {/* Error badge */}
      {error && (
        <div className="px-4 py-1 text-xs bg-destructive/10 text-destructive border-b">
          {error}
        </div>
      )}

      {/* Loader */}
      {loading && (
        <div className="flex items-center justify-center py-16">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
          <span className="ml-2 text-muted-foreground">Chargement de la preview...</span>
        </div>
      )}

      {/* Iframe */}
      {iframeUrl && (
        <iframe
          ref={iframeRef}
          src={iframeUrl}
          style={{
            width: DEVICE_WIDTHS[device],
            height: device === 'desktop' ? '100%' : '600px',
            border: 'none',
            flex: 1,
            margin: '0 auto',
            transition: 'width 0.3s ease',
          }}
          title="Live Preview"
          sandbox="allow-scripts allow-same-origin"
        />
      )}
    </Card>
  );
}
