import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';
import React from 'react';

import type { Project, BreadcrumbItem, UserCan, Field } from '@/types';

import AppLayout from '@/layouts/app-layout';
import ProjectsLayout from '@/pages/Projects/layout';
import ProjectSidebar from '@/pages/Projects/ProjectSidebar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import InputError from '@/components/input-error';
import { renderField } from '@/pages/Content/Fields';

interface Props {
    project: Project;
    userCan: UserCan;
    endUserFields: Field[];
}

function buildInitialCustomFields(fields: Field[]): Record<string, any> {
    const data: Record<string, any> = {};
    fields.forEach(field => {
        if (field.options?.repeatable) {
            data[field.slug] = [{ value: null }];
        } else if (field.type === 'enumeration' && field.options?.multiple) {
            data[field.slug] = [];
        } else if (field.type === 'boolean') {
            data[field.slug] = false;
        } else if (field.type === 'media' || field.type === 'relation') {
            data[field.slug] = [];
        } else if (field.type === 'number' || field.type === 'json') {
            data[field.slug] = null;
        } else {
            data[field.slug] = '';
        }
    });
    return data;
}

export default function EndUsersCreate({ project, endUserFields }: Props) {
    const t = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: route('projects.show', project.id) },
        { title: t('end_users.heading'), href: route('projects.settings.end-users', project.id) },
        { title: t('common.create'), href: '#' },
    ];

    // Seuls les champs non-système sont modifiables (is_system est la source canonique)
    const customFields = endUserFields.filter(f => !f.is_system);

    const { data, setData, post, processing, errors } = useForm<{
        name: string;
        email: string;
        password: string;
        status: string;
        custom_fields: Record<string, any>;
    }>({
        name: '',
        email: '',
        password: '',
        status: 'active',
        custom_fields: buildInitialCustomFields(customFields),
    });

    function handleFieldChange(field: Field, value: any, index?: number) {
        const newData = { ...data.custom_fields };

        if (field.options?.repeatable) {
            if (typeof index === 'number') {
                if (!Array.isArray(newData[field.slug])) {
                    newData[field.slug] = [{ value: null }];
                }
                newData[field.slug][index].value = value;
            } else {
                newData[field.slug] = value;
            }
        } else if (field.type === 'media') {
            newData[field.slug] = Array.isArray(value) ? value : (value ? [value] : []);
        } else {
            newData[field.slug] = value;
        }

        setData('custom_fields', newData);
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post(route('projects.settings.end-users.store', project.id));
    }

    const mappedErrors: Record<string, string> = {};
    Object.entries(errors).forEach(([k, v]) => {
        if (typeof v === 'string') mappedErrors[`fields.${k}`] = v;
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${t('end_users.create')} — ${project.name}`} />
            <ProjectsLayout>
                <ProjectSidebar project={project} />
                <div className="flex-1 min-w-0">
                    <div className="max-w-2xl space-y-6">
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
                                    onChange={e => setData('name', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="email">{t('end_users.col_email')}</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    placeholder={t('end_users.email_placeholder')}
                                    value={data.email}
                                    onChange={e => setData('email', e.target.value)}
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
                                    onChange={e => setData('password', e.target.value)}
                                    required
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="status">{t('end_users.col_status')}</Label>
                                <select
                                    id="status"
                                    value={data.status}
                                    onChange={e => setData('status', e.target.value)}
                                    className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="active">{t('end_users.status_active')}</option>
                                    <option value="pending">{t('end_users.status_pending')}</option>
                                </select>
                            </div>

                            {customFields.length > 0 && (
                                <div className="space-y-4 border-t pt-4">
                                    <h3 className="text-sm font-medium text-muted-foreground uppercase tracking-wider">
                                        {t('end_users.custom_fields')}
                                    </h3>
                                    {customFields.map(field => (
                                        <div key={field.id} className="border border-dashed border-gray-200 dark:border-gray-800 rounded-md p-4">
                                            <React.Fragment>
                                                {renderField({
                                                    field,
                                                    value: data.custom_fields[field.slug],
                                                    onChange: handleFieldChange,
                                                    processing,
                                                    errors: mappedErrors,
                                                })}
                                            </React.Fragment>
                                        </div>
                                    ))}
                                </div>
                            )}

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
                </div>
            </ProjectsLayout>
        </AppLayout>
    );
}
