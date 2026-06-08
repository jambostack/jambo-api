import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import type { Project, BreadcrumbItem, UserCan } from '@/types/index.d';

import AppLayout from '@/layouts/app-layout';
import ProjectSettingsLayout from './layout';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import DeleteProject from '@/components/delete-project';
import { Separator } from '@/components/ui/separator';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { useTranslation } from '@/lib/i18n';

interface Props {
    project: Project;
}

/** Format seconds as a human-readable duration. */
function formatTtl(seconds: number): string {
    if (seconds >= 86400) {
        const d = Math.round(seconds / 86400)
        return seconds % 86400 === 0
            ? `= ${d} day${d > 1 ? 's' : ''}`
            : `= ${d} day${d > 1 ? 's' : ''} (${seconds}s)`
    }
    if (seconds >= 3600) {
        const h = Math.round(seconds / 3600)
        return seconds % 3600 === 0
            ? `= ${h} hour${h > 1 ? 's' : ''}`
            : `= ${h} hour${h > 1 ? 's' : ''} (${seconds}s)`
    }
    if (seconds >= 60) {
        const m = Math.round(seconds / 60)
        return seconds % 60 === 0
            ? `= ${m} min`
            : `= ${m} min (${seconds}s)`
    }
    return `= ${seconds} seconds`
}

export default function ProjectSettingsPage({ project }: Props) {
    const t = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: project.name,
            href: route('projects.show', project.id),
        },
        {
            title: t('projects.settings.title'),
            href: route('projects.settings.project', project.id),
        },
    ];

    const { data, setData, put, processing, errors, recentlySuccessful } = useForm({
        name: project.name || '',
        description: project.description || '',
        default_locale: project.default_locale || '',
        disk: project.disk || 'public',
        jwt_access_ttl: project.jwt_access_ttl ?? '',
        jwt_refresh_ttl: project.jwt_refresh_ttl ?? '',
    });

    const can = usePage().props.userCan as UserCan;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/api/projects/${project.uuid}`, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('projects.settings.page_title')} />

            <ProjectSettingsLayout project={project}>
                <div className="space-y-6 max-w-2xl">
                    <HeadingSmall title={t('projects.settings.project')} description={t('projects.settings.project_desc')} />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name">{t('projects.settings.project_name')}</Label>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                                placeholder={t('projects.settings.project_name_ph')}
                            />
                            <InputError className="mt-2" message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="description">{t('projects.settings.desc_label')}</Label>
                            <Input
                                id="description"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                placeholder={t('projects.settings.desc_ph')}
                            />
                            <InputError className="mt-2" message={errors.description} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="disk">{t('projects.settings.storage')}</Label>
                            <RadioGroup
                                id="disk"
                                value={data.disk}
                                onValueChange={(value) => setData('disk', value as 'public' | 's3')}
                                className="flex gap-4"
                            >
                                <div className="flex flex-col p-4 px-6 border border-dashed rounded-md">
                                    <div className="flex items-center space-x-2">
                                        <RadioGroupItem value="public" id="disk-public" />
                                        <Label htmlFor="disk-public" className="font-medium">{t('projects.settings.local_storage')}</Label>
                                    </div>
                                    <p className="text-xs text-muted-foreground pl-6">{t('projects.settings.local_storage_desc')}</p>
                                </div>
                                <div className="flex items-center space-x-1 p-4 px-10 pl-4 border border-dashed rounded-md">
                                    <RadioGroupItem value="s3" id="disk-s3" />
                                    <Label htmlFor="disk-s3">{t('projects.settings.s3')}</Label>
                                </div>
                            </RadioGroup>
                        </div>

                        <Separator />

                        <HeadingSmall title={t('projects.settings.jwt_title')} description={t('projects.settings.jwt_desc')} />

                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="jwt_access_ttl">{t('projects.settings.jwt_access_ttl')}</Label>
                                <Input
                                    id="jwt_access_ttl"
                                    type="number"
                                    min={60}
                                    value={data.jwt_access_ttl}
                                    onChange={(e) => setData('jwt_access_ttl', e.target.value)}
                                    placeholder={t('projects.settings.jwt_access_ttl_hint')}
                                />
                                <p className="text-xs text-muted-foreground">
                                    {data.jwt_access_ttl && Number(data.jwt_access_ttl) >= 60
                                        ? formatTtl(Number(data.jwt_access_ttl))
                                        : data.jwt_access_ttl && Number(data.jwt_access_ttl) > 0
                                            ? t('projects.settings.jwt_ttl_min') : t('projects.settings.jwt_access_ttl_hint')}
                                </p>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="jwt_refresh_ttl">{t('projects.settings.jwt_refresh_ttl')}</Label>
                                <Input
                                    id="jwt_refresh_ttl"
                                    type="number"
                                    min={60}
                                    value={data.jwt_refresh_ttl}
                                    onChange={(e) => setData('jwt_refresh_ttl', e.target.value)}
                                    placeholder={t('projects.settings.jwt_refresh_ttl_hint')}
                                />
                                <p className="text-xs text-muted-foreground">
                                    {data.jwt_refresh_ttl && Number(data.jwt_refresh_ttl) >= 60
                                        ? formatTtl(Number(data.jwt_refresh_ttl))
                                        : data.jwt_refresh_ttl && Number(data.jwt_refresh_ttl) > 0
                                            ? t('projects.settings.jwt_ttl_min') : t('projects.settings.jwt_refresh_ttl_hint')}
                                </p>
                            </div>
                        </div>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>{t('projects.settings.save_btn')}</Button>
                            {recentlySuccessful && (
                                <p className="text-sm text-neutral-600">{t('projects.settings.saved')}</p>
                            )}
                        </div>
                    </form>

                    {can.delete_project && (
                        <>
                            <Separator />
                            <DeleteProject projectId={project.id} projectUuid={project.uuid} projectName={project.name} />
                        </>
                    )}
                </div>


            </ProjectSettingsLayout>
        </AppLayout>
    );
}
