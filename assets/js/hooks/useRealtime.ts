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
    } catch {
        // sessionStorage indisponible (navigation privée, quota...) — ignoré
    }
}

/**
 * Hook temps réel par short polling (compatible tout serveur).
 * Interroge le serveur toutes les 3 secondes pour les nouveaux événements.
 *
 * Le curseur `since` est persisté dans sessionStorage pour éviter
 * de re-jouer les anciens événements après un rafraîchissement Inertia.
 *
 * Usage dans n'importe quelle page projet :
 *   useRealtime(project.uuid);
 *
 * Aucune configuration — juste cet appel. Fonctionne sur Apache/CGI, Nginx, etc.
 */
export function useRealtime(projectUuid?: string) {
    // SinceRef persisté pour éviter les doublons après rafraîchissement Inertia.
    // Initialisé depuis sessionStorage ; mis à jour après chaque poll.
    const sinceRef = useRef(projectUuid ? getStoredSince(projectUuid) : 0);
    const timerRef = useRef<ReturnType<typeof setInterval>>();

    useEffect(() => {
        if (!projectUuid) return;

        // Rattrape le curseur sauvegardé d'un précédent cycle de vie du hook.
        sinceRef.current = getStoredSince(projectUuid);

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
                }

                // Persiste le curseur pour ne pas re-jouer ces événements
                // après un rafraîchissement Inertia.
                storeSince(projectUuid, sinceRef.current);
            } catch {
                // Silencieux — réessaiera au prochain cycle
            }
        };

        // Premier appel immédiat, puis toutes les 3 secondes
        poll();
        timerRef.current = setInterval(poll, 3000);

        return () => {
            if (timerRef.current) clearInterval(timerRef.current);
        };
    }, [projectUuid]);
}
