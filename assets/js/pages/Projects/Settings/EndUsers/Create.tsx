import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';

import type { Project, BreadcrumbItem, UserCan } from '@/types';

import AppLayout from '@/layouts/app-layout';
import ProjectSettingsLayout from '../layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import InputError from '@/components/input-error';

interface Props {
    project: Project;
    userCan: UserCan;
}

export default function EndUsersCreate({ project }: Props) {
    const t = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: route('projects.show', project.id) },
        { title: t('projects.settings.title'), href: route('projects.settings.project', project.id) },
        { title: t('end_users.heading'), href: route('projects.settings.end-users', project.id) },
        { title: t('common.create'), href: '#' },
    ];

    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        password: '',
        status: 'active' as string,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post(route('projects.settings.end-users.store', project.id));
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${t('end_users.create')} — ${project.name}`} />
            <ProjectSettingsLayout project={project}>
                <div className="max-w-lg space-y-6">
                    <div className="flex items-center gap-2">
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={route('projects.settings.end-users', project.id)}>
                                <ArrowLeft className="mr-1 h-4 w-4" /> {t('end_users.back')}
                            </Link>
                        </Button>
                    </div>

                    <h2 className="text-lg font-semibold">{t('end_users.create')}</h2>

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
                            <Label htmlFor="password">{t('common.password')}</Label>
                            <Input
                                id="password"
                                type="password"
                                placeholder={t('end_users.password_placeholder')}
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                required
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="status">{t('end_users.col_status')}</Label>
                            <select
                                id="status"
                                value={data.status}
                                onChange={(e) => setData('status', e.target.value)}
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="active">{t('end_users.status_active')}</option>
                                <option value="pending">{t('end_users.status_pending')}</option>
                            </select>
                        </div>

                        <div className="flex items-center gap-3 pt-2">
                            <Button type="submit" disabled={processing}>
                                <Save className="mr-1 h-4 w-4" /> {t('end_users.create')}
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href={route('projects.settings.end-users', project.id)}>{t('common.cancel')}</Link>
                            </Button>
                        </div>
                    </form>
                </div>
            </ProjectSettingsLayout>
        </AppLayout>
    );
}
