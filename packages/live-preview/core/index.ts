export interface PreviewContext {
  entryUuid: string;
  collection: string;
  locale: string;
  token: string;
  projectUuid: string;
}

export interface LivePreviewOptions {
  onInit: (ctx: PreviewContext) => Promise<Record<string, any>>;
  onUpdate: (data: Record<string, any>) => void;
  debug?: boolean;
  targetOrigin?: string;
}

export function subscribe(options: LivePreviewOptions): () => void {
  const { onInit, onUpdate, debug = false, targetOrigin = '*' } = options;
  let currentData: Record<string, any> = {};

  const log = (...args: any[]) => {
    if (debug) console.log('[jambo-live-preview]', ...args);
  };

  // Extraire les params de l'URL
  const params = new URLSearchParams(window.location.search);
  const previewToken = params.get('jambo_preview');
  const entryUuid = params.get('jambo_entry');
  const collection = params.get('jambo_collection');
  const locale = params.get('jambo_locale') || 'en';
  const projectUuid = params.get('jambo_project') || '';

  if (!previewToken || !entryUuid || !collection) {
    // Pas en mode preview — retourne un noop
    return () => {};
  }

  let ctx: PreviewContext = {
    entryUuid,
    collection,
    locale,
    token: previewToken,
    projectUuid,
  };

  let cleanup = false;

  const messageHandler = async (event: MessageEvent) => {
    if (cleanup) return;
    if (!event.data || typeof event.data !== 'object') return;

    // Verifier l'origine si targetOrigin n'est pas '*'
    if (targetOrigin !== '*' && event.origin !== targetOrigin) {
      log(`Message ignore : origine ${event.origin} non autorisee`);
      return;
    }

    switch (event.data.type) {
      case 'jambo-init':
        log('jambo-init recu', event.data);
        ctx = {
          entryUuid: event.data.entryUuid || ctx.entryUuid,
          collection: event.data.collection || ctx.collection,
          locale: event.data.locale || ctx.locale,
          token: event.data.previewToken || ctx.token,
          projectUuid: event.data.projectUuid || ctx.projectUuid,
        };

        try {
          currentData = await onInit(ctx);
          onUpdate(currentData);
          // Signaler que la preview est prete
          window.parent.postMessage({ type: 'jambo-ready' }, targetOrigin);
        } catch (err: any) {
          log('Erreur onInit', err);
          window.parent.postMessage({
            type: 'jambo-error',
            error: err?.message || 'Initialisation echouee',
          }, targetOrigin);
        }
        break;

      case 'jambo-update':
        log('jambo-update recu', event.data);
        if (event.data.fields) {
          // Shallow merge
          currentData = { ...currentData, ...event.data.fields };
          onUpdate(currentData);
        }
        break;

      case 'jambo-navigate':
        log('jambo-navigate recu', event.data);
        if (event.data.locale && event.data.locale !== ctx.locale) {
          ctx.locale = event.data.locale;
          window.location.search = new URLSearchParams({
            ...Object.fromEntries(params),
            jambo_locale: event.data.locale,
          }).toString();
        }
        break;
    }
  };

  window.addEventListener('message', messageHandler);

  // Envoyer ready au chargement
  log('envoi jambo-ready');
  window.parent.postMessage({ type: 'jambo-ready' }, targetOrigin);

  return () => {
    cleanup = true;
    window.removeEventListener('message', messageHandler);
  };
}
