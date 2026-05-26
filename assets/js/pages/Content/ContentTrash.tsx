import { useState, useEffect, useCallback } from "react";
import { router } from "@inertiajs/react";
import axios from "axios";
import { toast } from "sonner";
import { useTranslation } from '@/lib/i18n';

import type { Collection, Project, Field } from "@/types";
import type { ContentEntry } from "@/types/content";

import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Checkbox } from "@/components/ui/checkbox";
import { Skeleton } from "@/components/ui/skeleton";
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";

interface Props {
    collection: Collection & { fields: Field[] };
    project: Project;
}

type TrashedEntry = ContentEntry & { deleted_at: string };

export default function ContentTrash({ collection, project }: Props) {
    const [entries, setEntries] = useState<TrashedEntry[]>([]);
    const [loading, setLoading] = useState(true);
    const [selected, setSelected] = useState<string[]>([]);
    const [processing, setProcessing] = useState(false);
    const [restoreDialog, setRestoreDialog] = useState(false);
    const [forceDeleteDialog, setForceDeleteDialog] = useState(false);
    const t = useTranslation();

    const baseUrl = `/api/projects/${project.uuid}/collections/${collection.slug}/entries`;

    const fetchTrashed = useCallback(async () => {
        setLoading(true);
        try {
            const res = await axios.get(`${baseUrl}/trash`);
            setEntries(res.data.data ?? []);
        } catch {
            toast.error(t('content.failed_load_trash'));
        } finally {
            setLoading(false);
        }
    }, [baseUrl]);

    useEffect(() => {
        fetchTrashed();
    }, [fetchTrashed]);

    const allSelected = entries.length > 0 && selected.length === entries.length;
    const someSelected = selected.length > 0;

    const toggleAll = () => {
        setSelected(allSelected ? [] : entries.map((e) => e.uuid));
    };

    const toggleOne = (uuid: string) => {
        setSelected((prev) =>
            prev.includes(uuid) ? prev.filter((u) => u !== uuid) : [...prev, uuid]
        );
    };

    const handleRestore = async () => {
        setProcessing(true);
        try {
            await Promise.all(
                selected.map((uuid) =>
                    axios.patch(`${baseUrl}/${uuid}/restore`)
                )
            );
            toast.success(`${selected.length} entr${selected.length > 1 ? "ies" : "y"} restored`);
            setSelected([]);
            setRestoreDialog(false);
            fetchTrashed();
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
                selected.map((uuid) =>
                    axios.delete(`${baseUrl}/${uuid}/force-delete`)
                )
            );
            toast.success(`${selected.length} entr${selected.length > 1 ? "ies" : "y"} permanently deleted`);
            setSelected([]);
            setForceDeleteDialog(false);
            fetchTrashed();
        } catch {
            toast.error(t('content.failed_delete'));
        } finally {
            setProcessing(false);
        }
    };

    const handleRestoreSingle = async (uuid: string) => {
        setProcessing(true);
        try {
            await axios.patch(`${baseUrl}/${uuid}/restore`);
            toast.success(t('content.restore_btn'));
            fetchTrashed();
        } catch {
            toast.error(t('content.failed_restore_entry'));
        } finally {
            setProcessing(false);
        }
    };

    const handleForceDeleteSingle = async (uuid: string) => {
        setProcessing(true);
        try {
            await axios.delete(`${baseUrl}/${uuid}/force-delete`);
            toast.success("Entry permanently deleted");
            fetchTrashed();
        } catch {
            toast.error(t('content.failed_delete_entry'));
        } finally {
            setProcessing(false);
        }
    };

    const displayableFields = (collection.fields ?? []).filter(
        (f) => f.type !== "password" && f.type !== "json" && !f.options?.hideInContentList
    ).slice(0, 4);

    const getCellValue = (entry: TrashedEntry, field: Field): string => {
        const value = entry[field.name];
        if (value === null || value === undefined || value === "") return "—";
        if (field.type === "boolean") return value ? t('content.yes') : t('content.no');
        if (field.type === "richtext" && typeof value === "string") {
            const plain = value.replace(/<[^>]+>/g, "");
            return plain.length > 40 ? plain.slice(0, 40) + "…" : plain;
        }
        if (typeof value === "string" && value.length > 40) {
            return value.slice(0, 40) + "…";
        }
        return String(value);
    };

    const backHref = `/projects/${project.id}/collections/${collection.id}`;

    return (
        <div>
            <div className="mb-4 flex items-center justify-between flex-wrap gap-2">
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
                    <div>
                        <h1 className="text-xl font-bold flex items-center gap-2">
                            <Trash2 className="h-5 w-5 text-destructive" />
                            {t('content.trash_heading')} — {collection.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {entries.length} trashed entr{entries.length !== 1 ? "ies" : "y"}
                        </p>
                    </div>
                </div>

                {someSelected && (
                    <div className="flex items-center gap-2">
                        <span className="text-sm text-muted-foreground">{t('content.selected', { count: String(selected.length) })}</span>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setRestoreDialog(true)}
                            disabled={processing}
                        >
                            <RotateCcw className="h-4 w-4 mr-1" />
                            {t('content.restore_btn')}
                        </Button>
                        <Button
                            variant="destructive"
                            size="sm"
                            onClick={() => setForceDeleteDialog(true)}
                            disabled={processing}
                        >
                            <Trash2 className="h-4 w-4 mr-1" />
                            {t('content.delete_perm_btn')}
                        </Button>
                    </div>
                )}
            </div>

            {loading ? (
                <div className="space-y-2">
                    {[...Array(4)].map((_, i) => (
                        <Skeleton key={i} className="h-12 w-full" />
                    ))}
                </div>
            ) : entries.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-16 text-muted-foreground gap-3">
                    <Trash2 className="h-10 w-10 opacity-30" />
                    <p className="text-sm">{t('content.trash_empty')}</p>
                </div>
            ) : (
                <div className="rounded-md border overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-10 px-4">
                                    <Checkbox
                                        checked={allSelected}
                                        onCheckedChange={toggleAll}
                                        aria-label="Select all"
                                    />
                                </TableHead>
                                {displayableFields.map((f) => (
                                    <TableHead key={f.id}>{f.label}</TableHead>
                                ))}
                                <TableHead>{t('content.deleted_at')}</TableHead>
                                <TableHead>{t('content.by')}</TableHead>
                                <TableHead className="text-right">{t('content.actions')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {entries.map((entry) => (
                                <TableRow key={entry.uuid} className="opacity-75">
                                    <TableCell className="px-4">
                                        <Checkbox
                                            checked={selected.includes(entry.uuid)}
                                            onCheckedChange={() => toggleOne(entry.uuid)}
                                            aria-label={`Select entry ${entry.uuid}`}
                                        />
                                    </TableCell>
                                    {displayableFields.map((f) => (
                                        <TableCell key={f.id} className="text-sm text-muted-foreground">
                                            {getCellValue(entry, f)}
                                        </TableCell>
                                    ))}
                                    <TableCell className="text-sm text-muted-foreground whitespace-nowrap">
                                        {entry.deleted_at
                                            ? new Date(entry.deleted_at).toLocaleString()
                                            : "—"}
                                    </TableCell>
                                    <TableCell className="text-sm text-muted-foreground">
                                        {entry.updater?.name ?? "—"}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <div className="flex justify-end gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={processing}
                                                onClick={() => handleRestoreSingle(entry.uuid)}
                                            >
                                                <RotateCcw className="h-3.5 w-3.5 mr-1" />
                                                {t('content.restore_btn')}
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-destructive hover:text-destructive"
                                                disabled={processing}
                                                onClick={() => handleForceDeleteSingle(entry.uuid)}
                                            >
                                                <Trash2 className="h-3.5 w-3.5 mr-1" />
                                                {t('content.delete_action')}
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            {/* Bulk restore dialog */}
            <Dialog open={restoreDialog} onOpenChange={setRestoreDialog}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('content.restore_entries_title')}</DialogTitle>
                        <DialogDescription>
                            {t('content.restore_entries_desc', { count: String(selected.length) })}
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
                            {t('content.delete_entries_desc', { count: String(selected.length) })}
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
