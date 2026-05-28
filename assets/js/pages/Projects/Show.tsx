import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Link } from '@inertiajs/react';
import {
    Copy, Save, Layers, FileText, Image, Webhook,
    Download, Upload, Check, HardDrive, Globe, Users, Settings, Key,
    BookOpen, UserCog,
} from 'lucide-react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import axios from 'axios';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';

import { type Project, type BreadcrumbItem, UserCan } from '@/types/index.d';

import AppLayout from '@/layouts/app-layout';
import { Separator } from '@/components/ui/separator';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';

import ProjectSidebar from './ProjectSidebar';
import ProjectsLayout from './layout';
import ExportModal from './Export/ExportModal';
import ImportModal from './Import/ImportModal';
import { useTranslation } from '@/lib/i18n';

interface Props {
    project: Project;
    allProjects: { uuid: string; name: string }[];
}

export default function Show({ project, allProjects }: Props) {
    const t = useTranslation();
    const can = usePage().props.userCan as UserCan;

    const { copied, copyToClipboard } = useCopyToast();

    const [templateModalOpen, setTemplateModalOpen] = useState(false);
    const [templateName, setTemplateName] = useState(project.name);
    const [templateDesc, setTemplateDesc] = useState(project.description ?? '');

    const [cloneModalOpen, setCloneModalOpen] = useState(false);
    const [cloneName, setCloneName] = useState(project.name + ' Copy');
    const [cloneDesc, setCloneDesc] = useState(project.description ?? '');

    const [exportModalOpen, setExportModalOpen] = useState(false);
    const [importModalOpen, setImportModalOpen] = useState(false);

    const slugify = (str: string) =>
        str.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');

    const handleSaveTemplate = async () => {
        try {
            await axios.post(`/api/project-templates`, {
                name: templateName,
                slug: slugify(templateName),
                description: templateDesc,
                project_uuid: project.uuid,
            });
            toast.success(t('projects.show.template_saved'));
            setTemplateModalOpen(false);
        } catch (e) {
            console.error(e);
            toast.error(t('projects.show.template_failed'));
        }
    };

    const handleCloneProject = async () => {
        try {
            const res = await axios.post(`/api/projects/${project.uuid}/clone`, {
                name: cloneName,
                description: cloneDesc,
            });
            const redirect = res.data?.redirect;
            if (redirect) {
                window.location.href = redirect;
            } else {
                toast.success(t('projects.show.clone_success'));
                setCloneModalOpen(false);
            }
        } catch (e) {
            console.error(e);
            toast.error(t('projects.show.clone_failed'));
        }
    };

    const diskLabel = project.disk === 'public'
        ? t('projects.show.local_storage_label')
        : project.disk.toUpperCase();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: '/project' },
    ];

    const localesDisplay = (project.locales?.length
        ? project.locales.join(', ')
        : project.default_locale
    ).toUpperCase();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={project.name} />

            <ProjectsLayout>
                <ProjectSidebar project={project} />

                <Separator className="my-6 md:hidden" />

                <div className="flex-1 min-w-0 space-y-6">

                    {/* ── Identity + Actions ──────────────────────── */}
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="min-w-0">
                            <h1 className="text-2xl font-bold tracking-tight truncate">
                                {project.name}
                            </h1>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {project.description || t('projects.show.no_description')}
                            </p>
                            <div className="mt-3 flex flex-wrap items-center gap-1.5">
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Badge variant="outline" className="gap-1 cursor-default text-xs font-normal">
                                            <HardDrive className="h-3 w-3" />
                                            {diskLabel}
                                        </Badge>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        {project.disk === 'public'
                                            ? t('projects.show.local_storage')
                                            : t('projects.show.s3_storage')}
                                    </TooltipContent>
                                </Tooltip>
                                <Badge variant="outline" className="gap-1 text-xs font-normal">
                                    <Globe className="h-3 w-3" />
                                    {project.default_locale.toUpperCase()}
                                </Badge>
                                {project.public_api && (
                                    <Badge variant="secondary" className="text-xs font-normal">
                                        {t('projects.show.public_api')}
                                    </Badge>
                                )}
                            </div>
                        </div>

                        {/* Action buttons */}
                        <div className="flex items-center gap-1.5 flex-wrap sm:justify-end shrink-0">
                            <Button
                                size="sm" variant="outline"
                                className="h-8 gap-1.5 text-xs"
                                onClick={() => setTemplateModalOpen(true)}
                            >
                                <Save className="h-3.5 w-3.5" />
                                <span className="hidden sm:inline">{t('projects.show.template_btn')}</span>
                            </Button>
                            {can.create_project && (
                                <Button
                                    size="sm" variant="outline"
                                    className="h-8 gap-1.5 text-xs"
                                    onClick={() => setCloneModalOpen(true)}
                                >
                                    <Copy className="h-3.5 w-3.5" />
                                    <span className="hidden sm:inline">{t('projects.show.clone_btn')}</span>
                                </Button>
                            )}
                            <Button
                                size="sm" variant="outline"
                                className="h-8 gap-1.5 text-xs"
                                onClick={() => setExportModalOpen(true)}
                            >
                                <Download className="h-3.5 w-3.5" />
                                <span className="hidden sm:inline">{t('projects.export.button')}</span>
                            </Button>
                            <Button
                                size="sm" variant="outline"
                                className="h-8 gap-1.5 text-xs"
                                onClick={() => setImportModalOpen(true)}
                            >
                                <Upload className="h-3.5 w-3.5" />
                                <span className="hidden sm:inline">{t('projects.import.button')}</span>
                            </Button>
                        </div>
                    </div>

                    {/* ── Stats row ───────────────────────────────── */}
                    <div className="grid grid-cols-3 gap-3">
                        <StatCard
                            icon={Layers}
                            label={t('projects.show.collections')}
                            value={project.collections_count ?? project.collections?.length ?? 0}
                            colorClass="bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-400"
                        />
                        <StatCard
                            icon={FileText}
                            label={t('projects.show.content_entries')}
                            value={project.content_count ?? 0}
                            colorClass="bg-sky-100 text-sky-600 dark:bg-sky-900/40 dark:text-sky-400"
                        />
                        <StatCard
                            icon={Image}
                            label={t('projects.show.assets')}
                            value={project.assets_count ?? 0}
                            colorClass="bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-400"
                        />
                    </div>

                    {/* ── Quick actions ────────────────────────────── */}
                    {(can.access_assets || can.access_project_settings || can.access_localization_settings ||
                        can.access_user_access_settings || can.access_api_access_settings ||
                        can.access_webhooks_settings || can.access_end_users_settings) && (
                        <div className="grid grid-cols-3 sm:grid-cols-4 gap-3">
                            {can.access_assets && (
                                <QuickAction
                                    icon={Image}
                                    label={t('projects.show.asset_library')}
                                    href={route('assets.index', project.id)}
                                    colorClass="bg-sky-100 text-sky-600 dark:bg-sky-900/40 dark:text-sky-400 group-hover:bg-sky-200 dark:group-hover:bg-sky-900/60"
                                />
                            )}
                            {can.access_project_settings && (
                                <QuickAction
                                    icon={Settings}
                                    label={t('projects.show.general_settings')}
                                    href={route('projects.settings.project', project.id)}
                                    colorClass="bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300 group-hover:bg-slate-200 dark:group-hover:bg-slate-700"
                                />
                            )}
                            {can.access_localization_settings && (
                                <QuickAction
                                    icon={Globe}
                                    label={t('projects.show.localization')}
                                    href={route('projects.settings.localization', project.id)}
                                    colorClass="bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400 group-hover:bg-emerald-200 dark:group-hover:bg-emerald-900/60"
                                />
                            )}
                            {can.access_user_access_settings && (
                                <QuickAction
                                    icon={Users}
                                    label={t('projects.show.user_access')}
                                    href={route('projects.settings.user-access', project.id)}
                                    colorClass="bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-400 group-hover:bg-violet-200 dark:group-hover:bg-violet-900/60"
                                />
                            )}
                            {can.access_api_access_settings && (
                                <QuickAction
                                    icon={Key}
                                    label={t('projects.show.api_access')}
                                    href={route('projects.settings.api-access', project.id)}
                                    colorClass="bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-400 group-hover:bg-amber-200 dark:group-hover:bg-amber-900/60"
                                />
                            )}
                            {can.access_api_access_settings && (
                                <QuickAction
                                    icon={BookOpen}
                                    label={t('projects.settings.nav_api_docs')}
                                    href={route('projects.settings.api-docs', project.id)}
                                    colorClass="bg-orange-100 text-orange-600 dark:bg-orange-900/40 dark:text-orange-400 group-hover:bg-orange-200 dark:group-hover:bg-orange-900/60"
                                />
                            )}
                            {can.access_webhooks_settings && (
                                <QuickAction
                                    icon={Webhook}
                                    label={t('projects.show.webhooks')}
                                    href={route('projects.settings.webhooks', project.id)}
                                    colorClass="bg-rose-100 text-rose-600 dark:bg-rose-900/40 dark:text-rose-400 group-hover:bg-rose-200 dark:group-hover:bg-rose-900/60"
                                />
                            )}
                            {can.access_end_users_settings && (
                                <QuickAction
                                    icon={UserCog}
                                    label={t('end_users.heading')}
                                    href={route('projects.settings.end-users', project.id)}
                                    colorClass="bg-teal-100 text-teal-600 dark:bg-teal-900/40 dark:text-teal-400 group-hover:bg-teal-200 dark:group-hover:bg-teal-900/60"
                                />
                            )}
                        </div>
                    )}

                    {/* ── Metadata panel ───────────────────────────── */}
                    <div className="rounded-xl border border-border/60 bg-card overflow-hidden">
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-5 p-5">
                            {/* Project UUID */}
                            <InfoItem label={t('projects.show.project_id')}>
                                <div className="flex items-center gap-1.5 mt-0.5">
                                    <code className="font-mono text-[11px] text-muted-foreground break-all">
                                        {project.uuid}
                                    </code>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="h-5 w-5 shrink-0"
                                        onClick={() => copyToClipboard(project.uuid)}
                                    >
                                        {copied
                                            ? <Check className="h-3 w-3 text-emerald-500" />
                                            : <Copy className="h-3 w-3" />
                                        }
                                        <span className="sr-only">{t('projects.show.copy_uuid')}</span>
                                    </Button>
                                </div>
                            </InfoItem>

                            {/* Storage */}
                            <InfoItem label={t('projects.show.storage')}>
                                <span className="uppercase">{diskLabel}</span>
                            </InfoItem>

                            {/* Default locale */}
                            <InfoItem label={t('projects.show.default_locale')}>
                                <span className="uppercase">{project.default_locale}</span>
                            </InfoItem>

                            {/* Available locales */}
                            <InfoItem label={t('projects.show.available_locales')}>
                                <span className="uppercase">{localesDisplay}</span>
                            </InfoItem>

                            {/* Public API */}
                            <InfoItem label={t('projects.show.public_api')}>
                                {project.public_api ? (
                                    <Badge variant="default" className="text-xs font-normal">
                                        {t('projects.show.enabled')}
                                    </Badge>
                                ) : (
                                    <Badge variant="outline" className="text-xs font-normal">
                                        {t('projects.show.disabled')}
                                    </Badge>
                                )}
                            </InfoItem>

                            {/* Created at */}
                            <InfoItem label={t('projects.show.created_at')}>
                                <span>{new Date(project.created_at).toLocaleString()}</span>
                            </InfoItem>

                            {/* Last updated */}
                            <InfoItem label={t('projects.show.last_updated')}>
                                <span>{new Date(project.updated_at).toLocaleString()}</span>
                            </InfoItem>
                        </div>
                    </div>
                </div>
            </ProjectsLayout>

            {/* ── Save Template modal ──────────────────────────── */}
            <Dialog open={templateModalOpen} onOpenChange={setTemplateModalOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('projects.show.save_template_title')}</DialogTitle>
                        <DialogDescription className="sr-only">{t('projects.show.save_template_title')}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div className="space-y-2">
                            <label className="text-sm font-medium" htmlFor="tpl_name">{t('projects.show.template_name')}</label>
                            <Input id="tpl_name" value={templateName} onChange={e => setTemplateName(e.target.value)} />
                        </div>
                        <div className="space-y-2">
                            <label className="text-sm font-medium" htmlFor="tpl_desc">{t('projects.show.template_desc')}</label>
                            <Textarea id="tpl_desc" value={templateDesc} onChange={e => setTemplateDesc(e.target.value)} rows={3} />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setTemplateModalOpen(false)}>{t('projects.show.cancel')}</Button>
                        <Button onClick={handleSaveTemplate}>{t('projects.show.save_btn')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ── Clone Project modal ──────────────────────────── */}
            <Dialog open={cloneModalOpen} onOpenChange={setCloneModalOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('projects.show.clone_title')}</DialogTitle>
                        <DialogDescription className="sr-only">{t('projects.show.clone_title')}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div className="space-y-2">
                            <label className="text-sm font-medium" htmlFor="clone_name">{t('projects.show.clone_name')}</label>
                            <Input id="clone_name" value={cloneName} onChange={e => setCloneName(e.target.value)} />
                        </div>
                        <div className="space-y-2">
                            <label className="text-sm font-medium" htmlFor="clone_desc">{t('projects.show.clone_desc')}</label>
                            <Textarea id="clone_desc" value={cloneDesc} onChange={e => setCloneDesc(e.target.value)} rows={3} />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCloneModalOpen(false)}>{t('projects.show.cancel')}</Button>
                        <Button onClick={handleCloneProject}>{t('projects.show.clone_action')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ExportModal
                open={exportModalOpen}
                onOpenChange={setExportModalOpen}
                projectUuid={project.uuid}
                projectName={project.name}
            />

            <ImportModal
                open={importModalOpen}
                onOpenChange={setImportModalOpen}
                projects={allProjects}
            />
        </AppLayout>
    );
}

// ── Hooks ─────────────────────────────────────────────────────────────

function useCopyToast() {
    const [copied, setCopied] = useState(false);

    const copyToClipboard = async (text: string) => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch (e) {
            console.error('Failed to copy', e);
        }
    };

    return { copied, copyToClipboard };
}

// ── Sub-components ────────────────────────────────────────────────────

interface StatCardProps {
    icon: React.ComponentType<{ className?: string }>;
    label: string;
    value: number;
    colorClass?: string;
}

function StatCard({ icon: Icon, label, value, colorClass }: StatCardProps) {
    return (
        <div className="rounded-xl border border-border/60 bg-card p-4 space-y-3">
            <div className={cn('flex h-8 w-8 items-center justify-center rounded-lg', colorClass)}>
                <Icon className="h-4 w-4" />
            </div>
            <div>
                <p className="text-3xl font-bold tabular-nums tracking-tight">
                    {value.toLocaleString()}
                </p>
                <p className="text-xs text-muted-foreground mt-0.5">{label}</p>
            </div>
        </div>
    );
}

interface QuickActionProps {
    icon: React.ComponentType<{ className?: string }>;
    label: string;
    href: string;
    colorClass?: string;
}

function QuickAction({ icon: Icon, label, href, colorClass }: QuickActionProps) {
    return (
        <Link
            href={href}
            className="group flex flex-col items-center gap-2.5 rounded-xl border border-border/60 bg-card p-4 text-center transition-all duration-200 hover:border-border hover:shadow-sm hover:-translate-y-px"
        >
            <div className={cn(
                'flex h-9 w-9 items-center justify-center rounded-xl transition-colors',
                colorClass
            )}>
                <Icon className="h-[18px] w-[18px]" />
            </div>
            <span className="text-xs font-medium leading-tight">{label}</span>
        </Link>
    );
}

function InfoItem({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="space-y-1.5">
            <p className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                {label}
            </p>
            <div className="text-sm font-medium text-foreground flex items-center gap-1.5">
                {children}
            </div>
        </div>
    );
}
