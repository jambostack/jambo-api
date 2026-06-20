import { Head, usePage } from '@inertiajs/react';
import React, { useState } from 'react';

import { Collection, Project, BreadcrumbItem, SharedData, Field } from '@/types/index.d';
import { useTranslation } from '@/lib/i18n';

import AppLayout from '@/layouts/app-layout';
import { Separator } from '@/components/ui/separator';

import ProjectSidebar from '@/pages/Projects/ProjectSidebar';
import ContentList from '@/pages/Content/ContentList';
import ContentForm from '@/pages/Content/ContentForm';
import LivePreviewPanel from '@/components/LivePreviewPanel';
import ProjectsLayout from '../Projects/layout';

interface Props {
    project: Project;
    collection: Collection & {
        fields: Field[];
    };
    contentEntry?: any;
    formData?: Record<string, any>;
    isEditMode?: boolean;
}

export default function Show({ project, collection, contentEntry, formData, isEditMode }: Props) {
    const t = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: project.name,
            href: route('projects.show', { project: project.id }),
        },
        {
            title: collection.name,
            href: route('projects.collections.show', { project: project.id, collection: collection.id }),
        },
    ];

    const page = usePage<SharedData>();
    const isContentCreatePage = page.url.includes('content/create');
    const isContentEditPage = page.url.includes('content') && page.url.includes('edit');
    const showContentForm = isContentCreatePage || isContentEditPage || isEditMode;
    const [liveFormData, setLiveFormData] = useState<Record<string, any>>(formData || {});

    // Add appropriate breadcrumb
    if (isContentCreatePage) {
        breadcrumbs.push({
            title: t('collections.content_create'),
            href: route('projects.collections.content.create', { project: project.id, collection: collection.id }),
        });
    } else if (isContentEditPage || isEditMode) {
        breadcrumbs.push({
            title: t('collections.content_edit'),
            href: contentEntry ? route('projects.collections.content.edit', {
                project: project.id,
                collection: collection.id,
                contentEntry: contentEntry.id
            }) : '#',
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={contentEntry && (isContentEditPage || isEditMode) ? `${t('collections.content_edit')} ${collection.name}` : collection.name} />

            <ProjectsLayout>
                <ProjectSidebar project={project} />

                <Separator className="my-6 md:hidden" />

                <div className="flex-1 max-w-full md:w-2xl lg:w-xl xl:w-full">
                    {!showContentForm && <ContentList collection={collection} project={project} />}
                    {showContentForm && (
                        <ContentForm
                            collection={collection}
                            project={project}
                            contentEntry={contentEntry}
                            formData={liveFormData}
                            isEditMode={isEditMode}
                            onFieldChange={setLiveFormData}
                        />
                    )}

                    {/* Live Preview Panel */}
                    {showContentForm && project.previewEnabled && project.previewUrl && contentEntry && (
                        <LivePreviewPanel
                            projectUuid={project.uuid!}
                            collection={collection}
                            entryUuid={contentEntry.uuid}
                            locale={contentEntry.locale || project.default_locale || 'en'}
                            formData={liveFormData || {}}
                            previewUrl={project.previewUrl}
                        />
                    )}
                </div>
            </ProjectsLayout>
        </AppLayout>
    );
} 