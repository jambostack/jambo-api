import { useRef, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import axios from "axios";
import { toast } from "sonner";
import moment from "moment";
import { useTranslation } from '@/lib/i18n';

import type { Collection, Project, Field, ContentEntry, ColumnDef, UserCan } from "@/types";

import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import {
    Trash2,
    RotateCcw,
    ArrowLeft,
    AlertTriangle,
} from "lucide-react";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { DataTable, DataTableRef } from "@/components/ui/data-table";

interface Props {
    collection: Collection & { fields: Field[] };
    project: Project;
}

type TrashedEntry = ContentEntry & { deleted_at: string };

export default function ContentTrash({ collection, project }: Props) {
    const dataTableRef = useRef<DataTableRef>(null);
    const [selectedItems, setSelectedItems] = useState<TrashedEntry[]>([]);
    const [restoreDialog, setRestoreDialog] = useState(false);
    const [forceDeleteDialog, setForceDeleteDialog] = useState(false);
    const [processing, setProcessing] = useState(false);

    const can = usePage().props.userCan as UserCan;
    const t = useTranslation();

    const baseUrl = `/api/projects/${project.uuid}/collections/${collection.slug}/entries`;

    const handleRestore = async () => {
        setProcessing(true);
        try {
            await Promise.all(
                selectedItems.map((item) =>
                    axios.patch(`${baseUrl}/${item.uuid}/restore`)
                )
            );
            toast.success(`${selectedItems.length} entr${selectedItems.length > 1 ? "ies" : "y"} restored`);
            setSelectedItems([]);
            setRestoreDialog(false);
            dataTableRef.current?.fetchData();
        } catch {
            toast.error(t('content.failed_restore'));
        } finally {
            setProcessing(false);
        }
    };

    const handleForceDelete = async () => {
        setProcessing(true);
        try {
            await Promise.all(
                selectedItems.map((item) =>
                    axios.delete(`${baseUrl}/${item.uuid}/force-delete`)
                )
            );
            toast.success(`${selectedItems.length} entr${selectedItems.length > 1 ? "ies" : "y"} permanently deleted`);
            setSelectedItems([]);
            setForceDeleteDialog(false);
            dataTableRef.current?.fetchData();
        } catch {
            toast.error(t('content.failed_delete'));
        } finally {
            setProcessing(false);
        }
    };

    const handleRestoreSingle = async (uuid: string) => {
        try {
            await axios.patch(`${baseUrl}/${uuid}/restore`);
            toast.success(t('content.restore_btn'));
            dataTableRef.current?.fetchData();
        } catch {
            toast.error(t('content.failed_restore_entry'));
        }
    };

    const handleForceDeleteSingle = async (uuid: string) => {
        try {
            await axios.delete(`${baseUrl}/${uuid}/force-delete`);
            toast.success("Entry permanently deleted");
            dataTableRef.current?.fetchData();
        } catch {
            toast.error(t('content.failed_delete_entry'));
        }
    };

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
                        { label: t('content.trashed'), value: 'trashed' },
                    ]
                },
                cell: (item: ContentEntry) => (
                    <Badge variant={item.status === 'scheduled' ? 'secondary' : 'destructive'} className={
                        item.status === 'scheduled'
                            ? 'bg-blue-500 hover:bg-blue-600 text-white'
                            : 'bg-red-600 hover:bg-red-700'
                    }>
                        {item.status === 'scheduled' ? t('content.scheduled') : t('content.trashed')}
                    </Badge>
                ),
            },
        ];

        if (collection.fields && collection.fields.length > 0) {
            const displayableFields = collection.fields.filter((field: Field) =>
                field.type !== 'password' &&
                field.type !== 'json' &&
                !field.options?.hideInContentList
            );

            displayableFields.forEach((field: Field) => {
                columns.push({
                    header: field.label,
                    accessorKey: field.slug,
                    sortable: true,
                    cell: (item: ContentEntry) => {
                        const value = item[field.slug];

                        if (value === null || value === undefined || value === '') {
                            return <span className="text-muted-foreground">—</span>;
                        }

                        switch (field.type) {
                            case 'text':
                            case 'longtext':
                                return typeof value === 'string' && value.length > 30
                                    ? `${value.substring(0, 30)}...`
                                    : value;
                            case 'email':
                            case 'slug':
                                return value;
                            case 'richtext':
                                if (typeof value === 'string') {
                                    const plain = value.replace(/<[^>]+>/g, "");
                                    return plain.length > 40 ? plain.slice(0, 40) + "…" : plain;
                                }
                                return String(value);
                            case 'date':
                                if (!value) return <span className="text-muted-foreground">—</span>;
                                {
                                    let format = 'YYYY-MM-DD' + (field.options?.includeTime ? ' HH:mm' : '');
                                    if (field.options?.mode === 'range') {
                                        return value.split(' - ').map((date: any) => moment.parseZone(date).format(format)).join(' / ');
                                    }
                                    return moment.parseZone(value).format(format);
                                }
                            case 'boolean':
                                return value ? t('content.yes') : t('content.no');
                            case 'enumeration':
                                if (Array.isArray(value)) return value.join(', ');
                                if (typeof value === 'string') {
                                    try {
                                        const parsedValue = JSON.parse(value);
                                        if (Array.isArray(parsedValue)) return parsedValue.join(', ');
                                    } catch { /* fallback */ }
                                }
                                return value;
                            case 'number':
                                return value === null ? <span className="text-muted-foreground">—</span> : Number(value).toString();
                            case 'media':
                                if (!value) return <span className="text-muted-foreground">—</span>;
                                if (Array.isArray(value)) return `${value.length} file(s)`;
                                return <span className="text-muted-foreground">—</span>;
                            case 'relation':
                                {
                                    const ids = Array.isArray(value) ? value : typeof value === 'string' && value.trim() !== '' ? ((): number[] => { try { const parsed = JSON.parse(value); return Array.isArray(parsed) ? parsed : []; } catch { return value.split(',').map(Number); } })() : [];
                                    return ids.length > 0 ? `${ids.length} related` : <span className="text-muted-foreground">—</span>;
                                }
                            default:
                                return String(value);
                        }
                    }
                });
            });
        }

        columns.push(
            {
                header: t('content.deleted_at'),
                accessorKey: "deleted_at",
                sortable: true,
                filter: {
                    type: 'date',
                },
                cell: (item: ContentEntry) => (
                    <span className="text-sm text-muted-foreground whitespace-nowrap">
                        {item.deleted_at
                            ? moment.parseZone(item.deleted_at).format('YYYY-MM-DD HH:mm')
                            : "—"}
                    </span>
                ),
            },
            {
                header: t('content.by'),
                accessorKey: "deleted_by",
                cell: (item: ContentEntry) => (
                    <span className="text-sm text-muted-foreground">
                        {item.updater?.name ?? "—"}
                    </span>
                ),
            },
            {
                header: t('content.actions'),
                accessorKey: "actions",
                align: "right",
                cell: (item: ContentEntry) => (
                    <div className="flex justify-end gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={(e) => {
                                e.stopPropagation();
                                handleRestoreSingle(item.uuid);
                            }}
                        >
                            <RotateCcw className="h-3.5 w-3.5 mr-1" />
                            {t('content.restore_btn')}
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            onClick={(e) => {
                                e.stopPropagation();
                                handleForceDeleteSingle(item.uuid);
                            }}
                        >
                            <Trash2 className="h-3.5 w-3.5 mr-1" />
                            {t('content.delete_action')}
                        </Button>
                    </div>
                ),
            }
        );

        return columns;
    };

    const backHref = `/projects/${project.id}/collections/${collection.id}`;

    return (
        <div>
            <div className="mb-3 flex items-center justify-between gap-2">
                <div className="flex items-center gap-3">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => router.visit(backHref)}
                        className="gap-1"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        {t('content.back')}
                    </Button>
                    <h1 className="text-xl font-bold flex items-center gap-2">
                        <Trash2 className="h-5 w-5 text-destructive" />
                        {t('content.trash_heading')} — {collection.name}
                        <span className="text-sm font-normal text-muted-foreground ml-2">
                            #<span className="select-all">{collection.slug}</span>
                        </span>
                    </h1>
                </div>
            </div>

            <DataTable
                ref={dataTableRef}
                pageName={`trash_${project.id}_${collection.id}`}
                searchRoute={`${baseUrl}/trash`}
                searchPlaceholder={`Search trashed ${collection.name}...`}
                columns={generateColumns()}
                selectable={true}
                onSelectionChange={setSelectedItems}
                selectedItems={selectedItems}
                itemKey="uuid"
                actions={[
                    {
                        label: t('content.restore_selected'),
                        onClick: () => setRestoreDialog(true),
                        icon: <RotateCcw className="h-4 w-4 mr-2" />,
                        variant: 'outline',
                        show: selectedItems.length > 0 && can.update_content,
                    },
                    {
                        label: t('content.delete_perm_btn'),
                        onClick: () => setForceDeleteDialog(true),
                        icon: <Trash2 className="h-4 w-4 mr-2" />,
                        variant: 'destructive',
                        show: selectedItems.length > 0 && can.delete_content,
                    },
                ]}
            />

            {/* Bulk restore dialog */}
            <Dialog open={restoreDialog} onOpenChange={setRestoreDialog}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('content.restore_entries_title')}</DialogTitle>
                        <DialogDescription>
                            {t('content.restore_entries_desc', { count: String(selectedItems.length) })}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRestoreDialog(false)} disabled={processing}>
                            {t('common.cancel')}
                        </Button>
                        <Button onClick={handleRestore} disabled={processing}>
                            <RotateCcw className="h-4 w-4 mr-2" />
                            {t('content.restore_btn')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Bulk force delete dialog */}
            <Dialog open={forceDeleteDialog} onOpenChange={setForceDeleteDialog}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <AlertTriangle className="h-5 w-5 text-destructive" />
                            {t('content.delete_entries_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('content.delete_entries_desc', { count: String(selectedItems.length) })}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setForceDeleteDialog(false)} disabled={processing}>
                            {t('common.cancel')}
                        </Button>
                        <Button variant="destructive" onClick={handleForceDelete} disabled={processing}>
                            <Trash2 className="h-4 w-4 mr-2" />
                            {t('content.delete_perm_btn')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
