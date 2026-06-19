import { useState, useEffect } from 'react';
import axios from 'axios';
import { ArrowLeft, CheckCircle, XCircle, Clock, ChevronDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';

interface Run {
    id: number;
    status: string;
    error_message: string | null;
    trigger_payload: Record<string, any> | null;
    condition_results: Array<Record<string, any>> | null;
    action_input: Record<string, any> | null;
    action_output: Record<string, any> | null;
    started_at: string;
    finished_at: string | null;
    duration_ms: number | null;
}

interface Props {
    projectUuid: string;
    automation: { id: number; name: string; debug_mode: boolean };
    onBack: () => void;
}

export default function AutomationRuns({ projectUuid, automation, onBack }: Props) {
    const [runs, setRuns] = useState<Run[]>([]);
    const [loading, setLoading] = useState(true);
    const [expanded, setExpanded] = useState<number | null>(null);

    const load = async () => {
        try {
            const r = await axios.get(`/api/projects/${projectUuid}/automations/${automation.id}/runs?per_page=50`);
            setRuns(r.data.data || []);
        } catch {
            // silencieux
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => { load(); }, [automation.id]);

    const StatusIcon = ({ status }: { status: string }) => {
        if (status === 'success') return <CheckCircle className="h-4 w-4 text-emerald-500" />;
        if (status === 'failed') return <XCircle className="h-4 w-4 text-destructive" />;
        return <Clock className="h-4 w-4 text-muted-foreground" />;
    };

    return (
        <div className="space-y-4">
            <div className="flex items-center gap-2">
                <Button variant="ghost" size="sm" onClick={onBack}>
                    <ArrowLeft className="h-3.5 w-3.5 mr-1" /> Retour
                </Button>
                <h3 className="text-lg font-semibold">Exécutions : {automation.name}</h3>
                {automation.debug_mode && <Badge variant="outline" className="text-xs">Debug</Badge>}
            </div>

            {loading ? (
                <div className="flex justify-center py-12"><div className="animate-spin rounded-full h-6 w-6 border-b-2 border-primary" /></div>
            ) : runs.length === 0 ? (
                <div className="text-center py-12 text-muted-foreground text-sm">Aucune exécution.</div>
            ) : (
                <div className="border rounded-lg overflow-hidden">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50">
                            <tr>
                                <th className="text-left px-4 py-2 font-medium w-8"></th>
                                <th className="text-left px-4 py-2 font-medium">Statut</th>
                                <th className="text-left px-4 py-2 font-medium">Date</th>
                                <th className="text-left px-4 py-2 font-medium">Durée</th>
                                <th className="text-left px-4 py-2 font-medium">Erreur</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {runs.map(run => (
                                <>
                                    <tr
                                        key={run.id}
                                        className="hover:bg-muted/30 cursor-pointer"
                                        onClick={() => setExpanded(expanded === run.id ? null : run.id)}
                                    >
                                        <td className="px-4 py-2">
                                            <ChevronDown className={`h-3 w-3 transition-transform ${expanded === run.id ? 'rotate-180' : ''}`} />
                                        </td>
                                        <td className="px-4 py-2"><StatusIcon status={run.status} /></td>
                                        <td className="px-4 py-2 text-xs text-muted-foreground">
                                            {new Date(run.started_at).toLocaleString()}
                                        </td>
                                        <td className="px-4 py-2 text-xs text-muted-foreground">
                                            {run.duration_ms != null ? `${run.duration_ms}ms` : '—'}
                                        </td>
                                        <td className="px-4 py-2 text-xs text-destructive truncate max-w-[200px]">
                                            {run.error_message || '—'}
                                        </td>
                                    </tr>
                                    {expanded === run.id && automation.debug_mode && (
                                        <tr key={`${run.id}-detail`}>
                                            <td colSpan={5} className="px-4 py-3 bg-muted/20">
                                                <div className="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                                                    {run.trigger_payload && (
                                                        <div>
                                                            <span className="font-medium">Payload trigger :</span>
                                                            <pre className="mt-1 bg-muted p-2 rounded text-xs overflow-x-auto max-h-[200px]">{JSON.stringify(run.trigger_payload, null, 2)}</pre>
                                                        </div>
                                                    )}
                                                    {run.condition_results && (
                                                        <div>
                                                            <span className="font-medium">Résultats conditions :</span>
                                                            <pre className="mt-1 bg-muted p-2 rounded text-xs overflow-x-auto max-h-[200px]">{JSON.stringify(run.condition_results, null, 2)}</pre>
                                                        </div>
                                                    )}
                                                    {run.action_input && (
                                                        <div>
                                                            <span className="font-medium">Input action :</span>
                                                            <pre className="mt-1 bg-muted p-2 rounded text-xs overflow-x-auto max-h-[200px]">{JSON.stringify(run.action_input, null, 2)}</pre>
                                                        </div>
                                                    )}
                                                    {run.action_output && (
                                                        <div>
                                                            <span className="font-medium">Output action :</span>
                                                            <pre className="mt-1 bg-muted p-2 rounded text-xs overflow-x-auto max-h-[200px]">{JSON.stringify(run.action_output, null, 2)}</pre>
                                                        </div>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                </>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
