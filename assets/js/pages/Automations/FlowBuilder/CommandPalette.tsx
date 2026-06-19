import { useMemo, useEffect, useRef } from 'react';
import { Command, CommandInput, CommandList, CommandGroup, CommandItem } from 'cmdk';
import { useReactFlow } from '@xyflow/react';
import { useFlowStore } from './FlowStore';
import { useNodeCatalog } from './useNodeCatalog';
import * as Icons from 'lucide-react';
import { categoryStyles } from './nodes/nodeStyles';

export default function CommandPalette({ open, onClose }: { open: boolean; onClose: () => void }) {
    const { catalog } = useNodeCatalog();
    const addNode = useFlowStore((s) => s.addNode);
    const { screenToFlowPosition } = useReactFlow();
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (open && inputRef.current) {
            setTimeout(() => inputRef.current?.focus(), 50);
        }
    }, [open]);

    const allNodes = useMemo(
        () => catalog.flatMap((cat) => cat.nodes.map((n) => ({ ...n, category: cat.key, color: cat.color }))),
        [catalog],
    );

    const handleSelect = (type: string, label: string, icon: string) => {
        const viewportCenter = screenToFlowPosition({
            x: window.innerWidth / 2,
            y: window.innerHeight / 2,
        });
        const id = `n_${Date.now()}_${Math.random().toString(36).substr(2, 6)}`;
        addNode({
            id,
            type,
            position: viewportCenter,
            data: { label, config: {}, icon, type },
        });
        onClose();
    };

    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'Escape') {
            onClose();
        }
    };

    return (
        <div
            className="fixed inset-0 z-50 bg-background/80 backdrop-blur-sm"
            onKeyDown={handleKeyDown}
        >
            <div className="max-w-lg mx-auto mt-[15vh]">
                <div className="bg-popover border rounded-xl shadow-2xl overflow-hidden">
                    <Command>
                        <CommandInput
                            ref={inputRef}
                            placeholder="Ajouter un node..."
                            className="text-sm"
                        />
                        <CommandList className="max-h-[400px] overflow-y-auto p-2">
                            {catalog.map((cat) => {
                                const catColor =
                                    categoryStyles[cat.key]?.border ??
                                    cat.color ??
                                    '#6b7280';
                                return (
                                    <CommandGroup key={cat.key} heading={cat.label}>
                                        {cat.nodes.map((node) => {
                                            const IconComp =
                                                (Icons as any)[node.icon] ||
                                                Icons.Box;
                                            return (
                                                <CommandItem
                                                    key={node.type}
                                                    onSelect={() =>
                                                        handleSelect(
                                                            node.type,
                                                            node.label,
                                                            node.icon,
                                                        )
                                                    }
                                                    className="flex items-center gap-2 text-sm cursor-pointer"
                                                >
                                                    <IconComp
                                                        className="h-4 w-4"
                                                        style={{
                                                            color: catColor,
                                                        }}
                                                    />
                                                    <span>{node.label}</span>
                                                    <span className="text-xs text-muted-foreground ml-auto">
                                                        {node.type}
                                                    </span>
                                                </CommandItem>
                                            );
                                        })}
                                    </CommandGroup>
                                );
                            })}
                        </CommandList>
                    </Command>
                </div>
            </div>
        </div>
    );
}
