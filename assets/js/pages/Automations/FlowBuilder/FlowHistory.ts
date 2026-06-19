import { FlowGraph } from './FlowStore';

export interface FlowHistoryState {
    past: FlowGraph[];
    present: FlowGraph | null;
    future: FlowGraph[];

    pushState: (graph: FlowGraph) => void;
    undo: () => FlowGraph | null;
    redo: () => FlowGraph | null;
    canUndo: () => boolean;
    canRedo: () => boolean;
}

export function createFlowHistory(): FlowHistoryState {
    const MAX_HISTORY = 50;
    let past: FlowGraph[] = [];
    let present: FlowGraph | null = null;
    let future: FlowGraph[] = [];

    return {
        get past() { return past; },
        get present() { return present; },
        get future() { return future; },

        pushState(graph: FlowGraph) {
            if (present) past.push(present);
            if (past.length > MAX_HISTORY) past.shift();
            present = structuredClone(graph);
            future = [];
        },

        undo(): FlowGraph | null {
            if (past.length === 0) return null;
            if (present) future.push(present);
            present = past.pop()!;
            return structuredClone(present);
        },

        redo(): FlowGraph | null {
            if (future.length === 0) return null;
            if (present) past.push(present);
            present = future.pop()!;
            return structuredClone(present);
        },

        canUndo(): boolean { return past.length > 0; },
        canRedo(): boolean { return future.length > 0; },
    };
}
