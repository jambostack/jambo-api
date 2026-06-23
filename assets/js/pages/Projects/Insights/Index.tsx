import { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';

import { Project, BreadcrumbItem } from '@/types';
import { useTranslation } from '@/lib/i18n';

import AppLayout from '@/layouts/app-layout';
import ProjectSidebar from '@/pages/Projects/ProjectSidebar';
import { FileText, HardDrive, Workflow, Users } from 'lucide-react';
import { ContentAreaChart, ActivityBarChart, StatusDonut, RecentActivityList } from '@/pages/Projects/Insights/charts';

interface InsightsData {
    range: string;
    content: { total: number; by_status: Record<string, number>; timeseries: { date: string; count: number }[] };
    media: { total: number; total_size: number; by_type: Record<string, number> };
    activity: { recent: { tool: string; status: string; source: string | null; by: string | null; at: string }[]; success_rate: number | null; timeseries: { date: string; count: number }[] };
    flows: { total: number; by_status: Record<string, number>; avg_duration_ms: number | null; timeseries: { date: string; count: number }[] };
    endusers: { total: number; by_status: Record<string, number>; timeseries: { date: string; count: number }[] };
}

interface Props {
    project: Project;
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let i = 0;
    while (value >= 1024 && i < units.length - 1) {
        value /= 1024;
        i++;
    }
    return `${value.toFixed(1)} ${units[i]}`;
}

function KpiCard({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
    return (
        <div className="rounded-2xl border border-border bg-card p-5">
            <div className="flex items-center gap-2 text-muted-foreground">
                {icon}
                <span className="text-xs font-medium">{label}</span>
            </div>
            <p className="mt-2 text-2xl font-bold tracking-tight">{value}</p>
        </div>
    );
}

export default function Index({ project }: Props) {
    const t = useTranslation();
    const [range, setRange] = useState('30d');
    const [data, setData] = useState<InsightsData | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError(false);
        axios
            .get(route('insights.project', project.id), { signal: controller.signal, params: { range } })
            .then((res) => setData(res.data.data))
            .catch((err) => {
                if (axios.isCancel?.(err) || err.name === 'CanceledError') return;
                setError(true);
            })
            .finally(() => setLoading(false));
        return () => controller.abort();
    }, [project.id, range]);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: route('projects.show', project.id) },
        { title: t('insights.title'), href: route('projects.insights', project.id) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('insights.title')} />
            <div className="flex flex-col lg:flex-row lg:gap-6 gap-0">
                <div className="hidden lg:block lg:w-64 lg:flex-shrink-0">
                    <ProjectSidebar project={project} />
                </div>
                <div className="flex-1 min-w-0 space-y-5">
                    <div className="flex items-center justify-between gap-4">
                        <h1 className="text-xl font-bold tracking-tight">{t('insights.title')}</h1>
                        <select
                            value={range}
                            onChange={(e) => setRange(e.target.value)}
                            aria-label={t('insights.title')}
                            className="h-9 rounded-lg border border-border bg-background px-3 text-sm"
                        >
                            <option value="7d">{t('insights.range_7d')}</option>
                            <option value="30d">{t('insights.range_30d')}</option>
                            <option value="90d">{t('insights.range_90d')}</option>
                        </select>
                    </div>

                    {error && <p className="text-sm text-destructive">{t('insights.error')}</p>}
                    {loading && <p className="text-sm text-muted-foreground">{t('insights.loading')}</p>}

                    {data && !loading && (
                        <div className="grid gap-3 grid-cols-2 lg:grid-cols-4">
                            <KpiCard icon={<FileText className="h-4 w-4" />} label={t('insights.kpi_content')} value={String(data.content.total)} />
                            <KpiCard icon={<HardDrive className="h-4 w-4" />} label={t('insights.kpi_storage')} value={formatBytes(data.media.total_size)} />
                            <KpiCard icon={<Workflow className="h-4 w-4" />} label={t('insights.kpi_flows')} value={String(data.flows.total)} />
                            <KpiCard icon={<Users className="h-4 w-4" />} label={t('insights.kpi_endusers')} value={String(data.endusers.total)} />
                        </div>
                    )}

                    {data && !loading && (
                        <div className="grid gap-3 grid-cols-1 lg:grid-cols-2">
                            <ContentAreaChart data={data.content.timeseries} title={t('insights.chart_content')} />
                            <StatusDonut byStatus={data.content.by_status} title={t('insights.chart_status')} />
                            <ActivityBarChart data={data.activity.timeseries} title={t('insights.chart_activity')} />
                            <RecentActivityList items={data.activity.recent} title={t('insights.recent_activity')} />
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
