import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';
import React from 'react';

import type { Project, BreadcrumbItem, UserCan, EndUser, Field } from '@/types';

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
    endUser: EndUser;
    endUserFields: Field[];
    userCan: UserCan;
}

function defaultForField(field: Field): any {
    if (field.options?.repeatable) return [{ value: null }];
    if (field.type === 'enumeration' && field.options?.multiple) return [];
    if (field.type === 'boolean') return false;
    if (field.type === 'media' || field.type === 'relation') return [];
    if (field.type === 'number' || field.type === 'json') return null;
    return '';
}

function buildInitialCustomFields(fields: Field[], existing: Record<string, any> | null): Record<string, any> {
    const data: Record<string, any> = {};
    fields.forEach(field => {
        const raw = existing?.[field.slug];

        if (field.type === 'media') {
            const extractUuid = (v: any) => (v && typeof v === 'object' ? v.uuid ?? null : v ?? null);
            const allowsMultiple = Boolean(field.options?.multiple) || field.options?.media?.type === 2 || Array.isArray(raw);
            if (allowsMultiple) {
                data[field.slug] = Array.isArray(raw) ? raw.map(extractUuid).filter((id: any) => id !== null) : [];
            } else {
                const id = Array.isArray(raw) ? extractUuid(raw[0]) : extractUuid(raw);
                data[field.slug] = id !== null ? [id] : [];
            }
            return;
        }

        if (raw === undefined || raw === null) {
            data[field.slug] = defaultForField(field);
            return;
        }
        data[field.slug] = raw;
    });
    return data;
}

export default function EndUsersEdit({ project, endUser, endUserFields }: Props) {
    const t = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: route('projects.show', project.id) },
        { title: t('end_users.heading'), href: route('projects.settings.end-users', project.id) },
        { title: endUser.email, href: route('projects.settings.end-users.show', { project: project.id, endUserUuid: endUser.uuid }) },
        { title: t('common.edit'), href: '#' },
    ];

    // Seuls les champs non-système sont modifiables (is_system est la source canonique)
    const customFields = endUserFields.filter(f => !f.is_system);

    const { data, setData, patch, processing, errors } = useForm<{
        name: string;
        email: string;
        password: string;
        custom_fields: Record<string, any>;
    }>({
        username: endUser.username || '',
        email: endUser.email,
        password: '',
        custom_fields: buildInitialCustomFields(customFields, endUser.custom_fields),
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
        patch(route('projects.settings.end-users.update', { project: project.id, endUserUuid: endUser.uuid }));
    }

    const mappedErrors: Record<string, string> = {};
    Object.entries(errors).forEach(([k, v]) => {
        if (typeof v === 'string') mappedErrors[`fields.${k}`] = v;
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${t('end_users.edit')} — ${endUser.email}`} />
            <ProjectsLayout>
                <ProjectSidebar project={project} />
                <div className="flex-1 min-w-0">
                    <div className="max-w-2xl space-y-6">
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
                                <Label htmlFor="username">{t('end_users.username')}</Label>
                                <Input
                                    id="username"
                                    placeholder={t('end_users.username_placeholder')}
                                    value={data.username}
                                    onChange={e => setData('username', e.target.value)}
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
                                <Label htmlFor="password">{t('end_users.new_password')}</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    placeholder={t('end_users.password_new_placeholder')}
                                    value={data.password}
                                    onChange={e => setData('password', e.target.value)}
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t('end_users.password_keep')}
                                </p>
                                <InputError message={errors.password} />
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
                                    <Save className="mr-1 h-4 w-4" /> {t('end_users.save')}
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={route('projects.settings.end-users.show', { project: project.id, endUserUuid: endUser.uuid })}>{t('common.cancel')}</Link>
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </ProjectsLayout>
        </AppLayout>
    );
}
