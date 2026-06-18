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
import { useTranslation } from '@/lib/i18n';

interface Props {
    project: Project;
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
        security: project.security || { endUserTwoFactor: false },
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

                    {/* Security Settings */}
                    <div className="mt-6 border-t pt-6">
                        <h3 className="text-sm font-semibold mb-3">Sécurité</h3>
                        <div className="space-y-3">
                            <label className="flex items-center gap-3">
                                <input
                                    type="checkbox"
                                    checked={data.security?.endUserTwoFactor ?? false}
                                    onChange={e => setData('security', { ...data.security, endUserTwoFactor: e.target.checked })}
                                    className="rounded"
                                />
                                <div>
                                    <span className="text-sm font-medium">Authentification à deux facteurs pour les utilisateurs finaux</span>
                                    <p className="text-xs text-muted-foreground">Les utilisateurs finaux devront configurer la 2FA dans leur espace personnel.</p>
                                </div>
                            </label>

                            {data.security?.endUserTwoFactor && (
                                <div className="ml-8 space-y-2">
                                    <span className="text-xs text-muted-foreground">Méthodes autorisées :</span>
                                    <label className="flex items-center gap-2">
                                        <input type="checkbox" defaultChecked className="rounded" />
                                        <span className="text-sm">TOTP (application d'authentification)</span>
                                    </label>
                                    <label className="flex items-center gap-2">
                                        <input type="checkbox" defaultChecked className="rounded" />
                                        <span className="text-sm">Email</span>
                                    </label>
                                </div>
                            )}
                        </div>
                    </div>
                </div>


            </ProjectSettingsLayout>
        </AppLayout>
    );
}
