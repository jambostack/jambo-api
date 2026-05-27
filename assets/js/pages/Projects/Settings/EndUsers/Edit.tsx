import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';

import type { Project, BreadcrumbItem, UserCan, EndUser } from '@/types';

import AppLayout from '@/layouts/app-layout';
import ProjectSettingsLayout from '../layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import InputError from '@/components/input-error';

interface FormData {
    name: string;
    email: string;
    password: string;
    custom_fields: string;
}

interface Props {
    project: Project;
    endUser: EndUser;
    userCan: UserCan;
}

export default function EndUsersEdit({ project, endUser }: Props) {
    const t = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: route('projects.show', project.id) },
        { title: t('projects.settings.title'), href: route('projects.settings.project', project.id) },
        { title: t('end_users.heading'), href: route('projects.settings.end-users', project.id) },
        { title: endUser.email, href: route('projects.settings.end-users.show', { project: project.id, endUserUuid: endUser.uuid }) },
        { title: t('common.edit'), href: '#' },
    ];

    const { data, setData, patch, processing, errors } = useForm<FormData>({
        name: endUser.name || '',
        email: endUser.email,
        password: '',
        custom_fields: endUser.custom_fields ? JSON.stringify(endUser.custom_fields, null, 2) : '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        const payload: Record<string, any> = {
            name: data.name || null,
            email: data.email,
        };
        if (data.password) {
            payload.password = data.password;
        }
        if (data.custom_fields.trim()) {
            try {
                payload.custom_fields = JSON.parse(data.custom_fields);
            } catch {
                // if invalid JSON, send as-is and let server reject
                payload.custom_fields = data.custom_fields;
            }
        }
        patch(
            route('projects.settings.end-users.update', { project: project.id, endUserUuid: endUser.uuid }),
            payload as any
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${t('end_users.edit')} ${endUser.email} — ${t('end_users.heading')}`} />
            <ProjectSettingsLayout project={project}>
                <div className="max-w-lg space-y-6">
                    <div className="flex items-center gap-2">
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={route('projects.settings.end-users.show', { project: project.id, endUserUuid: endUser.uuid })}>
                                <ArrowLeft className="mr-1 h-4 w-4" /> {t('end_users.back')}
                            </Link>
                        </Button>
                    </div>

                    <h2 className="text-lg font-semibold">{t('end_users.edit')}</h2>

                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="space-y-1">
                            <Label htmlFor="name">{t('end_users.name')}</Label>
                            <Input
                                id="name"
                                placeholder={t('end_users.name_placeholder')}
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="email">{t('end_users.col_email')}</Label>
                            <Input
                                id="email"
                                type="email"
                                placeholder={t('end_users.email_placeholder')}
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                required
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="password">{t('end_users.new_password')}</Label>
                            <Input
                                id="password"
                                type="password"
                                placeholder={t('end_users.password_new_placeholder')}
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                            />
                            <p className="text-xs text-muted-foreground">
                                {t('end_users.password_keep')} {t('end_users.password_invalidate')}
                            </p>
                            <InputError message={errors.password} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="custom_fields">{t('end_users.custom_fields_label')}</Label>
                            <textarea
                                id="custom_fields"
                                rows={6}
                                placeholder='{"key": "value"}'
                                value={data.custom_fields}
                                onChange={(e) => setData('custom_fields', e.target.value)}
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono"
                            />
                        </div>

                        <div className="flex items-center gap-3 pt-2">
                            <Button type="submit" disabled={processing}>
                                <Save className="mr-1 h-4 w-4" /> {t('end_users.save')}
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href={route('projects.settings.end-users.show', { project: project.id, endUserUuid: endUser.uuid })}>{t('common.cancel')}</Link>
                            </Button>
                        </div>
                    </form>
                </div>
            </ProjectSettingsLayout>
        </AppLayout>
    );
}
