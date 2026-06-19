import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { Play, CheckCircle, XCircle, Clock } from 'lucide-react';
import axios from 'axios';
import { useFlowStore, FlowGraph } from './FlowStore';

interface DryRunStep {
    nodeId: string;
    type: string;
    label: string;
    status: string;
    durationMs: number;
    input: Record<string, any>;
    output: Record<string, any>;
    error?: string;
}

interface DryRunResult {
    success: boolean;
    status: string;
    step_log: DryRunStep[];
    total_duration_ms: number;
    error: string | null;
}

export default function FlowDryRun({ projectUuid, automationId, open, onClose }: {
    projectUuid: string;
    automationId: number;
    open: boolean;
    onClose: () => void;
}) {
    const [payload, setPayload] = useState(JSON.stringify({
        entry: { title: 'Test', status: 'published', slug: 'test', uuid: '00000000-0000-0000-0000-000000000000' },
    }, null, 2));
    const [running, setRunning] = useState(false);
    const [result, setResult] = useState<DryRunResult | null>(null);

    const handleRun = async () => {
        setRunning(true);
        setResult(null);
        try {
            let parsedPayload;
            try { parsedPayload = JSON.parse(payload); } catch { parsedPayload = {}; }

            const r = await axios.post(
                `/api/projects/${projectUuid}/automations/${automationId}/dry-run`,
                { payload: parsedPayload },
            );
            setResult(r.data);
        } catch (e: any) {
            setResult({ success: false, status: 'error', step_log: [], total_duration_ms: 0, error: e.message });
        } finally {
            setRunning(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={() => onClose()}>
            <DialogContent className="sm:max-w-2xl max-h-[85vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Dry-Run — Test du flow</DialogTitle>
                </DialogHeader>

                <div className="space-y-4">
                    {/* Payload editor */}
                    <div>
                        <label className="text-sm font-medium">Payload simulé</label>
                        <Textarea
                            className="font-mono text-xs min-h-[120px] mt-1"
                            value={payload}
                            onChange={(e) => setPayload(e.target.value)}
                        />
                    </div>

                    <Button onClick={handleRun} disabled={running} size="sm">
                        <Play className="h-3.5 w-3.5 mr-1" />
                        {running ? 'Exécution...' : 'Exécuter'}
                    </Button>

                    {/* Résultats */}
                    {result && (
                        <div className="space-y-2 border rounded-md p-3">
                            <div className="flex items-center gap-2 text-sm">
                                {result.success ? (
                                    <CheckCircle className="h-4 w-4 text-emerald-500" />
                                ) : (
                                    <XCircle className="h-4 w-4 text-destructive" />
                                )}
                                <span className="font-medium">
                                    {result.status === 'success' ? 'SUCCESS' : result.status === 'partial' ? 'PARTIEL' : 'ÉCHEC'}
                                </span>
                                <span className="text-xs text-muted-foreground">{result.total_duration_ms}ms</span>
                            </div>

                            {result.error && (
                                <div className="text-xs text-destructive bg-destructive/10 p-2 rounded">{result.error}</div>
                            )}

                            <div className="space-y-1 mt-2">
                                {result.step_log.map((step, i) => (
                                    <div key={i} className={`flex items-center gap-2 text-xs p-1.5 rounded ${
                                        step.status === 'success' ? 'bg-emerald-50' :
                                        step.status === 'failed' ? 'bg-red-50' : 'bg-muted'
                                    }`}>
                                        {step.status === 'success' ? (
                                            <CheckCircle className="h-3 w-3 text-emerald-500 shrink-0" />
                                        ) : step.status === 'failed' ? (
                                            <XCircle className="h-3 w-3 text-destructive shrink-0" />
                                        ) : (
                                            <Clock className="h-3 w-3 text-muted-foreground shrink-0" />
                                        )}
                                        <span className="font-medium">{step.label}</span>
                                        <span className="text-muted-foreground">{step.durationMs}ms</span>
                                        {step.error && (
                                            <span className="text-destructive ml-auto truncate max-w-[200px]">{step.error}</span>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
