import { Head, usePage } from '@inertiajs/react';

import { Collection, Project, BreadcrumbItem, SharedData, Field } from '@/types/index.d';
import { useTranslation } from '@/lib/i18n';

import AppLayout from '@/layouts/app-layout';
import { Separator } from '@/components/ui/separator';

import ProjectSidebar from '@/pages/Projects/ProjectSidebar';
import ContentList from '@/pages/Content/ContentList';
import ContentForm from '@/pages/Content/ContentForm';
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
                        />
                    )}
                </div>
            </ProjectsLayout>
        </AppLayout>
    );
} 