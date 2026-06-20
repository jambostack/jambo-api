import { useEffect, useRef } from 'react';
import { toast } from 'sonner';

const STORAGE_PREFIX = 'rt_since_';

function getStoredSince(projectUuid: string): number {
    try {
        const v = sessionStorage.getItem(STORAGE_PREFIX + projectUuid);
        return v !== null ? parseInt(v, 10) || 0 : 0;
    } catch {
        return 0;
    }
}

function storeSince(projectUuid: string, since: number): void {
    try {
        sessionStorage.setItem(STORAGE_PREFIX + projectUuid, String(since));
        // Nettoyer les curseurs obsolètes (> 7 jours sans mise à jour)
        const now = Date.now();
        for (let i = sessionStorage.length - 1; i >= 0; i--) {
            const key = sessionStorage.key(i);
            if (key?.startsWith(STORAGE_PREFIX) && key !== STORAGE_PREFIX + projectUuid) {
                const val = parseInt(sessionStorage.getItem(key) || '0', 10);
                if (now - val > 604800000) { sessionStorage.removeItem(key); }
            }
        }
    } catch {
        // sessionStorage indisponible — ignoré
    }
}

/**
 * Hook temps réel.
 *
 * Mode SSE (prioritaire) : ouvre un EventSource vers le hub Mercure
 * Mode polling (fallback) : fetch toutes les 3 secondes si le SSE échoue
 *
 * Usage : useRealtime(project.uuid);
 */
export function useRealtime(projectUuid?: string) {
    const sinceRef = useRef(projectUuid ? getStoredSince(projectUuid) : 0);
    const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const eventSourceRef = useRef<EventSource | null>(null);

    useEffect(() => {
        if (!projectUuid) return;

        sinceRef.current = getStoredSince(projectUuid);

        let cancelled = false;

        // ── Tente le mode SSE (EventSource) ──────────────────────────
        const trySSE = async () => {
            try {
                // 1. Récupérer le token de souscription
                const tokenResp = await fetch(
                    `/api/projects/${encodeURIComponent(projectUuid)}/realtime/token`,
                    { credentials: 'same-origin' },
                );

                if (!tokenResp.ok) {
                    throw new Error(`Token endpoint returned ${tokenResp.status}`);
                }

                const { token, hub_url, topics } = await tokenResp.json();

                if (!token || !hub_url) {
                    throw new Error('Token response incomplete');
                }

                // 2. Construire l'URL EventSource avec topics
                const url = new URL(hub_url);
                topics.forEach((t: string) => url.searchParams.append('topic', t));
                // Le cookie mercureAuthorization est envoyé automatiquement par le navigateur
                // car il est posé avec Path=/.well-known/mercure

                // 3. Ouvrir l'EventSource
                const es = new EventSource(url.toString());
                eventSourceRef.current = es;

                es.addEventListener('message', (e) => {
                    try {
                        const evt = JSON.parse(e.data);
                        dispatchToast(evt);
                    } catch {
                        // Payload invalide — ignoré
                    }
                });

                es.addEventListener('error', () => {
                    // EventSource a sa propre reconnexion automatique.
                    // Dégrader vers le polling après 3 échecs consécutifs.
                    if (!cancelled && (es.readyState === EventSource.CLOSED)) {
                        es.close();
                        startPolling();
                    }
                });

            } catch {
                // SSE indisponible → fallback polling
                if (!cancelled) {
                    startPolling();
                }
            }
        };

        // ── Fallback : polling REST (JSONL) ──────────────────────────
        const startPolling = () => {
            const poll = async () => {
                try {
                    const r = await fetch(
                        `/api/projects/${encodeURIComponent(projectUuid)}/realtime?since=${sinceRef.current}`,
                        { credentials: 'same-origin' },
                    );
                    if (!r.ok) return;
                    const json = await r.json();
                    if (!json.events?.length) return;

                    for (const evt of json.events) {
                        if (evt._id !== undefined) {
                            sinceRef.current = Math.max(sinceRef.current, evt._id + 1);
                        }
                        dispatchToast(evt);
                    }

                    storeSince(projectUuid, sinceRef.current);
                } catch {
                    // Silencieux — réessaiera au prochain cycle
                }
            };

            poll(); // premier appel immédiat
            timerRef.current = setInterval(poll, 3000);
        };

        // ── Dispatch commun des toasts ───────────────────────────────
        const dispatchToast = (evt: { event?: string; data?: { title?: string } }) => {
            switch (evt.event) {
                case 'media.uploaded':
                    toast.success(evt.data?.title || 'Fichier téléversé', {
                        description: 'Upload terminé',
                        duration: 4000,
                    });
                    break;
                case 'media.deleted':
                    toast.info((evt.data?.title || 'Fichier') + ' supprimé', {
                        duration: 3000,
                    });
                    break;
                case 'entry.created':
                    toast.success(evt.data?.title || 'Créé', {
                        description: 'Nouveau contenu',
                        duration: 4000,
                    });
                    break;
                case 'entry.updated':
                    toast.info(evt.data?.title || 'Mis à jour', {
                        description: 'Contenu modifié',
                        duration: 3000,
                    });
                    break;
                case 'status.changed':
                    toast.info(evt.data?.title || 'Statut changé', {
                        duration: 4000,
                    });
                    break;
            }
        };

        // ── Démarrage ────────────────────────────────────────────────
        trySSE();

        return () => {
            cancelled = true;
            if (eventSourceRef.current) {
                eventSourceRef.current.close();
            }
            if (timerRef.current) {
                clearInterval(timerRef.current);
            }
        };
    }, [projectUuid]);
}
