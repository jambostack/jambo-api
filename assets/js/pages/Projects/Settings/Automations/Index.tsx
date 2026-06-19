import { useState, useEffect } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { Plus, Edit, Trash, Play, Clock, List, Workflow } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import type { Project } from '@/types';
import AutomationForm from './AutomationForm';
import AutomationRuns from './AutomationRuns';
import FlowBuilderPage from '../../../Automations/FlowBuilder/FlowBuilderPage';

interface Automation {
    id: number;
    uuid: string;
    name: string;
    is_active: boolean;
    debug_mode: boolean;
    flow_graph: Record<string, any> | null;
    last_run_at: string | null;
    created_at: string;
    updated_at: string;
}

interface Props {
    project?: Project;
    projectUuid?: string;
}

const TRIGGER_LABELS: Record<string, string> = {
    'content.created': 'Contenu créé',
    'content.updated': 'Contenu modifié',
    'content.deleted': 'Contenu supprimé',
    'content.status_changed': 'Statut changé',
    'schedule.cron': 'Planifié (cron)',
};

const ACTION_LABELS: Record<string, string> = {
    'send_email': 'Envoyer un email',
    'call_webhook': 'Appeler un webhook',
    'create_entry': 'Créer une entrée',
    'update_entry': 'Modifier une entrée',
    'send_notification': 'Envoyer une notification',
};

export default function AutomationsIndex({ project, projectUuid }: Props) {
    const uuid = project?.uuid || projectUuid || '';
    const [automations, setAutomations] = useState<Automation[]>([]);
    const [loading, setLoading] = useState(true);
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Automation | null>(null);
    const [runsFor, setRunsFor] = useState<Automation | null>(null);
    const [builderOpen, setBuilderOpen] = useState(false);
    const [builderId, setBuilderId] = useState<number | null>(null);

    const load = async () => {
        try {
            const r = await axios.get(`/api/projects/${uuid}/automations`);
            setAutomations(r.data.data || []);
        } catch {
            toast.error('Erreur de chargement des automatisations');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => { load(); }, [uuid]);

    const handleDelete = async (id: number) => {
        if (!confirm('Supprimer cette automatisation ?')) return;
        try {
            await axios.delete(`/api/projects/${projectUuid}/automations/${id}`);
            toast.success('Automatisation supprimée');
            load();
        } catch {
            toast.error('Échec de la suppression');
        }
    };

    const handleToggle = async (a: Automation) => {
        try {
            await axios.put(`/api/projects/${projectUuid}/automations/${a.id}`, { is_active: !a.is_active });
            load();
        } catch {
            toast.error('Échec');
        }
    };

    if (builderOpen) {
        return (
            <FlowBuilderPage
                projectUuid={uuid}
                automationId={builderId}
                onClose={() => { setBuilderOpen(false); load(); }}
                onSaved={() => { setBuilderOpen(false); load(); }}
            />
        );
    }

    if (runsFor) {
        return <AutomationRuns projectUuid={uuid} automation={runsFor} onBack={() => setRunsFor(null)} />;
    }

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <div>
                    <h3 className="text-lg font-semibold">Automatisations</h3>
                    <p className="text-sm text-muted-foreground">Flows visuels avec nodes, branches et actions</p>
                </div>
                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => { setBuilderId(null); setBuilderOpen(true); }}
                    >
                        <Workflow className="h-3.5 w-3.5 mr-1" /> Flow Builder
                    </Button>
                    <Button size="sm" onClick={() => { setEditing(null); setFormOpen(true); }}>
                        <Plus className="h-3.5 w-3.5 mr-1" /> Nouvelle automatisation
                    </Button>
                </div>
            </div>

            {loading ? (
                <div className="flex justify-center py-12"><div className="animate-spin rounded-full h-6 w-6 border-b-2 border-primary" /></div>
            ) : automations.length === 0 ? (
                <div className="text-center py-12 text-muted-foreground text-sm">
                    Aucune automatisation. Créez-en une pour automatiser vos tâches.
                </div>
            ) : (
                <div className="border rounded-lg overflow-hidden">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50">
                            <tr>
                                <th className="text-left px-4 py-2 font-medium">Nom</th>
                                <th className="text-left px-4 py-2 font-medium">Déclencheur</th>
                                <th className="text-left px-4 py-2 font-medium">Action</th>
                                <th className="text-left px-4 py-2 font-medium">Statut</th>
                                <th className="text-left px-4 py-2 font-medium">Dernière exécution</th>
                                <th className="text-right px-4 py-2 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {automations.map(a => (
                                <tr key={a.id} className="hover:bg-muted/30">
                                    <td className="px-4 py-2.5 font-medium">{a.name}</td>
                                    <td className="px-4 py-2.5 text-xs">
                                        {a.flow_graph?.nodes?.[0]?.data?.label || '—'}
                                    </td>
                                    <td className="px-4 py-2.5 text-xs">
                                        {a.flow_graph?.nodes?.[a.flow_graph.nodes.length - 1]?.data?.label || '—'}
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <Badge variant={a.is_active ? 'default' : 'secondary'} className="cursor-pointer text-xs" onClick={() => handleToggle(a)}>
                                            {a.is_active ? 'Actif' : 'Inactif'}
                                        </Badge>
                                    </td>
                                    <td className="px-4 py-2.5 text-xs text-muted-foreground">
                                        {a.last_run_at ? new Date(a.last_run_at).toLocaleString() : '—'}
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <div className="flex items-center justify-end gap-1">
                                            <Button variant="ghost" size="icon" className="h-7 w-7" onClick={() => { setRunsFor(a); }}>
                                                <List className="h-3.5 w-3.5" />
                                            </Button>
                                            <Button variant="ghost" size="icon" className="h-7 w-7" onClick={() => { setBuilderId(a.id); setBuilderOpen(true); }}>
                                                <Workflow className="h-3.5 w-3.5" />
                                            </Button>
                                            <Button variant="ghost" size="icon" className="h-7 w-7" onClick={() => { setEditing(a); setFormOpen(true); }}>
                                                <Edit className="h-3.5 w-3.5" />
                                            </Button>
                                            <Button variant="ghost" size="icon" className="h-7 w-7 text-destructive" onClick={() => handleDelete(a.id)}>
                                                <Trash className="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {formOpen && (
                <AutomationForm
                    projectUuid={uuid}
                    automation={editing}
                    onClose={() => setFormOpen(false)}
                    onSaved={() => { setFormOpen(false); load(); }}
                />
            )}
        </div>
    );
}
