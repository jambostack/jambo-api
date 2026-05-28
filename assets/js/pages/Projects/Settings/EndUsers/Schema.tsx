import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { UserCog } from 'lucide-react';

import type { Project, BreadcrumbItem, UserCan, Field } from '@/types';

import AppLayout from '@/layouts/app-layout';
import ProjectsLayout from '@/pages/Projects/layout';
import ProjectSidebar from '@/pages/Projects/ProjectSidebar';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';

import FieldList from '@/pages/Collections/Fields/FieldList';
import AddFieldModal from '@/pages/Collections/Fields/AddFieldModal';

interface Props {
    project: Project;
    userCan: UserCan;
    endUserFields: Field[];
}

export default function EndUsersSchema({ project, userCan, endUserFields }: Props) {
    const t = useTranslation();
    const [isAddModalOpen, setIsAddModalOpen] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: route('projects.show', project.id) },
        { title: t('end_users.heading'), href: route('projects.settings.end-users', project.id) },
        { title: t('end_users.schema'), href: '#' },
    ];

    const apiBasePath = `/api/projects/${project.uuid}/end-users/fields`;

    const can = {
        create_field: userCan.access_end_users_settings,
        update_field: userCan.access_end_users_settings,
        delete_field: userCan.access_end_users_settings,
    };

    function handleFieldSaved() {
        setIsAddModalOpen(false);
        router.reload({ only: ['endUserFields'] });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${t('end_users.schema')} — ${project.name}`} />
            <ProjectsLayout>
                <ProjectSidebar project={project} />
                <div className="flex-1 min-w-0 space-y-6">
                    <div className="flex items-center justify-between">
                        <div>
                            <div className="flex items-center gap-2">
                                <UserCog className="h-5 w-5 text-muted-foreground" />
                                <h2 className="text-lg font-semibold">{t('end_users.schema')}</h2>
                            </div>
                            <p className="text-sm text-muted-foreground mt-1">{t('end_users.schema_desc')}</p>
                        </div>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => router.visit(route('projects.settings.end-users', project.id))}
                        >
                            {t('end_users.view_users')}
                        </Button>
                    </div>

                    <FieldList
                        projectId={project.id}
                        projectUuid={project.uuid}
                        collectionId={0}
                        collectionSlug=""
                        apiBasePath={apiBasePath}
                        reloadProp="endUserFields"
                        initialFields={endUserFields}
                        onAddFieldClick={() => setIsAddModalOpen(true)}
                        collections={(project.collections ?? []).map((c) => ({ id: c.id, name: c.name }))}
                        can={can}
                    />

                    <AddFieldModal
                        isOpen={isAddModalOpen}
                        onClose={() => setIsAddModalOpen(false)}
                        collectionId={0}
                        projectId={project.id}
                        projectUuid={project.uuid}
                        collectionSlug=""
                        apiBasePath={apiBasePath}
                        onFieldCreated={handleFieldSaved}
                        collections={(project.collections ?? []).map((c) => ({ id: c.id, name: c.name }))}
                        collectionFields={endUserFields}
                        can={can}
                    />
                </div>
            </ProjectsLayout>
        </AppLayout>
    );
}
