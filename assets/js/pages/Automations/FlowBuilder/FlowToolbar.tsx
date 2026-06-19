import { Button } from '@/components/ui/button';
import { Undo2, Redo2, ZoomIn, ZoomOut, Maximize, Map, Play, Save, CheckCircle, XCircle } from 'lucide-react';
import { useReactFlow } from '@xyflow/react';
import { useFlowStore } from './FlowStore';
import { createFlowHistory } from './FlowHistory';
import { useState } from 'react';
import FlowDryRun from './FlowDryRun';
import { useTranslation } from '@/lib/i18n';
import { validateFlowGraph } from './FlowValidation';
import type { ValidationError } from './FlowValidation';

const history = createFlowHistory();

interface FlowToolbarProps {
    onSave?: () => void;
    saving?: boolean;
    projectUuid: string;
    automationId?: number | null;
}

export default function FlowToolbar({ onSave, saving, projectUuid, automationId }: FlowToolbarProps) {
    const t = useTranslation();
    const { zoomIn, zoomOut, fitView } = useReactFlow();
    const { getFlowGraph, loadFlowGraph, flowName, isActive, debugMode, minimapVisible, setMinimapVisible } = useFlowStore();
    const [validating, setValidating] = useState(false);
    const [errors, setErrors] = useState<ValidationError[]>([]);
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
        const errs = validateFlowGraph(graph);
        setErrors(errs);
        setValidating(true);
    };

    return (
        <div className="h-12 border-t bg-background flex items-center justify-between px-3 shrink-0">
            <div className="flex items-center gap-1">
                <Button variant="ghost" size="icon" className="h-8 w-8" onClick={handleUndo} disabled={!history.canUndo()} title={t('flow.toolbar_undo')}>
                    <Undo2 className="h-4 w-4" />
                </Button>
                <Button variant="ghost" size="icon" className="h-8 w-8" onClick={handleRedo} disabled={!history.canRedo()} title={t('flow.toolbar_redo')}>
                    <Redo2 className="h-4 w-4" />
                </Button>
                <div className="w-px h-5 bg-border mx-1" />
                <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => zoomIn()} title={t('flow.toolbar_zoom_in')}>
                    <ZoomIn className="h-4 w-4" />
                </Button>
                <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => zoomOut()} title={t('flow.toolbar_zoom_out')}>
                    <ZoomOut className="h-4 w-4" />
                </Button>
                <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => fitView()} title={t('flow.toolbar_fit')}>
                    <Maximize className="h-4 w-4" />
                </Button>
                <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => setMinimapVisible(!minimapVisible)} title={t('flow.toolbar_minimap')}>
                    <Map className={minimapVisible ? 'h-4 w-4 text-primary' : 'h-4 w-4'} />
                </Button>
            </div>

            <div className="flex items-center gap-2">
                {validating && errors.length > 0 && (
                    <div className="flex items-center gap-1 text-xs text-destructive">
                        <XCircle className="h-3 w-3" />
                        {t(errors[0].code, errors[0].params ?? {})}
                    </div>
                )}
                {validating && errors.length === 0 && (
                    <div className="flex items-center gap-1 text-xs text-emerald-500">
                        <CheckCircle className="h-3 w-3" />
                        {t('flow.toolbar_valid')}
                    </div>
                )}
                <Button variant="outline" size="sm" onClick={handleValidate} className="h-8 text-xs">
                    {t('flow.toolbar_validate')}
                </Button>
                <Button variant="outline" size="sm" onClick={() => setDryRunOpen(true)} disabled={!automationId} className="h-8 text-xs">
                    <Play className="h-3 w-3 mr-1" /> {t('flow.toolbar_test')}
                </Button>
                <Button size="sm" className="h-8 text-xs" onClick={onSave} disabled={saving}>
                    <Save className="h-3 w-3 mr-1" /> {saving ? t('flow.saving_btn') : t('flow.save_btn')}
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
