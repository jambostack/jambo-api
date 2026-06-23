import { useState, useEffect } from 'react';
import axios from 'axios';
import { Head, router, usePage } from '@inertiajs/react';

import { type BreadcrumbItem, type Project, type UserCan } from '@/types/index.d';
import { useTranslation } from '@/lib/i18n';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { ArrowRight, Folder, Plus, Upload } from 'lucide-react';
import { SearchBar } from '@/components/ui/search-bar';

import CreateProjectModal from '@/pages/Projects/CreateProjectModal';
import ImportModal from '@/pages/Projects/Import/ImportModal';

interface Props {
    projects: Project[];
}

const PROJECT_COLORS = [
    'oklch(0.58 0.18 158)',  // émeraude
    'oklch(0.62 0.16 195)',  // teal
    'oklch(0.72 0.20 130)',  // lime
    'oklch(0.60 0.17 220)',  // bleu-vert
    'oklch(0.65 0.18 170)',  // vert menthe
];

function getProjectColor(id: number | string): string {
    const idx = String(id).split('').reduce((acc, c) => acc + c.charCodeAt(0), 0);
    return PROJECT_COLORS[idx % PROJECT_COLORS.length];
}

function ProjectInitials({ name }: { name: string }) {
    const parts = name.trim().split(/\s+/);
    return parts.length >= 2
        ? (parts[0][0] + parts[1][0]).toUpperCase()
        : name.slice(0, 2).toUpperCase();
}

export default function Dashboard({ projects }: Props) {
    const t = useTranslation();
    const can = (usePage().props.userCan || {}) as UserCan;

    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
    const [isImportModalOpen, setIsImportModalOpen] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');

    const [summary, setSummary] = useState<null | { projects: number; content_total: number; media_total: number; storage_bytes: number; endusers_total: number }>(null);

    useEffect(() => {
        axios.get(route('insights.summary')).then((res) => setSummary(res.data.data)).catch(() => {});
    }, []);

    const formatBytes = (bytes: number): string => {
        if (bytes < 1024) return `${bytes} B`;
        const units = ['KB', 'MB', 'GB', 'TB'];
        let v = bytes / 1024, i = 0;
        while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
        return `${v.toFixed(1)} ${units[i]}`;
    };

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: t('dashboard.page_title'),
            href: '/',
        },
    ];

    const filteredProjects = (projects || []).filter(
        (project) =>
            project.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
            (project.description?.toLowerCase().includes(searchQuery.toLowerCase()) ?? false),
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('dashboard.page_title')} />

            <div className="flex flex-col gap-5">
                {/* Page header */}
                <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-xl font-bold tracking-tight">{t('dashboard.title')}</h1>
                        <p className="text-sm text-muted-foreground mt-0.5">
                            {t('dashboard.project_count', { count: String((projects || []).length) })}
                        </p>
                    </div>
                    {can.create_project && (
                        <div className="flex gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                className="self-start sm:self-auto gap-1.5"
                                onClick={() => setIsImportModalOpen(true)}
                            >
                                <Upload className="h-4 w-4" />
                                {t('projects.import.button')}
                            </Button>
                            <Button
                                size="sm"
                                className="self-start sm:self-auto gap-1.5"
                                onClick={() => setIsCreateModalOpen(true)}
                            >
                                <Plus className="h-4 w-4" />
                                {t('dashboard.new_project')}
                            </Button>
                        </div>
                    )}
                </div>

                {/* Stats band */}
                {summary && summary.projects > 0 && (
                    <div className="grid gap-3 grid-cols-2 lg:grid-cols-4">
                        <div className="rounded-2xl border border-border bg-card p-4">
                            <p className="text-xs text-muted-foreground">{t('insights.home_content')}</p>
                            <p className="mt-1 text-xl font-bold">{summary.content_total}</p>
                        </div>
                        <div className="rounded-2xl border border-border bg-card p-4">
                            <p className="text-xs text-muted-foreground">{t('insights.home_media')}</p>
                            <p className="mt-1 text-xl font-bold">{summary.media_total}</p>
                        </div>
                        <div className="rounded-2xl border border-border bg-card p-4">
                            <p className="text-xs text-muted-foreground">{t('insights.home_storage')}</p>
                            <p className="mt-1 text-xl font-bold">{formatBytes(summary.storage_bytes)}</p>
                        </div>
                        <div className="rounded-2xl border border-border bg-card p-4">
                            <p className="text-xs text-muted-foreground">{t('insights.home_endusers')}</p>
                            <p className="mt-1 text-xl font-bold">{summary.endusers_total}</p>
                        </div>
                    </div>
                )}

                {/* Search */}
                <SearchBar value={searchQuery} onChange={setSearchQuery} placeholder={t('dashboard.search')} />

                {/* Empty state */}
                {filteredProjects.length === 0 && !can.create_project && (
                    <div className="flex flex-col items-center justify-center py-20 gap-3 text-center">
                        <div className="w-14 h-14 rounded-2xl bg-muted flex items-center justify-center">
                            <Folder className="h-7 w-7 text-muted-foreground" />
                        </div>
                        <div>
                            <p className="font-semibold text-sm">{t('dashboard.empty_title')}</p>
                            <p className="text-sm text-muted-foreground">{t('dashboard.empty_description')}</p>
                        </div>
                    </div>
                )}

                {/* Project grid */}
                <div className="grid gap-3 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    {/* Create card */}
                    {can.create_project && (
                        <button
                            onClick={() => setIsCreateModalOpen(true)}
                            className="group relative flex flex-col items-center justify-center gap-2.5 rounded-2xl border-2 border-dashed border-border/70 bg-transparent p-6 text-muted-foreground transition-all duration-200 hover:border-primary/40 hover:bg-primary/[0.03] hover:text-foreground cursor-pointer min-h-[120px]"
                        >
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-muted group-hover:bg-primary/10 transition-colors">
                                <Plus className="h-5 w-5 group-hover:text-primary transition-colors" />
                            </div>
                            <span className="text-sm font-medium">{t('dashboard.new_project')}</span>
                        </button>
                    )}

                    {/* Project cards */}
                    {filteredProjects.map((project) => {
                        const color = getProjectColor(project.id);
                        return (
                            <button
                                key={project.id}
                                onClick={() => router.visit(route('projects.show', project.id))}
                                className="group relative flex flex-col justify-between rounded-2xl border border-border bg-card p-5 text-left cursor-pointer transition-all duration-200 hover:border-border/80 hover:shadow-[0_4px_24px_oklch(0_0_0/0.06)] min-h-[120px] overflow-hidden"
                                style={{ '--project-color': color } as React.CSSProperties}
                            >
                                {/* Top accent bar */}
                                <div
                                    className="absolute top-0 left-0 right-0 h-0.5 opacity-0 group-hover:opacity-100 transition-opacity"
                                    style={{ background: color }}
                                />

                                <div className="flex items-start gap-3">
                                    {/* Project avatar */}
                                    <div
                                        className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl text-sm font-bold text-white"
                                        style={{ background: color }}
                                    >
                                        <ProjectInitials name={project.name} />
                                    </div>

                                    <div className="min-w-0 flex-1 pt-0.5">
                                        <p className="text-sm font-semibold leading-snug truncate">{project.name}</p>
                                        {project.description && (
                                            <p className="mt-0.5 text-xs text-muted-foreground line-clamp-2">
                                                {project.description}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                <div className="mt-3 flex items-center justify-end">
                                    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground group-hover:text-foreground transition-colors">
                                        {t('dashboard.open')}
                                        <ArrowRight className="h-3 w-3 -translate-x-0.5 group-hover:translate-x-0 transition-transform" />
                                    </span>
                                </div>
                            </button>
                        );
                    })}
                </div>
            </div>

            <CreateProjectModal open={isCreateModalOpen} onOpenChange={setIsCreateModalOpen} />

            <ImportModal
                open={isImportModalOpen}
                onOpenChange={setIsImportModalOpen}
                projects={(projects || []).map((p) => ({ uuid: p.uuid, name: p.name }))}
            />
        </AppLayout>
    );
}
