import { useRef, useState, useEffect } from "react";
import { router, usePage } from "@inertiajs/react";
import axios from "axios";
import { toast } from "sonner";
import moment from "moment";
import { useTranslation } from '@/lib/i18n';

import type { Collection, Project, Field, ContentEntry, ColumnDef, SharedData, UserCan, Asset } from "@/types";

import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { HoverCard, HoverCardTrigger, HoverCardContent } from '@/components/ui/hover-card';
import {
    Plus,
    Trash,
    FileText,
    FileEdit,
    RotateCcw,
    AlignCenter,
    Link as LinkIcon,
    Copy as DuplicateIcon,
    Send,
    Copy,
    ExternalLink,
} from "lucide-react";
import { DataTable, DataTableRef } from "@/components/ui/data-table";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { ScrollArea } from "@/components/ui/scroll-area";
import RelationEntriesTable from "@/components/ui/relation-entries-table";

interface Props {
    collection: Collection;
    project: Project;
}

export default function ContentList({ collection, project }: Props) {
    const dataTableRef = useRef<DataTableRef>(null);
    const [selectedItems, setSelectedItems] = useState<ContentEntry[]>([]);
    const [showDeleteDialog, setShowDeleteDialog] = useState(false);
    const [showForceDeleteDialog, setShowForceDeleteDialog] = useState(false);
    const [showRestoreDialog, setShowRestoreDialog] = useState(false);
    const [showPublishDialog, setShowPublishDialog] = useState(false);
    const [showDraftDialog, setShowDraftDialog] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [richTextModal, setRichTextModal] = useState<{title:string, content:string}|null>(null);
    const [relationModal, setRelationModal] = useState<{title:string, entries:any[], fields:Field[]}|null>(null);
    const [mediaPreview, setMediaPreview] = useState<Asset | null>(null);
    const [mediaLoading, setMediaLoading] = useState(false);

    const openMediaPreview = async (assetOrUuid: Asset | string) => {
        if (typeof assetOrUuid === 'string') {
            setMediaLoading(true);
            try {
                const res = await axios.get(`/api/projects/${project.uuid}/media/${assetOrUuid}`);
                setMediaPreview(res.data?.data ?? res.data);
            } catch {
                toast.error('Could not load file details');
            } finally {
                setMediaLoading(false);
            }
        } else {
            setMediaPreview(assetOrUuid);
        }
    };
    const [hasEntry, setHasEntry] = useState(false);
    
    const can = usePage().props.userCan as UserCan;
    const t = useTranslation();

    // Fetch entry count when component mounts for singleton collections
    useEffect(() => {
        if (collection.is_singleton) {
            axios.get(`/api/projects/${project.uuid}/collections/${collection.slug}/entries`, {
                params: { per_page: 1 }
            })
            .then(res => {
                if (res?.data?.total !== undefined) {
                    setHasEntry(res.data.total > 0);
                }
            })
            .catch(() => {
                // ignore errors – leave hasEntry as false
            });
        }
    }, [collection.is_singleton, collection.id, project.id]);
    
    const handleEdit = (item: ContentEntry) => {
        // if the item is trashed don't allow editing
        if (item.deleted_at !== null) return;

        if (!can.update_content) return;
        router.visit(route('projects.collections.content.edit', {
            project: project.id,
            collection: collection.id,
            contentEntry: item.id
        }));
    };
    
    const contentBase = `/api/projects/${project.uuid}/collections/${collection.slug}/entries`;

    const handleDelete = async () => {
        setProcessing(true);

        try {
            const requests = selectedItems.map(item =>
                axios.delete(`${contentBase}/${item.uuid}`)
            );

            await Promise.all(requests);

            toast.success(`${selectedItems.length} content entries moved to trash`);
            setSelectedItems([]);
            setShowDeleteDialog(false);
            dataTableRef.current?.fetchData();
        } catch (error) {
            toast.error(t('content.failed_trash'));
        } finally {
            setProcessing(false);
        }
    };

    const handleForceDelete = async () => {
        setProcessing(true);

        try {
            const requests = selectedItems.map(item =>
                axios.delete(`${contentBase}/${item.uuid}/force-delete`)
            );

            await Promise.all(requests);

            toast.success(`${selectedItems.length} content entries permanently deleted`);
            setSelectedItems([]);
            setShowForceDeleteDialog(false);
            dataTableRef.current?.fetchData();
        } catch (error) {
            toast.error(t('content.failed_delete'));
        } finally {
            setProcessing(false);
        }
    };

    const restoreSelected = async () => {
        setProcessing(true);
        try {
            const requests = selectedItems.map(item =>
                axios.patch(`${contentBase}/${item.uuid}/restore`, {})
            );
            await Promise.all(requests);
            toast.success(`${selectedItems.length} content entries restored`);
            setSelectedItems([]);
            dataTableRef.current?.fetchData();
        } catch {
            toast.error(t('content.failed_restore'));
        }
        setProcessing(false);
    };

    const handlePublish = async () => {
        setProcessing(true);
        try {
            const requests = selectedItems.map(item =>
                axios.patch(`${contentBase}/${item.uuid}`, { status: 'published' })
            );
            await Promise.all(requests);
            toast.success(t('content.published_success', { count: String(selectedItems.length) }));
            setSelectedItems([]);
            setShowPublishDialog(false);
            dataTableRef.current?.fetchData();
        } catch {
            toast.error(t('content.failed_publish'));
        } finally {
            setProcessing(false);
        }
    };

    const handleDraft = async () => {
        setProcessing(true);
        try {
            const requests = selectedItems.map(item =>
                axios.patch(`${contentBase}/${item.uuid}`, { status: 'draft' })
            );
            await Promise.all(requests);
            toast.success(t('content.draft_success', { count: String(selectedItems.length) }));
            setSelectedItems([]);
            setShowDraftDialog(false);
            dataTableRef.current?.fetchData();
        } catch {
            toast.error(t('content.failed_draft'));
        } finally {
            setProcessing(false);
        }
    };

    const anyTrashedSelected = selectedItems.some(item => (item as any).deleted_at !== null);

    // Generate dynamic columns based on collection fields
    const generateColumns = (): ColumnDef[] => {
        const columns: ColumnDef[] = [
            {
                header: t('content.status'),
                accessorKey: "status",
                sortable: true,
                align: "center",
                width: "w-24",
                padding: "px-10",
                filter: {
                    type: 'select',
                    options: [
                        { label: t('content.draft'), value: 'draft' },
                        { label: t('content.published'), value: 'published' },
                        { label: t('content.scheduled'), value: 'scheduled' },
                    ]
                },
                cell: (item: ContentEntry) => (
                    <Badge variant={item.status === 'published' ? 'default' : item.status === 'scheduled' ? 'secondary' : item.status === 'trashed' ? 'destructive' : 'outline'} className={
                        item.status === 'published'
                            ? 'bg-green-600 hover:bg-green-700'
                            : item.status === 'scheduled'
                                ? 'bg-blue-500 hover:bg-blue-600 text-white'
                                : item.status === 'trashed' ? 'bg-red-600 hover:bg-red-700' : 'text-amber-600 border-amber-300'
                    }>
                        {item.status === 'published' ? t('content.published') : item.status === 'scheduled' ? t('content.scheduled') : item.status === 'trashed' ? t('content.trashed') : t('content.draft')}
                    </Badge>
                ),
            },
        ];
        
        // Add field columns from loaded fields
        if (collection.fields && collection.fields.length > 0) {
            // Slugs réservés par les colonnes système — ne pas dupliquer
            const RESERVED_SLUGS = new Set(['status', 'created_at', 'updated_at', 'deleted_at', 'published_at', 'scheduled_at', 'uuid', 'id']);

            const displayableFields = collection.fields.filter((field: Field) =>
                field.type !== 'password' &&
                field.type !== 'json' &&
                !field.options?.hideInContentList &&
                !RESERVED_SLUGS.has(field.slug)
            );
            
            // Add columns for each field
            displayableFields.forEach((field: Field) => {
                columns.push({
                    header: field.label,
                    accessorKey: field.slug,
                    sortable: true,
                    cell: (item: ContentEntry) => {
                        const value = item[field.slug];
                        
                        if (value === null || value === undefined || value === '') {
                            return '-';
                        }
                        
                        // If the field is repeatable and the value is an array, show first item + counter
                        if (field.options?.repeatable) {
                            if (value.length === 0) return '-';
                            const first = value[0];
                            if (typeof first === 'object') {
                                // complex objects handled later
                            } else {
                                const label = `${first}${value.length > 1 ? ` (+${value.length - 1} more)` : ''}`;
                                if (value.length === 1) {
                                    return label;
                                }
                                return (
                                    <HoverCard openDelay={100}>
                                        <HoverCardTrigger asChild>
                                            <span className="underline decoration-dotted cursor-help">{label}</span>
                                        </HoverCardTrigger>
                                        <HoverCardContent align="start" className="w-48">
                                            <ul className="list-disc pl-4 space-y-1 text-sm">
                                                {value.map((v: any, idx: number) => (
                                                    <li key={idx}>{String(v)}</li>
                                                ))}
                                            </ul>
                                        </HoverCardContent>
                                    </HoverCard>
                                );
                            }
                        }

                        switch (field.type) {
                            case 'text':
                                // Truncate long text
                                return typeof value === 'string' && value.length > 30
                                    ? `${value.substring(0, 30)}...`
                                    : value;
                            case 'email':
                            case 'slug':
                                return value;
                            case 'richtext':
                                if (value && typeof value === 'string' && value.trim() !== '') {
                                    return (
                                        <div>
                                            <button
                                                type="button"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    setRichTextModal({ title: field.label, content: value });
                                                }}
                                                className="p-1 hover:bg-muted rounded"
                                            >
                                                <AlignCenter className="w-4 h-4 text-indigo-500" />
                                            </button>
                                        </div>
                                    );
                                }
                                return <div className="text-center">-</div>;
                            case 'longtext':
                                // Truncate long text
                                return typeof value === 'string' && value.length > 30
                                    ? `${value.substring(0, 30)}...`
                                    : value;
                            case 'date':
                                if (!value) return '-';
                                let format = 'YYYY-MM-DD' + (field.options?.includeTime ? ' HH:mm' : '');
                                if (field.options?.mode === 'range') {
                                    return value.split(' - ').map((date: any) => moment.parseZone(date).format(format)).join(' / ');
                                }
                                return moment.parseZone(value).format(format);
                            case 'boolean':
                                return value ? t('content.yes') : t('content.no');
                            case 'enumeration':
                                if (Array.isArray(value)) {
                                    return value.join(', ');
                                }
                                if (typeof value === 'string') {
                                    try {
                                        const parsedValue = JSON.parse(value);
                                        if (Array.isArray(parsedValue)) {
                                            return parsedValue.join(', ');
                                        }
                                    } catch (e) {
                                        // Not valid JSON, return as is
                                    }
                                }
                                return value;
                            case 'number':
                                return value === null ? '-' : Number(value).toString();
                            case 'media':
                                if (!value) return '-';
                                if (Array.isArray(value) && value.length > 0) {
                                    return (
                                        <div className="flex flex-wrap gap-1" data-no-row-click>
                                            {value.map((asset, index) => {
                                                const isObj = typeof asset === 'object' && asset !== null;
                                                const thumbUrl = isObj ? asset.thumbnail_url : null;
                                                const title = isObj ? (asset.original_filename ?? asset.filename ?? '') : String(asset).slice(0, 8) + '…';
                                                return (
                                                    <button
                                                        key={index}
                                                        type="button"
                                                        onClick={() => openMediaPreview(asset)}
                                                        title={title}
                                                        className="w-8 h-8 rounded overflow-hidden cursor-pointer hover:opacity-75 hover:ring-2 hover:ring-primary transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                    >
                                                        {thumbUrl ? (
                                                            <img src={thumbUrl} alt="" draggable={false} className="w-full h-full object-cover pointer-events-none" />
                                                        ) : (
                                                            <div className="w-full h-full flex items-center justify-center bg-muted pointer-events-none">
                                                                <FileText className="w-4 h-4 text-muted-foreground" />
                                                            </div>
                                                        )}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    );
                                }
                                return '-';
                            case 'relation':
                                const ids = Array.isArray(value) ? value : typeof value === 'string' && value.trim() !== '' ? (():number[]=>{try{const parsed=JSON.parse(value);return Array.isArray(parsed)?parsed:[]}catch{return value.split(',').map(Number) }})() : [];
                                if (ids.length>0) {
                                    return (
                                        <div className="flex">
                                            <button
                                                type="button"
                                                onClick={async (e)=>{
                                                    e.stopPropagation();
                                                    try {
                                                        const targetCollectionId = (field as any).options?.relation?.collection ?? collection.id;
                                                        const targetColl = (project.collections ?? []).find((c: any) => c.id === targetCollectionId) ?? collection;
                                                        const apiBase = `/api/projects/${project.uuid}/collections/${targetColl.slug}/entries`;
                                                        const respEntries = await axios.get(apiBase, { params: { ids: ids.join(',') } });
                                                        const collResp = await axios.get(`/api/projects/${project.uuid}/collections/${targetColl.slug}`);
                                                        setRelationModal({ title: field.label, entries: respEntries.data?.data ?? respEntries.data, fields: collResp.data?.data?.fields ?? collResp.data.fields ?? [] });
                                                    } catch(err){
                                                        toast.error(t('content.failed_load_related'));
                                                    }
                                                }}
                                                className="p-1 hover:bg-muted rounded"
                                            >
                                                <LinkIcon className="w-4 h-4 text-indigo-500" />
                                            </button>
                                        </div>
                                    );
                                }
                                return <div className="text-center">-</div>;
                            default:
                                return value;
                        }
                    }
                });
            });
        }

        columns.push(
            {
                header: t('content.col_created'),
                accessorKey: "created_at",
                sortable: true,
                filter: {
                    type: 'date',
                },
                cell: (item: ContentEntry) => (
                    <div className="flex flex-col">
                        <span>{new Date(item.created_at).toLocaleString()}</span>
                        <span className="text-xs text-muted-foreground">by {item.creator?.name || 'Unknown'}</span>
                    </div>
                ),
            },
            {
                header: t('content.col_updated'),
                accessorKey: "updated_at",
                sortable: true,
                filter: {
                    type: 'date',
                },
                cell: (item: ContentEntry) => (
                    <div className="flex flex-col">
                        <span>{new Date(item.updated_at).toLocaleString()}</span>
                        <span className="text-xs text-muted-foreground">by {item.updater?.name || 'Unknown'}</span>
                    </div>
                ),
            }
        );
        
        return columns;
    };

    return (
        <div>
            <div className="mb-3 flex items-center justify-between gap-2">
                <h1 className="text-xl font-bold">{collection.name}
                    <span className="text-sm font-normal text-muted-foreground ml-2">
                        #
                        <span className="select-all">{collection.slug}</span>
                    </span>
                </h1>
                {can.move_content_to_trash && (
                    <button
                        type="button"
                        onClick={() => router.visit(`/projects/${project.id}/collections/${collection.id}/content/trash`)}
                        className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground transition-colors"
                    >
                        <Trash className="h-3.5 w-3.5" />
                        {t('content.trash_link')}
                    </button>
                )}
            </div>
            
            <DataTable
                key={collection.id}
                ref={dataTableRef}
                pageName={`content_${collection.project_id}_${collection.id}`}
                searchRoute={`/api/projects/${project.uuid}/collections/${collection.slug}/entries`}
                searchPlaceholder={`Search ${collection.name}...`}
                columns={generateColumns()}
                actions={[
                    {
                        label: t('content.create_new'),
                        onClick: () => router.visit(route('projects.collections.content.create', { project: project.id, collection: collection.id })),
                        icon: <Plus className="h-4 w-4 mr-2" />,
                        show: can.create_content && !(collection.is_singleton && hasEntry),
                    },
                    !anyTrashedSelected ? {
                        label: t('content.publish_action'),
                        onClick: () => setShowPublishDialog(true),
                        icon: <Send className="h-4 w-4 mr-1" />,
                        variant: "default",
                        size: "sm",
                        show: selectedItems.length > 0 && can.update_content,
                    } : null,
                    !anyTrashedSelected ? {
                        label: t('content.draft_action'),
                        onClick: () => setShowDraftDialog(true),
                        icon: <FileEdit className="h-4 w-4 mr-1" />,
                        variant: "outline",
                        size: "sm",
                        show: selectedItems.length > 0 && can.update_content,
                    } : null,
                    !anyTrashedSelected ? {
                        label: t('content.trash_action'),
                        onClick: () => setShowDeleteDialog(true),
                        icon: <Trash className="h-4 w-4 mr-1" />,
                        variant: "warning",
                        size: "sm",
                        show: selectedItems.length > 0 && can.move_content_to_trash,
                    } : null,
                    !anyTrashedSelected && {
                        label: t('content.delete_action'),
                        onClick: () => setShowForceDeleteDialog(true),
                        icon: <Trash className="h-4 w-4 mr-1" />,
                        variant: "destructive",
                        size: "sm",
                        show: selectedItems.length > 0 && can.delete_content,
                    },
                    // selectedItems.length === 1 ? {
                    //     label: "Duplicate",
                    //     onClick: async () => {
                    //         const entry = selectedItems[0];
                    //         try {
                    //             await axios.post(route('projects.collections.content.duplicate', {
                    //                 project: project.id,
                    //                 collection: collection.id,
                    //                 contentEntry: entry.id,
                    //             }));
                    //             toast.success('Entry duplicated');
                    //             dataTableRef.current?.fetchData();
                    //         } catch {
                    //             toast.error('Could not duplicate');
                    //         }
                    //     },
                    //     icon: <DuplicateIcon className="h-4 w-4 mr-2" />,
                    //     show: can.create_content,
                    // } : null,
                    anyTrashedSelected ? {
                        label: t('content.restore_selected'),
                        onClick: () => setShowRestoreDialog(true),
                        icon: <RotateCcw className="h-4 w-4 mr-1" />,
                        size: "sm",
                        show: selectedItems.length > 0 && can.update_content,
                        variant: 'outline',
                    } : null,
                ].filter((a): a is any => Boolean(a))}
                onRowClick={handleEdit}
                selectable={true}
                onSelectionChange={setSelectedItems}
                selectedItems={selectedItems}
            />
            
            {/* Publish Confirmation Dialog */}
            <Dialog open={showPublishDialog} onOpenChange={setShowPublishDialog}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('content.publish_title')}</DialogTitle>
                        <DialogDescription>
                            {t('content.publish_desc', { count: String(selectedItems.length) })}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setShowPublishDialog(false)}
                            disabled={processing}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button
                            onClick={handlePublish}
                            disabled={processing}
                        >
                            <Send className="mr-2 h-4 w-4" />
                            {t('content.publish_btn')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Set to Draft Confirmation Dialog */}
            <Dialog open={showDraftDialog} onOpenChange={setShowDraftDialog}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('content.draft_title')}</DialogTitle>
                        <DialogDescription>
                            {t('content.draft_desc', { count: String(selectedItems.length) })}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setShowDraftDialog(false)}
                            disabled={processing}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button
                            variant="outline"
                            onClick={handleDraft}
                            disabled={processing}
                        >
                            <FileEdit className="mr-2 h-4 w-4" />
                            {t('content.draft_btn')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Move to Trash Confirmation Dialog */}
            <Dialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('content.move_to_trash_title')}</DialogTitle>
                        <DialogDescription>
                            {t('content.move_to_trash_desc', { count: String(selectedItems.length) })}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setShowDeleteDialog(false)}
                            disabled={processing}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button
                            variant="warning"
                            onClick={handleDelete}
                            disabled={processing}
                        >
                            <Trash className="mr-2 h-4 w-4" />
                            {t('content.move_to_trash_btn')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            
            {/* Force Delete Confirmation Dialog */}
            <Dialog open={showForceDeleteDialog} onOpenChange={setShowForceDeleteDialog}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('content.delete_perm_title')}</DialogTitle>
                        <DialogDescription>
                            {t('content.delete_perm_desc', { count: String(selectedItems.length) })}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setShowForceDeleteDialog(false)}
                            disabled={processing}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleForceDelete}
                            disabled={processing}
                        >
                            <Trash className="mr-2 h-4 w-4" />
                            {t('content.delete_perm_btn')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Restore Confirmation Dialog */}
            <Dialog open={showRestoreDialog} onOpenChange={setShowRestoreDialog}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('content.restore_title')}</DialogTitle>
                        <DialogDescription>
                            {t('content.restore_desc', { count: String(selectedItems.length) })}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowRestoreDialog(false)} disabled={processing}>{t('common.cancel')}</Button>
                        <Button onClick={async ()=>{ await restoreSelected(); setShowRestoreDialog(false);} } disabled={processing}>
                            <RotateCcw className="mr-2 h-4 w-4" /> {t('content.restore_btn')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Rich Text preview dialog */}
            <Dialog open={!!richTextModal} onOpenChange={(open)=>!open && setRichTextModal(null)}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{richTextModal?.title}</DialogTitle>
                        {richTextModal?.title && <DialogDescription>{t('content.richtext_preview')}</DialogDescription>}
                    </DialogHeader>
                    {richTextModal && (
                        <ScrollArea className="max-h-[60vh] pr-4">
                            <div className="prose" dangerouslySetInnerHTML={{__html: richTextModal.content}} />
                        </ScrollArea>
                    )}
                    <DialogFooter>
                        <Button variant="outline" onClick={()=>setRichTextModal(null)}>{t('content.close')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Media Preview Dialog */}
            <Dialog open={!!mediaPreview || mediaLoading} onOpenChange={(open) => { if (!open) { setMediaPreview(null); setMediaLoading(false); } }}>
                <DialogContent className="sm:max-w-md w-full p-0 overflow-hidden">
                    {/* Header */}
                    <div className="px-5 pt-5 pb-4 border-b border-border">
                        <DialogTitle className="text-sm font-semibold truncate pr-6 leading-tight">
                            {mediaPreview?.original_filename ?? mediaPreview?.filename ?? t('content.media_preview')}
                        </DialogTitle>
                        {mediaPreview?.mime_type && (
                            <DialogDescription className="text-xs mt-0.5 truncate">
                                {mediaPreview.mime_type}
                                {mediaPreview.formatted_size && <span className="ml-2 opacity-60">· {mediaPreview.formatted_size}</span>}
                            </DialogDescription>
                        )}
                    </div>

                    {/* Body */}
                    <div className="px-5 py-4 space-y-4 overflow-y-auto max-h-[60vh]">

                        {/* Spinner chargement */}
                        {mediaLoading && (
                            <div className="flex items-center justify-center py-10 text-muted-foreground">
                                <svg className="animate-spin w-5 h-5" viewBox="0 0 24 24" fill="none">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 100 16v-4l-3 3 3 3v-4a8 8 0 01-8-8z"/>
                                </svg>
                            </div>
                        )}

                        {mediaPreview && !mediaLoading && (<>
                            {/* Aperçu image */}
                            {(mediaPreview.mime_type?.startsWith('image/') || mediaPreview.thumbnail_url) && (
                                <div className="w-full rounded-md overflow-hidden bg-muted flex items-center justify-center" style={{ height: '200px' }}>
                                    <img
                                        src={
                                            mediaPreview.mime_type?.startsWith('image/')
                                                ? (mediaPreview.full_url ?? mediaPreview.url ?? mediaPreview.thumbnail_url ?? '')
                                                : (mediaPreview.thumbnail_url ?? '')
                                        }
                                        alt={mediaPreview.metadata?.alt_text ?? ''}
                                        className="w-full h-full object-contain"
                                    />
                                </div>
                            )}

                            {/* Icône fichier non-image */}
                            {!mediaPreview.mime_type?.startsWith('image/') && !mediaPreview.thumbnail_url && (
                                <div className="w-full rounded-md bg-muted flex flex-col items-center justify-center gap-2 py-8">
                                    <FileText className="w-10 h-10 text-muted-foreground" />
                                    <span className="text-xs font-mono uppercase text-muted-foreground tracking-widest">
                                        {mediaPreview.extension ?? (mediaPreview.mime_type?.split('/')[1]) ?? '—'}
                                    </span>
                                </div>
                            )}

                            {/* Métadonnées */}
                            {(mediaPreview.metadata?.alt_text || mediaPreview.metadata?.caption) && (
                                <div className="grid grid-cols-[80px_1fr] gap-x-3 gap-y-1 text-xs">
                                    {mediaPreview.metadata?.alt_text && (<>
                                        <span className="text-muted-foreground pt-px">Alt</span>
                                        <span className="break-words min-w-0">{mediaPreview.metadata.alt_text}</span>
                                    </>)}
                                    {mediaPreview.metadata?.caption && (<>
                                        <span className="text-muted-foreground pt-px">Caption</span>
                                        <span className="break-words min-w-0">{mediaPreview.metadata.caption}</span>
                                    </>)}
                                </div>
                            )}

                            {/* URL + copier */}
                            {(mediaPreview.full_url ?? mediaPreview.url) && (
                                <div className="flex items-center gap-2 min-w-0">
                                    <div className="flex-1 min-w-0 bg-muted rounded-md px-2.5 py-1.5 overflow-hidden">
                                        <p className="text-xs font-mono text-muted-foreground truncate">
                                            {mediaPreview.full_url ?? mediaPreview.url}
                                        </p>
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        className="shrink-0 h-8 w-8"
                                        onClick={() => {
                                            navigator.clipboard.writeText(mediaPreview.full_url ?? mediaPreview.url ?? '');
                                            toast.success(t('content.url_copied'));
                                        }}
                                    >
                                        <Copy className="w-3.5 h-3.5" />
                                    </Button>
                                </div>
                            )}
                        </>)}
                    </div>

                    {/* Footer */}
                    {!mediaLoading && (
                        <div className="flex items-center justify-end gap-2 px-5 py-3 border-t border-border bg-muted/30">
                            {(mediaPreview?.full_url ?? mediaPreview?.url) && (
                                <Button variant="outline" size="sm" asChild>
                                    <a href={mediaPreview?.full_url ?? mediaPreview?.url ?? ''} target="_blank" rel="noopener noreferrer">
                                        <ExternalLink className="w-3.5 h-3.5 mr-1.5" />
                                        {t('content.open_tab')}
                                    </a>
                                </Button>
                            )}
                            <Button variant="outline" size="sm" onClick={() => setMediaPreview(null)}>
                                {t('content.close')}
                            </Button>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            {/* Relation entries dialog */}
            <Dialog open={!!relationModal} onOpenChange={(open)=>!open && setRelationModal(null)}>
                <DialogContent className="sm:max-w-4xl">
                    <DialogHeader>
                        <DialogTitle>{relationModal?.title}</DialogTitle>
                        {relationModal?.title && <DialogDescription>{t('content.related_entries')}</DialogDescription>}
                    </DialogHeader>
                    {relationModal && (
                            <div className="overflow-x-auto w-full">
                            <RelationEntriesTable
                                fields={relationModal.fields.filter((f)=>!['password','json'].includes(f.type) && !f.options?.hideInContentList)}
                                entries={relationModal.entries}
                                showStatus
                                showCreated
                            />
                            </div>
                    )}
                    <DialogFooter>
                        <Button variant="outline" onClick={()=>setRelationModal(null)}>{t('content.close')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}