import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Copy, Save, Layers, FileText, Image, Webhook } from 'lucide-react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import axios from 'axios';
import { toast } from 'sonner';

import { type Project, type BreadcrumbItem, UserCan } from '@/types/index.d';

import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Button } from '@/components/ui/button';
import { HardDrive, Globe, Users, Settings, Key } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';

import ProjectSidebar from './ProjectSidebar';
import ProjectsLayout from './layout';
import { useTranslation } from '@/lib/i18n';

interface Props {
    project: Project;
}

export default function Show({ project }: Props) {
    const t = useTranslation();
    const can = usePage().props.userCan as UserCan;

    const { copied, copyToClipboard } = useCopyToast();

    const [templateModalOpen, setTemplateModalOpen] = useState(false);
    const [templateName, setTemplateName] = useState(project.name);
    const [templateDesc, setTemplateDesc] = useState(project.description ?? '');

    const [cloneModalOpen, setCloneModalOpen] = useState(false);
    const [cloneName, setCloneName] = useState(project.name + ' Copy');
    const [cloneDesc, setCloneDesc] = useState(project.description ?? '');

    const slugify = (str: string) => str.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');

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

    const diskLabel = project.disk === 'public' ? t('projects.show.local_storage_label') : project.disk.toUpperCase();

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: project.name,
            href: '/project',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={project.name} />

            <ProjectsLayout>
                <ProjectSidebar project={project} />

                <Separator className="my-6 md:hidden" />

                <div className="flex-1 max-w-full md:w-2xl lg:w-xl xl:w-3xl">
                    <section className="space-y-6">


                        <Card>
                            <CardHeader className="space-y-2">
                                <div className="flex items-start justify-between gap-4 flex-wrap">
                                    <div>
                                        <CardTitle className="text-2xl font-bold">{project.name}</CardTitle>
                                        <CardDescription>
                                            {project.description || t('projects.show.no_description')}
                                        </CardDescription>
                                    </div>
                                    {/* Badges and top-right actions */}
                                    <div className="flex flex-wrap gap-2 items-center">
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <Badge variant="outline" className="flex items-center gap-1 cursor-default">
                                                    <HardDrive className="h-3 w-3" /> {diskLabel}
                                                </Badge>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                {project.disk === 'public'
                                                    ? t('projects.show.local_storage')
                                                    : t('projects.show.s3_storage')}
                                            </TooltipContent>
                                        </Tooltip>
                                        <Badge variant="outline" className="flex items-center gap-1">
                                            <Globe className="h-3 w-3" /> {project.default_locale.toUpperCase()}
                                        </Badge>
                                        {project.public_api && (
                                            <Badge variant="secondary" className="flex items-center gap-1">
                                                {t('projects.show.public_api')}
                                            </Badge>
                                        )}
                                        {/* Actions */}
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="flex items-center gap-1"
                                            onClick={() => setTemplateModalOpen(true)}
                                        >
                                            <Save className="h-4 w-4" />
                                            <span className="hidden sm:inline">{t('projects.show.template_btn')}</span>
                                        </Button>
                                        {can.create_project && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="flex items-center gap-1"
                                                onClick={() => setCloneModalOpen(true)}
                                            >
                                                <Copy className="h-4 w-4" />
                                                <span className="hidden sm:inline">{t('projects.show.clone_btn')}</span>
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div className="flex flex-wrap gap-3 justify-center mb-4">
                                    {can.access_assets && (
                                        <QuickAction icon={Image} label={t('projects.show.asset_library')} href={route('assets.index', project.id)} />
                                    )}
                                    {can.access_project_settings && (
                                        <QuickAction icon={Settings} label={t('projects.show.general_settings')} href={route('projects.settings.project', project.id)} />
                                    )}
                                    {can.access_localization_settings && (
                                        <QuickAction icon={Globe} label={t('projects.show.localization')} href={route('projects.settings.localization', project.id)} />
                                    )}
                                    {can.access_user_access_settings && (
                                        <QuickAction icon={Users} label={t('projects.show.user_access')} href={route('projects.settings.user-access', project.id)} />
                                    )}
                                    {can.access_api_access_settings && (
                                        <QuickAction icon={Key} label={t('projects.show.api_access')} href={route('projects.settings.api-access', project.id)} />
                                    )}
                                    {can.access_webhooks_settings && (
                                        <QuickAction icon={Webhook} label={t('projects.show.webhooks')} href={route('projects.settings.webhooks', project.id)} />
                                    )}
                                </div>
                                <div className='flex flex-wrap gap-3 justify-between'>
                                    <div className="space-y-5">
                                        <div>
                                            <h3 className="text-sm font-medium">{t('projects.show.project_id')}</h3>
                                            <div className="flex items-center gap-2">
                                                <p className="text-sm text-muted-foreground break-all">{project.uuid}</p>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-6 w-6"
                                                    onClick={() => copyToClipboard(project.uuid)}
                                                >
                                                    <Copy className="h-4 w-4" />
                                                    <span className="sr-only">{t('projects.show.copy_uuid')}</span>
                                                </Button>
                                                {copied && <span className="text-xs text-green-500">{t('projects.show.copied')}</span>}
                                            </div>
                                        </div>
                                        <div>
                                            <h3 className="text-sm font-medium">{t('projects.show.storage')}</h3>
                                            <p className="text-sm text-muted-foreground uppercase">{diskLabel}</p>
                                        </div>
                                        <div>
                                            <h3 className="text-sm font-medium">{t('projects.show.default_locale')}</h3>
                                            <p className="text-sm text-muted-foreground uppercase">
                                                {project.default_locale}
                                            </p>
                                        </div>
                                        <div>
                                            <h3 className="text-sm font-medium">{t('projects.show.available_locales')}</h3>
                                            <p className="text-sm text-muted-foreground uppercase">
                                                {(project.locales?.length ? project.locales.join(', ') : project.default_locale).toUpperCase()}
                                            </p>
                                        </div>
                                        <div>
                                            <h3 className="text-sm font-medium">{t('projects.show.public_api')}</h3>
                                            <p className="text-sm text-muted-foreground uppercase">
                                                {project.public_api ? <Badge variant="default" className="flex items-center gap-1">
                                                    {t('projects.show.enabled')}
                                                </Badge> : <Badge variant="outline" className="flex items-center gap-1">
                                                    {t('projects.show.disabled')}
                                                </Badge>}
                                            </p>
                                        </div>
                                        <div>
                                            <h3 className="text-sm font-medium">{t('projects.show.created_at')}</h3>
                                            <p className="text-sm text-muted-foreground">
                                                {new Date(project.created_at).toLocaleString()}
                                            </p>
                                        </div>
                                        <div>
                                            <h3 className="text-sm font-medium">{t('projects.show.last_updated')}</h3>
                                            <p className="text-sm text-muted-foreground">
                                                {new Date(project.updated_at).toLocaleString()}
                                            </p>
                                        </div>
                                    </div>

                                    <div className='space-y-3'>
                                        <div className='text-center border border-dashed rounded-md p-4'>
                                            <StatsItem
                                                icon={Layers}
                                                label={t('projects.show.collections')}
                                                value={project.collections_count ?? project.collections?.length ?? 0}
                                            />
                                        </div>

                                        <div className='text-center border border-dashed rounded-md p-4'>
                                            <StatsItem
                                                icon={FileText}
                                                label={t('projects.show.content_entries')}
                                                value={project.content_count ?? 0}
                                            />
                                        </div>

                                        <div className='text-center border border-dashed rounded-md p-4'>
                                            <StatsItem
                                                icon={Image}
                                                label={t('projects.show.assets')}
                                                value={project.assets_count ?? 0}
                                            />
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </section>
                </div>
            </ProjectsLayout>

            {/* Save Template Modal */}
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

            {/* Clone Project Modal */}
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
        </AppLayout>
    );
}

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

interface StatsItemProps {
    icon: React.ComponentType<{ className?: string }>;
    label: string;
    value: number;
}

function StatsItem({ icon: Icon, label, value }: StatsItemProps) {
    return (
        <div className="space-y-2">
            <Icon className="mx-auto h-6 w-6 text-muted-foreground" />
            <p className="text-2xl font-semibold">{value}</p>
            <p className="text-sm text-muted-foreground">{label}</p>
        </div>
    );
}

interface QuickActionProps {
    icon: React.ComponentType<{ className?: string }>;
    label: string;
    href: string;
}

function QuickAction({ icon: Icon, label, href }: QuickActionProps) {
    return (
        <Link href={href} className="inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm hover:bg-accent">
            <Icon className="h-4 w-4" /> {label}
        </Link>
    );
}
