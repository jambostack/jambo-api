import { FlowGraph } from './FlowStore';

export interface ValidationError {
    nodeId?: string;
    edgeId?: string;
    message: string;
    severity: 'error' | 'warning';
}

export function validateFlowGraph(graph: FlowGraph): ValidationError[] {
    const errors: ValidationError[] = [];
    const { nodes, edges } = graph;

    // 1. Au moins un node
    if (nodes.length === 0) {
        errors.push({ message: 'Ajoutez au moins un node au flow', severity: 'error' });
        return errors;
    }

    // 2. Au moins un trigger
    const triggers = nodes.filter((n) => n.type.startsWith('trigger.'));
    if (triggers.length === 0) {
        errors.push({ message: 'Ajoutez au moins un déclencheur (trigger)', severity: 'error' });
    }

    // 3. Détection de cycles (DFS)
    const adj = new Map<string, string[]>();
    const nodeIds = new Set(nodes.map((n) => n.id));

    for (const n of nodes) adj.set(n.id, []);
    for (const e of edges) {
        if (!nodeIds.has(e.source) || !nodeIds.has(e.target)) continue;
        adj.get(e.source)?.push(e.target);
    }

    const hasCycle = detectCycle(nodes.map((n) => n.id), adj);
    if (hasCycle) {
        errors.push({ message: 'Le graphe contient un cycle — les flows doivent être acycliques', severity: 'error' });
    }

    // 4. Nodes orphelins (pas d'entrée, pas trigger)
    const targetIds = new Set(edges.map((e) => e.target));
    for (const node of nodes) {
        if (!node.type.startsWith('trigger.') && !targetIds.has(node.id)) {
            errors.push({
                nodeId: node.id,
                message: `"${node.data.label}" n'a pas d'entrée et n'est pas un déclencheur`,
                severity: 'warning',
            });
        }
    }

    // 5. Nodes sans sortie (sauf si c'est volontaire — log, notify, comment)
    const terminalTypes = ['util.log', 'util.comment', 'action.send_notification', 'util.noop'];
    const sourceIds = new Set(edges.map((e) => e.source));
    for (const node of nodes) {
        if (!terminalTypes.includes(node.type) && !sourceIds.has(node.id)) {
            errors.push({
                nodeId: node.id,
                message: `"${node.data.label}" n'a pas de sortie — connectez-le à un autre node`,
                severity: 'warning',
            });
        }
    }

    return errors;
}

function detectCycle(nodeIds: string[], adj: Map<string, string[]>): boolean {
    const WHITE = 0, GRAY = 1, BLACK = 2;
    const color = new Map<string, number>();
    for (const id of nodeIds) color.set(id, WHITE);

    function dfs(u: string): boolean {
        color.set(u, GRAY);
        for (const v of adj.get(u) ?? []) {
            const c = color.get(v) ?? WHITE;
            if (c === GRAY) return true;
            if (c === WHITE && dfs(v)) return true;
        }
        color.set(u, BLACK);
        return false;
    }

    for (const id of nodeIds) {
        if (color.get(id) === WHITE && dfs(id)) return true;
    }
    return false;
}
