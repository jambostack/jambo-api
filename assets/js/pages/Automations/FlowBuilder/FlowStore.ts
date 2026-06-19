import { create } from 'zustand';
import {
    Edge, Node, Connection, EdgeChange, NodeChange,
    addEdge, applyNodeChanges, applyEdgeChanges,
} from '@xyflow/react';

export interface FlowState {
    nodes: Node[];
    edges: Edge[];
    variables: Record<string, string>;
    flowName: string;
    isActive: boolean;
    debugMode: boolean;

    // Actions
    setNodes: (nodes: Node[]) => void;
    setEdges: (edges: Edge[]) => void;
    onNodesChange: (changes: NodeChange[]) => void;
    onEdgesChange: (changes: EdgeChange[]) => void;
    onConnect: (connection: Connection) => void;
    addNode: (node: Node) => void;
    updateNodeData: (nodeId: string, data: Record<string, unknown>) => void;
    deleteNode: (nodeId: string) => void;
    deleteEdge: (edgeId: string) => void;
    setVariables: (vars: Record<string, string>) => void;
    setFlowName: (name: string) => void;
    setIsActive: (active: boolean) => void;
    setDebugMode: (debug: boolean) => void;
    getFlowGraph: () => FlowGraph;
    loadFlowGraph: (graph: FlowGraph, name: string, active: boolean, debug: boolean) => void;
}

export interface FlowGraph {
    nodes: Array<{
        id: string;
        type: string;
        position: { x: number; y: number };
        data: { label: string; config: Record<string, unknown> };
    }>;
    edges: Array<{
        id: string;
        source: string;
        target: string;
        label?: string;
    }>;
    variables: Record<string, string>;
}

export const useFlowStore = create<FlowState>((set, get) => ({
    nodes: [],
    edges: [],
    variables: {},
    flowName: '',
    isActive: true,
    debugMode: false,

    setNodes: (nodes) => set({ nodes }),
    setEdges: (edges) => set({ edges }),

    onNodesChange: (changes) => set((state) => ({
        nodes: applyNodeChanges(changes, state.nodes),
    })),

    onEdgesChange: (changes) => set((state) => ({
        edges: applyEdgeChanges(changes, state.edges),
    })),

    onConnect: (connection) => set((state) => ({
        edges: addEdge(connection, state.edges),
    })),

    addNode: (node) => set((state) => ({ nodes: [...state.nodes, node] })),

    updateNodeData: (nodeId, data) => set((state) => ({
        nodes: state.nodes.map((n) =>
            n.id === nodeId ? { ...n, data: { ...n.data, ...data } } : n
        ),
    })),

    deleteNode: (nodeId) => set((state) => ({
        nodes: state.nodes.filter((n) => n.id !== nodeId),
        edges: state.edges.filter((e) => e.source !== nodeId && e.target !== nodeId),
    })),

    deleteEdge: (edgeId) => set((state) => ({
        edges: state.edges.filter((e) => e.id !== edgeId),
    })),

    setVariables: (variables) => set({ variables }),
    setFlowName: (flowName) => set({ flowName }),
    setIsActive: (isActive) => set({ isActive }),
    setDebugMode: (debugMode) => set({ debugMode }),

    getFlowGraph: () => {
        const { nodes, edges, variables } = get();
        return {
            nodes: nodes.map((n) => ({
                id: n.id,
                type: n.type ?? '',
                position: n.position,
                data: { label: (n.data as any)?.label ?? '', config: (n.data as any)?.config ?? {} },
            })),
            edges: edges.map((e) => ({
                id: e.id,
                source: e.source,
                target: e.target,
                label: (e as any).label ?? undefined,
            })),
            variables,
        };
    },

    loadFlowGraph: (graph, name, active, debug) => {
        const nodes = (graph.nodes || []).map((n) => ({
            id: n.id,
            type: n.type,
            position: n.position,
            data: { label: n.data?.label ?? '', config: n.data?.config ?? {} },
        }));
        const edges = (graph.edges || []).map((e) => ({
            id: e.id,
            source: e.source,
            target: e.target,
            label: e.label,
        }));
        set({
            nodes,
            edges,
            variables: graph.variables || {},
            flowName: name,
            isActive: active,
            debugMode: debug,
        });
    },
}));
