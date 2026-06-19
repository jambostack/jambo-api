import { useEffect, useRef } from 'react';
import { toast } from 'sonner';

/**
 * Hook temps réel par short polling (compatible tout serveur).
 * Interroge le serveur toutes les 3 secondes pour les nouveaux événements.
 *
 * Usage dans n'importe quelle page projet :
 *   useRealtime(project.uuid);
 *
 * Aucune configuration — juste cet appel. Fonctionne sur Apache/CGI, Nginx, etc.
 */
export function useRealtime(projectUuid?: string) {
    const sinceRef = useRef(0);
    const timerRef = useRef<ReturnType<typeof setInterval>>();

    useEffect(() => {
        if (!projectUuid) return;

        const poll = async () => {
            try {
                const r = await fetch(
                    `/api/projects/${encodeURIComponent(projectUuid)}/realtime?since=${sinceRef.current}`,
                    { credentials: 'same-origin' }
                );
                if (!r.ok) return;
                const json = await r.json();
                if (!json.events?.length) return;

                for (const evt of json.events) {
                    if (evt._id !== undefined) {
                        sinceRef.current = evt._id + 1;
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
