import { useRealtime } from '@/hooks/useRealtime';

/**
 * Active les notifications temps réel pour un projet.
 * À placer n'importe où dans l'arbre React d'une page projet.
 *
 * Usage :
 *   <RealtimeNotifier projectUuid={project.uuid} />
 *
 * Aucune props supplémentaire, aucune configuration — juste ça.
 */
export default function RealtimeNotifier({ projectUuid }: { projectUuid?: string }) {
    useRealtime(projectUuid);
    return null; // pas de rendu visuel, juste les toasts
}
