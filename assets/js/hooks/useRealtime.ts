import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import { usePage } from '@inertiajs/react';
import { type SharedData } from '@/types/index.d';
import { useTranslation } from '@/lib/i18n';

const STORAGE_PREFIX = 'rt_since_';

function getStoredSince(projectUuid: string): number | null {
    try {
        const v = sessionStorage.getItem(STORAGE_PREFIX + projectUuid);
        return v !== null ? parseInt(v, 10) : null;
    } catch {
        return null;
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
    const t = useTranslation();
    const pageProps = usePage<SharedData>().props;
    const currentUserId = pageProps.auth?.user?.id;
    const sinceRef = useRef<number | null>(projectUuid ? getStoredSince(projectUuid) : null);
    const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const eventSourceRef = useRef<EventSource | null>(null);

    useEffect(() => {
        if (!projectUuid) return;

        sinceRef.current = getStoredSince(projectUuid);

        let cancelled = false;

        // ── Tente le mode SSE (EventSource) ──────────────────────────
        const trySSE = async () => {
            if (typeof window === 'undefined' || !window.EventSource) {
                startPolling();
                return;
            }

            try {
                // 1. Récupérer le token de souscription
                const tokenResp = await fetch(
                    `/api/projects/${encodeURIComponent(projectUuid)}/realtime/token`,
                    { credentials: 'same-origin' },
                );

                if (!tokenResp.ok) {
                    throw new Error(`Token endpoint returned ${tokenResp.status}`);
                }

                const data = await tokenResp.json();
                if (data.enabled === false || !data.token || !data.hub_url) {
                    throw new Error('SSE not available on this server');
                }

                const { token, hub_url, topics } = data;

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
                        // Ne pas afficher de toast pour les actions déclenchées par l'utilisateur connecté lui-même
                        if (currentUserId && (evt.actor_id === currentUserId || evt.data?.actor_id === currentUserId)) {
                            return;
                        }
                        dispatchToast(evt);
                    } catch {
                        // Payload invalide — ignoré
                    }
                });

                es.addEventListener('close', () => {
                    es.close();
                    if (!cancelled) {
                        startPolling();
                    }
                });

                es.addEventListener('error', () => {
                    // Dégrader vers le polling si le flux SSE échoue
                    if (!cancelled) {
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
                    const sinceParam = sinceRef.current !== null ? sinceRef.current : 'latest';
                    const r = await fetch(
                        `/api/projects/${encodeURIComponent(projectUuid)}/realtime?since=${sinceParam}`,
                        { credentials: 'same-origin' },
                    );
                    if (!r.ok) return;
                    const json = await r.json();

                    // Premier appel sans curseur : initialise avec le dernier index sans rejouer les toasts passés
                    if (sinceRef.current === null) {
                        sinceRef.current = typeof json.since === 'number' ? json.since : 0;
                        storeSince(projectUuid, sinceRef.current);
                        return;
                    }

                    if (!json.events?.length) {
                        if (typeof json.since === 'number' && json.since !== sinceRef.current) {
                            sinceRef.current = json.since;
                            storeSince(projectUuid, sinceRef.current);
                        }
                        return;
                    }

                    for (const evt of json.events) {
                        if (evt._id !== undefined) {
                            sinceRef.current = Math.max(sinceRef.current, evt._id + 1);
                        }
                        // Ne pas afficher de toast pour les actions déclenchées par l'utilisateur connecté lui-même
                        if (currentUserId && (evt.actor_id === currentUserId || evt.data?.actor_id === currentUserId)) {
                            continue;
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
                    toast.success(evt.data?.title || t('realtime.media_uploaded'), {
                        description: t('realtime.upload_completed'),
                        duration: 4000,
                    });
                    break;
                case 'media.deleted':
                    toast.info((evt.data?.title || t('realtime.file')) + ' ' + t('realtime.deleted'), {
                        duration: 3000,
                    });
                    break;
                case 'entry.created':
                    toast.success(evt.data?.title || t('realtime.created'), {
                        description: t('realtime.new_content'),
                        duration: 4000,
                    });
                    break;
                case 'entry.updated':
                    toast.info(evt.data?.title || t('realtime.updated'), {
                        description: t('realtime.content_modified'),
                        duration: 3000,
                    });
                    break;
                case 'status.changed':
                    toast.info(evt.data?.title || t('realtime.status_changed'), {
                        duration: 4000,
                    });
                    break;
                case 'form.submitted':
                    toast.success(evt.data?.title || t('realtime.form_submitted_title'), {
                        description: t('realtime.form_submitted_desc'),
                        duration: 5000,
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
