import { memo } from 'react';
import { Handle, Position, NodeProps } from '@xyflow/react';
import { getCategoryFromType, categoryStyles, iconComponents } from './nodeStyles';
import * as Icons from 'lucide-react';

interface BaseNodeData {
    label: string;
    config: Record<string, unknown>;
    icon?: string;
}

const BaseNode = memo(({ id, data, selected }: NodeProps) => {
    const nodeData = data as unknown as BaseNodeData;
    const fullType = (data as any)?.type ?? 'util.unknown';
    const category = getCategoryFromType(fullType);
    const style = categoryStyles[category] ?? categoryStyles.util;

    const LuciIcon = (Icons as any)[nodeData.icon ?? style.icon] ?? Icons.Wrench;

    // Preview de la config (2-3 lignes max)
    const configPreview = Object.entries(nodeData.config ?? {})
        .slice(0, 2)
        .map(([k, v]) => `${k}: ${typeof v === 'string' ? v.substring(0, 20) : JSON.stringify(v).substring(0, 20)}`)
        .join('\n');

    return (
        <div
            className={`
                min-w-[180px] rounded-lg border-2 bg-white shadow-sm
                ${selected ? 'ring-2 ring-offset-1 ring-blue-500' : ''}
            `}
            style={{ borderColor: style.border }}
        >
            {/* Header */}
            <div
                className="flex items-center gap-2 px-3 py-2 rounded-t-md text-sm font-medium"
                style={{ backgroundColor: style.bg, color: style.text }}
            >
                <LuciIcon className="h-4 w-4" />
                <span className="truncate">{nodeData.label || 'Node'}</span>
            </div>

            {/* Config preview */}
            {configPreview && (
                <div className="px-3 py-2 text-xs text-muted-foreground font-mono whitespace-pre-wrap leading-relaxed">
                    {configPreview}
                </div>
            )}

            {/* Input handle (top) */}
            <Handle
                type="target"
                position={Position.Top}
                className="!w-3 !h-3 !border-2 !bg-white"
                style={{ borderColor: style.border }}
            />

            {/* Output handle (bottom) */}
            <Handle
                type="source"
                position={Position.Bottom}
                className="!w-3 !h-3 !border-2 !bg-white"
                style={{ borderColor: style.border }}
            />
        </div>
    );
});

BaseNode.displayName = 'BaseNode';
export default BaseNode;
