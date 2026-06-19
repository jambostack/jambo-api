import { useState, useEffect } from 'react';
import axios from 'axios';

export interface NodeCatalogItem {
    type: string;
    label: string;
    description: string;
    icon: string;
    configSchema: Record<string, any>;
    outputPorts: string[];
}

export interface NodeCategory {
    key: string;
    label: string;
    color: string;
    nodes: NodeCatalogItem[];
}

export function useNodeCatalog() {
    const [catalog, setCatalog] = useState<NodeCategory[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        axios
            .get('/api/automations/node-catalog')
            .then((r) => setCatalog(r.data.categories || []))
            .catch(() => {})
            .finally(() => setLoading(false));
    }, []);

    const getNodeInfo = (type: string): NodeCatalogItem | undefined => {
        for (const cat of catalog) {
            const found = cat.nodes.find((n) => n.type === type);
            if (found) return found;
        }
        return undefined;
    };

    return { catalog, loading, getNodeInfo };
}
