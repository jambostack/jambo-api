import { useState, useEffect } from 'react';
import { ReactFlowProvider } from '@xyflow/react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { toast } from 'sonner';
import axios from 'axios';
import FlowCanvas from './FlowCanvas';
import NodePanel from './NodePanel';
import InspectorPanel from './InspectorPanel';
import FlowToolbar from './FlowToolbar';
import CommandPalette from './CommandPalette';
import { useFlowStore } from './FlowStore';
import { useTranslation } from '@/lib/i18n';

interface FlowBuilderPageProps {
    projectUuid: string;
    automationId?: number | null;
    onClose?: () => void;
    onSaved?: () => void;
}

function FlowBuilderContent({ projectUuid, automationId, onClose, onSaved }: FlowBuilderPageProps) {
    const { getFlowGraph, loadFlowGraph, flowName, setFlowName, isActive, setIsActive, debugMode, setDebugMode } = useFlowStore();
    const t = useTranslation();
    const [cmdOpen, setCmdOpen] = useState(false);
    const [saving, setSaving] = useState(false);

    // Charger l'automatisation existante si édition
    useEffect(() => {
        if (automationId) {
            axios
                .get(`/api/projects/${projectUuid}/automations/${automationId}`)
                .then((r) => {
                    const data = r.data.data;
                    loadFlowGraph(
                        data.flow_graph ?? { nodes: [], edges: [], variables: {} },
                        data.name,
                        data.is_active,
                        data.debug_mode,
                    );
                })
                .catch(() =>
                    toast.error(t('flow.load_automation_error')),
                );
        }
    }, [automationId, projectUuid]);

    // Raccourci Cmd+K / Ctrl+K
    useEffect(() => {
        const handler = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                setCmdOpen((o) => !o);
            }
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, []);

    const handleSave = async () => {
        setSaving(true);
        const graph = getFlowGraph();
        const payload = {
            name: flowName || t('flow.unnamed'),
            flow_graph: graph,
            is_active: isActive,
            debug_mode: debugMode,
        };
        try {
            if (automationId) {
                await axios.put(
                    `/api/projects/${projectUuid}/automations/${automationId}`,
                    payload,
                );
            } else {
                await axios.post(
                    `/api/projects/${projectUuid}/automations`,
                    payload,
                );
            }
            toast.success(t('flow.saved_success'));
            onSaved?.();
        } catch (e: any) {
            const errors = e.response?.data?.errors ?? [e.message];
            toast.error(t('flow.save_error'), {
                description: Array.isArray(errors) ? errors.join('\n') : errors,
            });
        } finally {
            setSaving(false);
        }
    };

    const isDialog = !!onClose;

    const content = (
        <div className="flex flex-col h-full">
            {/* Header */}
            <div className="flex items-center justify-between px-4 py-2 border-b bg-background shrink-0">
                <div className="flex items-center gap-3">
                    {!isDialog && (
                        <h3 className="text-sm font-semibold">
                            {t('flow.heading')}
                        </h3>
                    )}
                    <input
                        className="text-sm font-medium bg-transparent border-b border-transparent hover:border-border focus:border-primary outline-none"
                        value={flowName}
                        onChange={(e) => setFlowName(e.target.value)}
                        placeholder={t('flow.name_placeholder')}
                    />
                    <label className="flex items-center gap-1.5 text-xs cursor-pointer">
                        <input
                            type="checkbox"
                            className="accent-primary"
                            checked={isActive}
                            onChange={(e) => setIsActive(e.target.checked)}
                        />
                        {t('flow.active_label')}
                    </label>
                    <label className="flex items-center gap-1.5 text-xs cursor-pointer">
                        <input
                            type="checkbox"
                            className="accent-primary"
                            checked={debugMode}
                            onChange={(e) => setDebugMode(e.target.checked)}
                        />
                        {t('flow.debug_label')}
                    </label>
                </div>
                <div className="flex items-center gap-2">
                    <span className="text-[10px] text-muted-foreground">
                        {t('flow.cmdk_hint')}
                    </span>
                    {isDialog && (
                        <Button variant="ghost" size="sm" onClick={onClose}>
                            {t('flow.cancel_btn')}
                        </Button>
                    )}
                    <Button
                        size="sm"
                        onClick={handleSave}
                        disabled={saving}
                    >
                        {saving ? t('flow.saving_btn') : t('flow.save_btn')}
                    </Button>
                </div>
            </div>

            {/* Body : Panel gauche + Canvas + Inspector */}
            <div className="flex flex-1 min-h-0 overflow-hidden">
                <NodePanel />
                <FlowCanvas />
                <InspectorPanel />
            </div>

            {/* Toolbar */}
            <FlowToolbar onSave={handleSave} saving={saving} projectUuid={projectUuid} automationId={automationId} />

            {/* Command Palette */}
            {cmdOpen && (
                <CommandPalette
                    open={cmdOpen}
                    onClose={() => setCmdOpen(false)}
                />
            )}
        </div>
    );

    if (isDialog) {
        return (
            <Dialog open modal onOpenChange={() => onClose?.()}>
                <DialogContent className="!max-w-none !w-[95vw] !h-[90vh] p-0 gap-0 [&>button.absolute]:hidden flex flex-col">
                    <DialogHeader className="sr-only">
                        <DialogTitle>
                            {automationId
                                ? t('flow.edit_title')
                                : t('flow.new_title')}
                        </DialogTitle>
                    </DialogHeader>
                    {content}
                </DialogContent>
            </Dialog>
        );
    }

    return <div className="h-[calc(100vh-4rem)]">{content}</div>;
}

export default function FlowBuilderPage(props: FlowBuilderPageProps) {
    return (
        <ReactFlowProvider>
            <FlowBuilderContent {...props} />
        </ReactFlowProvider>
    );
}
