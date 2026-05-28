import { Head } from '@inertiajs/react';
import { Suspense, lazy, useEffect, useState } from 'react';
import type { Project, BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import ProjectSettingsLayout from './layout';
import { useTranslation } from '@/lib/i18n';

const SwaggerUI = lazy(() => import('./SwaggerLoader'));

interface Props {
    project: Project;
}

type FetchState =
    | { status: 'loading' }
    | { status: 'ready'; spec: unknown }
    | { status: 'error'; message: string };

export default function ApiDocs({ project }: Props) {
    const t = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: route('projects.show', project.id) },
        { title: t('projects.settings.title'), href: route('projects.settings.project', project.id) },
        { title: t('projects.settings.nav_api_docs'), href: route('projects.settings.api-docs', project.id) },
    ];

    const [state, setState] = useState<FetchState>({ status: 'loading' });

    useEffect(() => {
        const controller = new AbortController();
        fetch(`/api/${project.uuid}/openapi.json`, {
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(async (res) => {
                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}`);
                }
                const spec = await res.json();
                setState({ status: 'ready', spec });
            })
            .catch((err) => {
                if (err.name === 'AbortError') return;
                setState({ status: 'error', message: err.message ?? t('common.error') });
            });
        return () => controller.abort();
    }, [project.uuid, t]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('projects.settings.nav_api_docs')} />

            <ProjectSettingsLayout project={project}>
                {state.status === 'loading' && (
                    <div className="text-muted-foreground text-sm py-8 text-center">{t('common.loading')}</div>
                )}
                {state.status === 'error' && (
                    <div className="text-destructive text-sm py-8 text-center">
                        {t('common.error')}: {state.message}
                    </div>
                )}
                {state.status === 'ready' && (
                    <Suspense fallback={<div className="text-muted-foreground text-sm py-8 text-center">{t('common.loading')}</div>}>
                        <div className="-mx-4">
                            <SwaggerUI spec={state.spec} tryItOutEnabled={false} />
                        </div>
                    </Suspense>
                )}
            </ProjectSettingsLayout>
        </AppLayout>
    );
}
