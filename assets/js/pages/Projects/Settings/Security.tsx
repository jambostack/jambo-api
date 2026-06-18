import { Head, useForm, usePage } from '@inertiajs/react';

import type { Project, BreadcrumbItem, UserCan } from '@/types/index.d';

import AppLayout from '@/layouts/app-layout';
import ProjectSettingsLayout from './layout';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { useTranslation } from '@/lib/i18n';

interface Props {
    project: Project;
}

export default function ProjectSecuritySettings({ project }: Props) {
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
        {
            title: t('projects.settings.security'),
            href: route('projects.settings.security', project.id),
        },
    ];

    const { data, setData, put, processing, errors, recentlySuccessful } = useForm({
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

    const submit = () => {
        put(`/api/projects/${project.uuid}`, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('projects.settings.security') + ' — ' + project.name} />

            <ProjectSettingsLayout project={project}>
                <div className="space-y-6 max-w-2xl">
                    <HeadingSmall title={t('projects.settings.security')} description="Gérez l'authentification des utilisateurs finaux" />

                    {/* 2FA for End Users */}
                    <div>
                        <h3 className="text-sm font-semibold mb-3">Authentification à deux facteurs</h3>
                        <p className="text-xs text-muted-foreground mb-3">Les utilisateurs finaux devront configurer la 2FA dans leur espace personnel.</p>
                        <div className="space-y-3">
                            <label className="flex items-center gap-3">
                                <input
                                    type="checkbox"
                                    checked={data.security?.endUserTwoFactor ?? false}
                                    onChange={e => setData('security', { ...data.security, endUserTwoFactor: e.target.checked })}
                                    className="rounded"
                                />
                                <div>
                                    <span className="text-sm font-medium">Exiger l'authentification à deux facteurs</span>
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

                    <Separator />

                    {/* Social Login for End Users */}
                    <div>
                        <h3 className="text-sm font-semibold mb-3">Social Login</h3>
                        <p className="text-xs text-muted-foreground mb-3">Les utilisateurs finaux pourront se connecter avec Google, Microsoft, GitHub ou GitLab.</p>
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
                                    <span className="text-sm font-medium">Autoriser la connexion via fournisseurs OAuth</span>
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

                    <div className="flex items-center gap-4 pt-4">
                        <Button disabled={processing} onClick={submit}>{t('projects.settings.save_btn')}</Button>
                        {recentlySuccessful && (
                            <p className="text-sm text-neutral-600">{t('projects.settings.saved')}</p>
                        )}
                    </div>
                </div>
            </ProjectSettingsLayout>
        </AppLayout>
    );
}
