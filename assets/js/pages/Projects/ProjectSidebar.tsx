import { Link, router, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import axios from 'axios';
import { useTranslation } from '@/lib/i18n';

import { Project, SharedData, Collection, UserCan } from '@/types/index.d';

import { Button } from '@/components/ui/button';
import { Plus, Settings, GripVertical, MoreVertical, Users, Lock, BarChart3 } from 'lucide-react';
import { SearchBar } from '@/components/ui/search-bar';
import { DragDropContext, Droppable, Draggable, DropResult, DroppableProvided, DraggableProvided } from '@hello-pangea/dnd';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

import CreateCollectionModal from '@/pages/Collections/CreateCollectionModal';
import DeleteCollectionModal from '@/pages/Collections/DeleteCollectionModal';

interface Props {
    project: Project;
}

export default function ProjectSidebar({ project }: Props) {
    const t = useTranslation();
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
    const [isEditModalOpen, setIsEditModalOpen] = useState(false);
    const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
    const [selectedCollection, setSelectedCollection] = useState<any>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [collections, setCollections] = useState(project.collections ?? []);
    const page = usePage<SharedData>();
    const can = page.props.userCan as UserCan;
    const { collection } = page.props as { collection?: Collection };

    // Update collections when project data changes
    useEffect(() => {
        setCollections(project.collections ?? []);
    }, [project.collections]);

    // Check if we're on any collection edit page
    const isEditPage = page.component === 'Collections/Edit';

    const isCollectionActive = (collectionId: number) => {
        if(page.component === 'Collections/Show' || page.component === 'Collections/Edit') {
            return collection?.id === collectionId;
        }

        return false;
    };

    const isEndUsersActive = page.component?.startsWith('Projects/Settings/EndUsers/');

    const filteredCollections = collections.filter(collection =>
        collection.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
        collection.slug.toLowerCase().includes(searchQuery.toLowerCase())
    );

    const handleDragEnd = async (result: DropResult) => {
        if (!result.destination) return;

        const items = Array.from(collections);
        const [reorderedItem] = items.splice(result.source.index, 1);
        items.splice(result.destination.index, 0, reorderedItem);

        // Update local state immediately for smooth UI
        setCollections(items);

        // Update the order in the backend
        try {
            await axios.post(route('projects.collections.reorder', project.uuid), {
                collections: items.map((item, index) => ({
                    id: item.id,
                    order: index,
                })),
            });
        } catch (error) {
            console.error('Failed to update collection order:', error);
            // Revert to original order if the API call fails
            setCollections(project.collections ?? []);
        }
    };

    return (
        <div>
            <aside className="w-full lg:w-64 space-y-4 lg:sticky lg:top-16 lg:max-h-[calc(100vh-5rem)] flex flex-col overflow-hidden">
                <div className="flex items-center justify-between shrink-0">
                    <h3 className="font-medium truncate min-w-0">{t('projects.sidebar.title')}</h3>
                    {can.create_collection && (
                    <Button
                        variant="default"
                        size="sm"
                        className="h-6 px-1 text-xs"
                        onClick={() => setIsCreateModalOpen(true)}
                    >
                        <Plus className="mr-1 rtl:ml-1 rtl:mr-0" />
                        {t('projects.sidebar.add')}
                    </Button>
                    )}
                </div>

                <SearchBar
                    value={searchQuery}
                    onChange={setSearchQuery}
                    placeholder={t('projects.sidebar.search')}
                    className="px-1 shrink-0"
                />

                <div className="flex-1 min-h-0 overflow-y-auto">
                <DragDropContext onDragEnd={handleDragEnd}>
                    <Droppable droppableId="collections">
                        {(provided: DroppableProvided) => (
                            <div
                                {...provided.droppableProps}
                                ref={provided.innerRef}
                                className="space-y-1"
                            >
                                {filteredCollections.map((collection, index) => (
                                    <Draggable
                                        key={collection.id}
                                        draggableId={collection.id.toString()}
                                        index={index}
                                        isDragDisabled={!isEditPage}
                                    >
                                        {(provided: DraggableProvided) => (
                                            <div
                                                ref={provided.innerRef}
                                                {...provided.draggableProps}
                                                className="flex items-center min-w-0 gap-1.5 lg:gap-2"
                                            >
                                                {isEditPage && (
                                                    <div
                                                        {...provided.dragHandleProps}
                                                        className="p-1.5 lg:p-2 text-muted-foreground hover:text-foreground cursor-grab"
                                                    >
                                                        <GripVertical className="w-4 h-4" />
                                                    </div>
                                                )}
                                                <Link
                                                    href={route('projects.collections.show', [project.id, collection.id])}
                                                    className={`flex-1 min-w-0 truncate p-2 text-sm rounded-md hover:bg-accent ${
                                                        isCollectionActive(collection.id) ? 'bg-accent text-accent-foreground' : ''
                                                    }`}
                                                >
                                                    {collection.name}
                                                </Link>
                                                {can.access_collection_settings && (
                                                    <Link
                                                        href={route('projects.collections.edit', [project.id, collection.id])}
                                                        className="block p-1.5 lg:p-2 text-sm rounded-md hover:bg-accent"
                                                    >
                                                        <Settings className="w-4 h-4" />
                                                    </Link>
                                                )}
                                                {(isEditPage && (can.update_collection || can.delete_collection)) && (
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger asChild>
                                                            <button
                                                                className="block p-1.5 lg:p-2 text-sm rounded-md hover:bg-accent"
                                                            >
                                                                <MoreVertical className="w-4 h-4" />
                                                            </button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            {/* Sur mobile/tablette, le lien Settings est dans le dropdown */}
                                                            {can.access_collection_settings && (
                                                                <DropdownMenuItem
                                                                    className="cursor-pointer lg:hidden"
                                                                    onClick={() => window.location.href = route('projects.collections.edit', [project.id, collection.id])}
                                                                >
                                                                    <Settings className="w-4 h-4 mr-2" />
                                                                    {t('collections.edit')}
                                                                </DropdownMenuItem>
                                                            )}
                                                            {can.update_collection && (
                                                                <DropdownMenuItem
                                                                    className="cursor-pointer"
                                                                    onClick={() => {
                                                                        setSelectedCollection(collection);
                                                                        setIsEditModalOpen(true);
                                                                    }}
                                                                >
                                                                    {t('collections.edit')}
                                                                </DropdownMenuItem>
                                                            )}
                                                            {can.delete_collection && (
                                                                <DropdownMenuItem
                                                                    className="text-destructive cursor-pointer"
                                                                    onClick={() => {
                                                                        setSelectedCollection(collection);
                                                                        setIsDeleteModalOpen(true);
                                                                    }}
                                                                >
                                                                    {t('collections.delete')}
                                                                </DropdownMenuItem>
                                                            )}
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                )}
                                            </div>
                                        )}
                                    </Draggable>
                                ))}
                                {provided.placeholder}
                                {filteredCollections.length === 0 && (
                                    <p className="text-sm text-muted-foreground px-2">
                                        {searchQuery ? t('projects.sidebar.no_results') : t('projects.sidebar.empty')}
                                    </p>
                                )}
                            </div>
                        )}
                    </Droppable>
                </DragDropContext>
                </div>

                <div className="pt-2 border-t shrink-0">
                    <Link
                        href={route('projects.insights', project.id)}
                        className={`flex items-center gap-2 p-2 text-sm rounded-md hover:bg-accent ${page.component === 'Projects/Insights/Index' ? 'bg-accent text-accent-foreground' : ''}`}
                    >
                        <BarChart3 className="w-4 h-4 shrink-0 text-muted-foreground" />
                        <span className="flex-1 truncate">{t('insights.nav')}</span>
                    </Link>
                </div>

                {can.access_end_users_settings && (
                    <div className="pt-2 border-t shrink-0">
                        <div className="flex items-center min-w-0 gap-1.5 lg:gap-2">
                            <Link
                                href={route('projects.settings.end-users', project.id)}
                                className={`flex-1 min-w-0 flex items-center gap-2 p-2 text-sm rounded-md hover:bg-accent ${isEndUsersActive ? 'bg-accent text-accent-foreground' : ''}`}
                            >
                                <Users className="w-4 h-4 shrink-0 text-muted-foreground" />
                                <span className="flex-1 truncate">{t('end_users.heading')}</span>
                                <Lock className="hidden sm:block w-3 h-3 text-muted-foreground/50 shrink-0" />
                            </Link>
                            <Link
                                href={route('projects.settings.end-users.schema', project.id)}
                                className={`hidden lg:block p-1.5 lg:p-2 text-sm rounded-md hover:bg-accent ${page.component === 'Projects/Settings/EndUsers/Schema' ? 'bg-accent text-accent-foreground' : ''}`}
                                title={t('end_users.schema')}
                            >
                                <Settings className="w-4 h-4" />
                            </Link>
                        </div>
                    </div>
                )}
            </aside>

            <CreateCollectionModal
                open={isCreateModalOpen}
                onOpenChange={setIsCreateModalOpen}
                projectId={project.id}
                projectUuid={project.uuid}
                onSuccess={() => router.reload()}
            />

            <CreateCollectionModal
                open={isEditModalOpen}
                onOpenChange={setIsEditModalOpen}
                projectId={project.id}
                projectUuid={project.uuid}
                collection={selectedCollection}
                onSuccess={() => router.reload()}
            />

            <DeleteCollectionModal
                open={isDeleteModalOpen}
                onOpenChange={setIsDeleteModalOpen}
                projectId={project.id}
                projectUuid={project.uuid}
                collection={selectedCollection}
                onCollectionDeleted={() => router.reload()}
            />
        </div>
    );
}
