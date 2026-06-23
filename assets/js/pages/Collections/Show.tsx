import { Head, usePage } from '@inertiajs/react';
import React, { useState } from 'react';

import { Collection, Project, BreadcrumbItem, SharedData, Field, UserCan } from '@/types/index.d';
import { useTranslation } from '@/lib/i18n';

import AppLayout from '@/layouts/app-layout';
import { Separator } from '@/components/ui/separator';

import ProjectSidebar from '@/pages/Projects/ProjectSidebar';
import ContentList from '@/pages/Content/ContentList';
import ContentForm from '@/pages/Content/ContentForm';
import LivePreviewPanel from '@/components/LivePreviewPanel';
import ShareDialog from '@/pages/Content/ShareDialog';
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
    const can = page.props.userCan as UserCan;
    const isContentCreatePage = page.url.includes('content/create');
    const isContentEditPage = page.url.includes('content') && page.url.includes('edit');
    const showContentForm = isContentCreatePage || isContentEditPage || isEditMode;
    const [liveFormData, setLiveFormData] = useState<Record<string, any> | null>(null);
    const [highlightedField, setHighlightedField] = useState<string | null>(null);
    const [patchField, setPatchField] = useState<{ fieldSlug: string; value: string } | null>(null);

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
                            formData={formData}
                            isEditMode={isEditMode}
                            onFieldChange={setLiveFormData}
                            highlightedField={highlightedField}
                            patchField={patchField}
                        />
                    )}

                    {/* Share button */}
                    {isEditMode && contentEntry?.uuid && can.update_content && (
                        <ShareDialog projectUuid={project.uuid!} entryUuid={contentEntry.uuid} />
                    )}

                    {/* Live Preview Panel */}
                    {showContentForm && project.previewEnabled && project.previewUrl && contentEntry && (
                        <LivePreviewPanel
                            projectUuid={project.uuid!}
                            collection={collection}
                            entryUuid={contentEntry.uuid}
                            locale={contentEntry.locale || project.default_locale || 'en'}
                            formData={liveFormData || formData || {}}
                            previewUrl={project.previewUrl}
                            onInlineUpdate={(fieldSlug, value) => setPatchField({ fieldSlug, value })}
                            onFieldHover={(slug) => setHighlightedField(slug || null)}
                            onFieldSelect={(slug) => {
                                setHighlightedField(slug);
                                setTimeout(() => setHighlightedField(null), 3000);
                            }}
                        />
                    )}
                </div>
            </ProjectsLayout>
        </AppLayout>
    );
} 