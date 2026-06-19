import { useMemo } from 'react';
import { useFlowStore } from './FlowStore';

export interface Suggestion {
    path: string;
    type: string;
    source: string;
}

export function useAutocomplete(): Suggestion[] {
    const nodes = useFlowStore((s) => s.nodes);
    const edges = useFlowStore((s) => s.edges);
    const variables = useFlowStore((s) => s.variables);

    return useMemo(() => {
        const suggestions: Suggestion[] = [];

        // Payload trigger connu
        suggestions.push(
            { path: 'entry.title', type: 'string', source: 'trigger' },
            { path: 'entry.status', type: 'string', source: 'trigger' },
            { path: 'entry.slug', type: 'string', source: 'trigger' },
            { path: 'entry.uuid', type: 'uuid', source: 'trigger' },
            { path: 'entry.collection_slug', type: 'string', source: 'trigger' },
            { path: 'entry.previous_status', type: 'string', source: 'trigger' },
            { path: 'project_uuid', type: 'uuid', source: 'trigger' },
            { path: 'trigger', type: 'string', source: 'trigger' },
            { path: 'timestamp', type: 'number', source: 'trigger' },
        );

        // Variables du flow
        for (const [key] of Object.entries(variables)) {
            suggestions.push({ path: `variables.${key}`, type: 'string', source: 'variable' });
        }

        return suggestions;
    }, [nodes.length, edges.length, variables]);
}
