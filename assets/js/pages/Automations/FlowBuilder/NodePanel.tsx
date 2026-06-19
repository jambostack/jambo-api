import { useState, useEffect } from 'react';
import { Input } from '@/components/ui/input';
import { Search, ChevronDown } from 'lucide-react';
import axios from 'axios';
import { categoryStyles } from './nodes/nodeStyles';
import * as Icons from 'lucide-react';
import { useTranslation } from '@/lib/i18n';

interface NodeCatalogItem {
    type: string;
    label: string;
    description: string;
    icon: string;
    outputPorts: string[];
}

interface NodeCategory {
    key: string;
    label: string;
    color: string;
    nodes: NodeCatalogItem[];
}

export default function NodePanel() {
    const t = useTranslation();
    const [catalog, setCatalog] = useState<NodeCategory[]>([]);
    const [search, setSearch] = useState('');
    const [expanded, setExpanded] = useState<Record<string, boolean>>({});

    useEffect(() => {
        axios.get('/api/automations/node-catalog').then((r) => {
            setCatalog(r.data.categories || []);
            setExpanded(
                Object.fromEntries(
                    (r.data.categories || []).map((c: NodeCategory) => [c.key, true]),
                ),
            );
        }).catch(() => {});
    }, []);

    const onDragStart = (event: React.DragEvent, item: NodeCatalogItem) => {
        event.dataTransfer.setData('application/reactflow-type', item.type);
        event.dataTransfer.setData('application/reactflow-label', item.label);
        event.dataTransfer.setData('application/reactflow-icon', item.icon);
        event.dataTransfer.effectAllowed = 'move';
    };

    const filtered = catalog
        .map((cat) => ({
            ...cat,
            nodes: cat.nodes.filter(
                (n) =>
                    n.label.toLowerCase().includes(search.toLowerCase()) ||
                    n.type.toLowerCase().includes(search.toLowerCase()),
            ),
        }))
        .filter((cat) => cat.nodes.length > 0);

    return (
        <div className="w-64 border-r bg-background flex flex-col min-h-0 shrink-0">
            <div className="p-3 border-b shrink-0">
                <div className="relative">
                    <Search className="absolute left-2 top-2.5 h-4 w-4 text-muted-foreground" />
                    <Input
                        placeholder={t('flow.node_filter_placeholder')}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="pl-7 h-8 text-sm"
                    />
                </div>
            </div>

            <div className="flex-1 min-h-0 overflow-y-auto">
                {filtered.map((cat) => {
                    const catColor =
                        categoryStyles[cat.key]?.border ?? cat.color ?? '#6b7280';
                    return (
                        <div key={cat.key}>
                            <button
                                onClick={() =>
                                    setExpanded((e) => ({
                                        ...e,
                                        [cat.key]: !e[cat.key],
                                    }))
                                }
                                className="flex items-center gap-2 w-full px-3 py-2 text-xs font-medium text-muted-foreground hover:bg-muted/50 sticky top-0 bg-background"
                            >
                                <ChevronDown
                                    className={`h-3 w-3 transition-transform ${
                                        expanded[cat.key] ? '' : '-rotate-90'
                                    }`}
                                />
                                {cat.label}
                            </button>
                            {expanded[cat.key] &&
                                cat.nodes.map((node) => {
                                    const IconComp =
                                        (Icons as any)[node.icon] || Icons.Box;
                                    return (
                                        <div
                                            key={node.type}
                                            draggable
                                            onDragStart={(e) =>
                                                onDragStart(e, node)
                                            }
                                            className="flex items-center gap-2 px-4 py-2 mx-2 my-0.5 rounded-md cursor-grab hover:bg-muted text-sm border border-transparent hover:border-border"
                                        >
                                            <IconComp
                                                className="h-4 w-4 shrink-0"
                                                style={{ color: catColor }}
                                            />
                                            <span className="truncate">
                                                {node.label}
                                            </span>
                                        </div>
                                    );
                                })}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
