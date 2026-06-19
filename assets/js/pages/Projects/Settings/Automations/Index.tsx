import { router, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { Plus, Edit, Trash, List, Workflow } from 'lucide-react';

import type { Project, BreadcrumbItem, UserCan } from '@/types';

import AppLayout from '@/layouts/app-layout';
import ProjectSettingsLayout from '../layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useTranslation } from '@/lib/i18n';
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
    project: Project;
}

export default function AutomationsSettings({ project }: Props) {
    const t = useTranslation();
    const can = usePage().props.userCan as UserCan;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: route('projects.show', project.id) },
        { title: t('flow.breadcrumb_settings'), href: route('projects.settings.project', project.id) },
        { title: t('flow.breadcrumb'), href: route('projects.settings.automations', project.id) },
    ];

    const [automations, setAutomations] = useState<Automation[]>([]);
    const [loading, setLoading] = useState(true);
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Automation | null>(null);
    const [runsFor, setRunsFor] = useState<Automation | null>(null);
    const [builderOpen, setBuilderOpen] = useState(false);
    const [builderId, setBuilderId] = useState<number | null>(null);

    const load = async () => {
        try {
            const r = await axios.get(`/api/projects/${project.uuid}/automations`);
            setAutomations(r.data.data || []);
        } catch {
            toast.error(t('flow.load_error'));
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => { load(); }, [project.uuid]);

    const handleDelete = async (id: number) => {
        if (!confirm(t('flow.delete_confirm'))) return;
        try {
            await axios.delete(`/api/projects/${project.uuid}/automations/${id}`);
            toast.success(t('flow.deleted_success'));
            load();
        } catch {
            toast.error(t('flow.delete_error'));
        }
    };

    const handleToggle = async (a: Automation) => {
        try {
            await axios.put(`/api/projects/${project.uuid}/automations/${a.id}`, { is_active: !a.is_active });
            load();
        } catch {
            toast.error(t('flow.toggle_error'));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <ProjectSettingsLayout project={project}>
                {builderOpen ? (
                    <FlowBuilderPage
                        projectUuid={project.uuid}
                        automationId={builderId}
                        onClose={() => { setBuilderOpen(false); load(); }}
                        onSaved={() => { setBuilderOpen(false); load(); }}
                    />
                ) : runsFor ? (
                    <AutomationRuns projectUuid={project.uuid} automation={runsFor} onBack={() => setRunsFor(null)} />
                ) : (
                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm text-muted-foreground">{t('flow.heading_desc')}</p>
                            </div>
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => { setBuilderId(null); setBuilderOpen(true); }}
                                >
                                    <Workflow className="h-3.5 w-3.5 mr-1" /> {t('flow.builder_btn')}
                                </Button>
                                <Button size="sm" onClick={() => { setEditing(null); setFormOpen(true); }}>
                                    <Plus className="h-3.5 w-3.5 mr-1" /> {t('flow.assistant_btn')}
                                </Button>
                            </div>
                        </div>

                        {loading ? (
                            <div className="flex justify-center py-12">
                                <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-primary" />
                            </div>
                        ) : automations.length === 0 ? (
                            <div className="text-center py-12 text-muted-foreground text-sm">
                                {t('flow.empty_list')}
                            </div>
                        ) : (
                            <div className="border rounded-lg overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50">
                                        <tr>
                                            <th className="text-left px-4 py-2 font-medium">{t('flow.col_name')}</th>
                                            <th className="text-left px-4 py-2 font-medium">{t('flow.col_trigger')}</th>
                                            <th className="text-left px-4 py-2 font-medium">{t('flow.col_action')}</th>
                                            <th className="text-left px-4 py-2 font-medium">{t('flow.col_status')}</th>
                                            <th className="text-left px-4 py-2 font-medium">{t('flow.col_last_run')}</th>
                                            <th className="text-right px-4 py-2 font-medium">{t('flow.col_actions')}</th>
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
                                                        {a.is_active ? t('flow.active_status') : t('flow.inactive_status')}
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
                                projectUuid={project.uuid}
                                automation={editing}
                                onClose={() => setFormOpen(false)}
                                onSaved={() => { setFormOpen(false); load(); }}
                            />
                        )}
                    </div>
                )}
            </ProjectSettingsLayout>
        </AppLayout>
    );
}
