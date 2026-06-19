import { Button } from '@/components/ui/button';
import { Undo2, Redo2, ZoomIn, ZoomOut, Maximize, Map, Play, Save, CheckCircle, XCircle } from 'lucide-react';
import { useReactFlow } from '@xyflow/react';
import { useFlowStore } from './FlowStore';
import { createFlowHistory } from './FlowHistory';
import { useState } from 'react';
import FlowDryRun from './FlowDryRun';

const history = createFlowHistory();

interface FlowToolbarProps {
    onSave?: () => void;
    saving?: boolean;
    projectUuid: string;
    automationId?: number | null;
}

export default function FlowToolbar({ onSave, saving, projectUuid, automationId }: FlowToolbarProps) {
    const { zoomIn, zoomOut, fitView } = useReactFlow();
    const { getFlowGraph, loadFlowGraph, flowName, isActive, debugMode } = useFlowStore();
    const [validating, setValidating] = useState(false);
    const [errors, setErrors] = useState<string[]>([]);
    const [dryRunOpen, setDryRunOpen] = useState(false);

    const handleUndo = () => {
        const prev = history.undo();
        if (prev) {
            loadFlowGraph(prev, flowName, isActive, debugMode);
        }
    };

    const handleRedo = () => {
        const next = history.redo();
        if (next) {
            loadFlowGraph(next, flowName, isActive, debugMode);
        }
    };

    const handleSaveSnapshot = () => {
        history.pushState(getFlowGraph());
    };

    const handleValidate = () => {
        const graph = getFlowGraph();
        const errs: string[] = [];

        if (graph.nodes.length === 0) errs.push('Aucun node dans le flow');
        if (!graph.nodes.some((n) => n.type.startsWith('trigger.'))) {
            errs.push('Ajoutez au moins un déclencheur (trigger)');
        }

        const targetIds = new Set(graph.edges.map((e) => e.target));

        for (const node of graph.nodes) {
            if (!node.type.startsWith('trigger.') && !targetIds.has(node.id)) {
                errs.push(`Le node "${node.data.label}" n'a pas d'entrée`);
            }
        }

        setErrors(errs);
        setValidating(true);
    };

    return (
        <div className="h-12 border-t bg-background flex items-center justify-between px-3 shrink-0">
            <div className="flex items-center gap-1">
                <Button variant="ghost" size="icon" className="h-8 w-8" onClick={handleUndo} disabled={!history.canUndo()} title="Annuler (Ctrl+Z)">
                    <Undo2 className="h-4 w-4" />
                </Button>
                <Button variant="ghost" size="icon" className="h-8 w-8" onClick={handleRedo} disabled={!history.canRedo()} title="Rétablir (Ctrl+Y)">
                    <Redo2 className="h-4 w-4" />
                </Button>
                <div className="w-px h-5 bg-border mx-1" />
                <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => zoomIn()} title="Zoom +">
                    <ZoomIn className="h-4 w-4" />
                </Button>
                <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => zoomOut()} title="Zoom -">
                    <ZoomOut className="h-4 w-4" />
                </Button>
                <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => fitView()} title="Ajuster">
                    <Maximize className="h-4 w-4" />
                </Button>
                <Button variant="ghost" size="icon" className="h-8 w-8" title="Minimap">
                    <Map className="h-4 w-4" />
                </Button>
            </div>

            <div className="flex items-center gap-2">
                {validating && errors.length > 0 && (
                    <div className="flex items-center gap-1 text-xs text-destructive">
                        <XCircle className="h-3 w-3" />
                        {errors[0]}
                    </div>
                )}
                {validating && errors.length === 0 && (
                    <div className="flex items-center gap-1 text-xs text-emerald-500">
                        <CheckCircle className="h-3 w-3" />
                        Flow valide
                    </div>
                )}
                <Button variant="outline" size="sm" onClick={handleValidate} className="h-8 text-xs">
                    Valider
                </Button>
                <Button variant="outline" size="sm" onClick={() => setDryRunOpen(true)} disabled={!automationId} className="h-8 text-xs">
                    <Play className="h-3 w-3 mr-1" /> Tester
                </Button>
                <Button size="sm" className="h-8 text-xs" onClick={onSave} disabled={saving}>
                    <Save className="h-3 w-3 mr-1" /> {saving ? '...' : 'Enregistrer'}
                </Button>
            </div>

            {automationId && (
                <FlowDryRun
                    projectUuid={projectUuid}
                    automationId={automationId}
                    open={dryRunOpen}
                    onClose={() => setDryRunOpen(false)}
                />
            )}
        </div>
    );
}
