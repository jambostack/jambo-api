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
        security: {
            endUserTwoFactor: project.security?.endUserTwoFactor ?? false,
            endUserTwoFactorMethods: project.security?.endUserTwoFactorMethods ?? ['totp', 'email'],
            endUserSocialLogin: project.security?.endUserSocialLogin ?? false,
            endUserSocialProviders: {
                google:    { enabled: project.security?.endUserSocialProviders?.google?.enabled ?? false,    clientId: '', clientSecret: '' },
                microsoft: { enabled: project.security?.endUserSocialProviders?.microsoft?.enabled ?? false, clientId: '', clientSecret: '' },
                github:    { enabled: project.security?.endUserSocialProviders?.github?.enabled ?? false,    clientId: '', clientSecret: '' },
                gitlab:    { enabled: project.security?.endUserSocialProviders?.gitlab?.enabled ?? false,    clientId: '', clientSecret: '' },
            },
        },
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

                    {/* Security Settings */}
                    <Separator />
                    <div>
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

                    {/* Social Login for End Users */}
                    <Separator />
                    <div>
                        <h3 className="text-sm font-semibold mb-3">Social Login pour les utilisateurs finaux</h3>
                        <div className="space-y-3">
                            <label className="flex items-center gap-3">
                                <input
                                    type="checkbox"
                                    checked={data.security?.endUserSocialLogin ?? false}
                                    onChange={e => setData('security', {
                                        ...data.security,
                                        endUserSocialLogin: e.target.checked,
                                    })}
                                    className="rounded"
                                />
                                <div>
                                    <span className="text-sm font-medium">Connexion via fournisseurs OAuth</span>
                                    <p className="text-xs text-muted-foreground">Les utilisateurs finaux pourront se connecter avec Google, Microsoft, GitHub ou GitLab.</p>
                                </div>
                            </label>

                            {data.security?.endUserSocialLogin && (
                                <div className="ml-8 space-y-4">
                                    {(['google', 'microsoft', 'github', 'gitlab'] as const).map(p => {
                                        const sp = data.security.endUserSocialProviders?.[p];
                                        const pp = project.security?.endUserSocialProviders?.[p];
                                        const isConfigured = pp?.configured ?? false;

                                        return (
                                            <div key={p} className="border rounded-lg p-3 space-y-2">
                                                <div className="flex items-center justify-between">
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-sm font-medium capitalize">{p}</span>
                                                        {isConfigured && sp?.enabled && (
                                                            <span className="text-xs text-green-600 font-medium">Configuré</span>
                                                        )}
                                                    </div>
                                                    <label className="flex items-center gap-2 text-xs">
                                                        <input
                                                            type="checkbox"
                                                            checked={sp?.enabled ?? false}
                                                            onChange={e => setData('security', {
                                                                ...data.security,
                                                                endUserSocialProviders: {
                                                                    ...data.security.endUserSocialProviders,
                                                                    [p]: { ...sp, enabled: e.target.checked },
                                                                },
                                                            })}
                                                            className="rounded"
                                                        />
                                                        Activé
                                                    </label>
                                                </div>

                                                {sp?.enabled && (
                                                    <div className="space-y-2 pl-2 border-l-2 border-muted">
                                                        <div>
                                                            <Label className="text-xs">Client ID</Label>
                                                            <Input
                                                                placeholder="..."
                                                                value={sp.clientId ?? ''}
                                                                onChange={e => setData('security', {
                                                                    ...data.security,
                                                                    endUserSocialProviders: {
                                                                        ...data.security.endUserSocialProviders,
                                                                        [p]: { ...sp, clientId: e.target.value },
                                                                    },
                                                                })}
                                                                className="h-8 text-xs"
                                                            />
                                                        </div>
                                                        <div>
                                                            <Label className="text-xs">Client Secret</Label>
                                                            <Input
                                                                type="password"
                                                                placeholder={isConfigured ? '••••••••' : '...'}
                                                                value={sp.clientSecret ?? ''}
                                                                onChange={e => setData('security', {
                                                                    ...data.security,
                                                                    endUserSocialProviders: {
                                                                        ...data.security.endUserSocialProviders,
                                                                        [p]: { ...sp, clientSecret: e.target.value },
                                                                    },
                                                                })}
                                                                className="h-8 text-xs"
                                                            />
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    </div>

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
