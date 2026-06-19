import { useFlowStore } from './FlowStore';
import { X, Copy, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import SchemaForm from './SchemaForm';
import { useEffect, useState } from 'react';
import axios from 'axios';

interface NodeCatalogItem {
    type: string;
    label: string;
    description: string;
    icon: string;
    configSchema: Record<string, any>;
    outputPorts: string[];
}

export default function InspectorPanel() {
    const nodes = useFlowStore((s) => s.nodes);
    const selectedNodes = nodes.filter((n) => n.selected);
    const selectedNode = selectedNodes.length === 1 ? selectedNodes[0] : null;
    const updateNodeData = useFlowStore((s) => s.updateNodeData);
    const deleteNode = useFlowStore((s) => s.deleteNode);
    const setNodes = useFlowStore((s) => s.setNodes);
    const addNode = useFlowStore((s) => s.addNode);

    const [nodeInfo, setNodeInfo] = useState<NodeCatalogItem | null>(null);
    const [config, setConfig] = useState<Record<string, any>>({});
    const [label, setLabel] = useState('');

    useEffect(() => {
        if (selectedNode) {
            setLabel((selectedNode.data as any)?.label ?? '');
            setConfig((selectedNode.data as any)?.config ?? {});

            const nodeType = selectedNode.type ?? '';
            if (nodeType) {
                axios
                    .get('/api/automations/node-catalog')
                    .then((r) => {
                        const allNodes: NodeCatalogItem[] = (
                            r.data.categories || []
                        ).flatMap((c: any) => c.nodes || []);
                        const info = allNodes.find(
                            (n: NodeCatalogItem) => n.type === nodeType,
                        );
                        if (info) setNodeInfo(info);
                    })
                    .catch(() => {});
            }
        } else {
            setNodeInfo(null);
        }
    }, [selectedNode?.id]);

    const handleConfigChange = (newConfig: Record<string, any>) => {
        setConfig(newConfig);
        if (selectedNode) {
            updateNodeData(selectedNode.id, {
                config: newConfig,
                type: selectedNode.type ?? '',
            });
        }
    };

    const handleLabelChange = (newLabel: string) => {
        setLabel(newLabel);
        if (selectedNode) {
            updateNodeData(selectedNode.id, {
                label: newLabel,
                type: selectedNode.type ?? '',
            });
        }
    };

    const handleDuplicate = () => {
        if (!selectedNode) return;
        const id = `n_${Date.now()}_${Math.random().toString(36).substr(2, 6)}`;
        const offset = 50;
        addNode({
            id,
            type: selectedNode.type ?? '',
            position: {
                x: selectedNode.position.x + offset,
                y: selectedNode.position.y + offset,
            },
            data: {
                ...structuredClone(selectedNode.data),
                type: selectedNode.type ?? '',
            },
        });
    };

    const handleDelete = () => {
        if (selectedNode) {
            deleteNode(selectedNode.id);
        }
    };

    if (!selectedNode) {
        return (
            <div className="w-72 border-l bg-background flex flex-col h-full shrink-0">
                <div className="p-4 text-sm text-muted-foreground text-center mt-12">
                    Sélectionnez un node pour le configurer
                </div>
            </div>
        );
    }

    return (
        <div className="w-72 border-l bg-background flex flex-col h-full overflow-hidden shrink-0">
            {/* Header */}
            <div className="flex items-center justify-between px-3 py-2 border-b">
                <span className="text-sm font-medium truncate">
                    {nodeInfo?.label ?? 'Node'}
                </span>
                <Button
                    variant="ghost"
                    size="icon"
                    className="h-6 w-6"
                    onClick={() =>
                        setNodes(
                            nodes.map((n) => ({ ...n, selected: false })),
                        )
                    }
                >
                    <X className="h-3 w-3" />
                </Button>
            </div>

            {/* Content */}
            <div className="flex-1 overflow-y-auto p-3 space-y-4">
                {/* Nom du node */}
                <div>
                    <Label className="text-xs">Nom</Label>
                    <Input
                        className="h-8 text-xs mt-1"
                        value={label}
                        onChange={(e) => handleLabelChange(e.target.value)}
                    />
                </div>

                <Separator />

                {/* Configuration */}
                <div>
                    <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                        Configuration
                    </Label>
                    {nodeInfo?.configSchema && (
                        <div className="mt-2">
                            <SchemaForm
                                schema={nodeInfo.configSchema}
                                values={config}
                                onChange={handleConfigChange}
                            />
                        </div>
                    )}
                </div>

                <Separator />

                {/* Infos */}
                <div className="text-xs space-y-1 text-muted-foreground">
                    <div>
                        <span className="font-medium">Node ID:</span>{' '}
                        {selectedNode.id}
                    </div>
                    <div>
                        <span className="font-medium">Type:</span>{' '}
                        {selectedNode.type}
                    </div>
                    <div>
                        <span className="font-medium">Sorties:</span>{' '}
                        {nodeInfo?.outputPorts?.join(', ') ?? 'default'}
                    </div>
                </div>
            </div>

            {/* Actions */}
            <div className="p-3 border-t flex gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    className="flex-1 h-8 text-xs"
                    onClick={handleDuplicate}
                >
                    <Copy className="h-3 w-3 mr-1" /> Dupliquer
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    className="flex-1 h-8 text-xs text-destructive"
                    onClick={handleDelete}
                >
                    <Trash2 className="h-3 w-3 mr-1" /> Supprimer
                </Button>
            </div>
        </div>
    );
}
