import React, { useState, useEffect, useRef } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { router, usePage } from '@inertiajs/react';
import { slugify } from '@/lib/utils';
import { useTranslation } from '@/lib/i18n';

import type { Project, Collection, Field, UserCan } from "@/types";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {  DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import { ChevronDown, Clock, FileText, Calendar, User, Globe2, Copy, Key, AlertCircle, Trash2, X, CheckCircle2, Save, Send } from "lucide-react";
import { renderField } from './Fields';
import { Card, CardContent } from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";
import { Badge } from "@/components/ui/badge";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import ReactSelect from "@/components/ui/select/Select";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import AiToolbar from '@/components/AiToolbar';
import VersionHistory from '@/components/VersionHistory';

interface Props {
    project: Project;
    collection: Collection & {
        fields: Field[];
    };
    contentEntry?: any;
    formData?: Record<string, any>;
    isEditMode?: boolean;
}

type SaveAction = 'stay' | 'close' | 'new';
type SaveStatus = 'draft' | 'published' | 'scheduled';

function buildInitialFormData(fields: Field[]): Record<string, any> {
    const data: Record<string, any> = {};
    fields.forEach(field => {
        if (field.type === 'repeater') {
            const subFields = (field.options?.subFields as any[]) ?? [];
            const defaultItem: Record<string, any> = {};
            subFields.forEach((sf: any) => { defaultItem[sf.slug] = sf.type === 'boolean' ? false : null; });
            data[field.slug] = subFields.length > 0 ? [defaultItem] : [];
        } else if (field.options?.repeatable) {
            data[field.slug] = [{ value: null }];
        } else if (field.type === 'enumeration' && field.options?.multiple) {
            data[field.slug] = [];
        } else if (field.type === 'boolean' || field.type === 'checkbox') {
            data[field.slug] = false;
        } else if (field.type === 'media' || field.type === 'relation') {
            data[field.slug] = [];
        } else if (field.type === 'number' || field.type === 'decimal') {
            data[field.slug] = null;
        } else if (field.type === 'json') {
            data[field.slug] = null;
        } else {
            data[field.slug] = '';
        }
    });
    return data;
}

export default function ContentForm({ project, collection, contentEntry, formData: initialFormData, isEditMode }: Props) {
    const t = useTranslation();
    const [formData, setFormData] = useState<Record<string, any>>({});
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    
    // Dialog states
    const [showUnpublishDialog, setShowUnpublishDialog] = useState(false);
    const [showTrashDialog, setShowTrashDialog] = useState(false);
    const [showDeleteDialog, setShowDeleteDialog] = useState(false);
    const [showDuplicateDialog, setShowDuplicateDialog] = useState(false);

    const can = usePage().props.userCan as UserCan;
    const isSingleton = collection.isSingleton;
    const [localStatus, setLocalStatus] = useState<string | null>(null);
    const [showSchedulePicker, setShowSchedulePicker] = useState(false);
    const [scheduledDate, setScheduledDate] = useState<string>('');
    const [scheduledTime, setScheduledTime] = useState<string>('12:00');

    // Locale state
    const projLocales = (project as any).locales as string[] | undefined;
    const availableLocales = Array.isArray(projLocales) && projLocales.length ? projLocales : [project.default_locale || 'en'];
    const entryLocale = contentEntry?.locale;
    const [locale, setLocale] = useState<string>(entryLocale || project.default_locale || availableLocales[0]);
    const localeMismatch = isEditMode && entryLocale && locale !== entryLocale;

// B5: stabiliser initialFormData pour éviter reset du formulaire quand le parent recrée l'objet
const initialFormDataKey = useRef<string | null>(null);
useEffect(() => {
    const key = JSON.stringify(initialFormData ?? null);
    if (key === initialFormDataKey.current) return;
    initialFormDataKey.current = key;

    // If we have initial form data (for editing), normalise it first
    if (initialFormData && Object.keys(initialFormData).length > 0) {
        const normalisedData: Record<string, any> = { ...initialFormData };

        collection.fields.forEach(field => {
            if (field.type === 'media') {
                const rawValue = initialFormData[field.slug];
                const extractUuid = (val: any) => (val && typeof val === 'object' ? val.uuid ?? null : val ?? null);
                const allowsMultiple =
                    Boolean(field.options?.multiple) ||
                    (field.options?.media?.type === 2) ||
                    Array.isArray(rawValue);

                if (allowsMultiple) {
                    normalisedData[field.slug] = Array.isArray(rawValue)
                        ? rawValue.map(extractUuid).filter((id: any) => id !== null)
                        : [];
                } else {
                    const id = Array.isArray(rawValue) ? extractUuid(rawValue[0]) : extractUuid(rawValue);
                    normalisedData[field.slug] = id !== null ? [id] : [];
                }
            }
            if (field.type === 'repeater') {
                const raw = initialFormData[field.slug];
                if (Array.isArray(raw) && raw.length > 0) {
                    normalisedData[field.slug] = raw;
                } else {
                    const subFields = (field.options?.subFields as any[]) ?? [];
                    const defaultItem: Record<string, any> = {};
                    subFields.forEach((sf: any) => { defaultItem[sf.slug] = sf.type === 'boolean' ? false : null; });
                    normalisedData[field.slug] = subFields.length > 0 ? [defaultItem] : [];
                }
            }
        });

        setFormData(normalisedData);
        return;
    }

    setFormData(buildInitialFormData(collection.fields));
}, [collection.id, initialFormData]);

    const handleSubmit = async (action: SaveAction, status: SaveStatus, scheduledAt?: string) => {
        setProcessing(true);
        setErrors({});

        try {
            let response;
            
            const contentBase = `/api/projects/${project.uuid}/collections/${collection.slug}/entries`;

            const payload: any = { fields: formData, status, locale };
            if (scheduledAt) {
                payload.scheduledAt = scheduledAt;
            }

            if (formData._assigned_to_id) {
                payload.assigned_to_id = parseInt(formData._assigned_to_id);
            }

            if (isEditMode && contentEntry) {
                // Update existing content
                response = await axios.put(
                    `${contentBase}/${contentEntry.uuid}`,
                    payload
                );
            } else {
                // Create new content
                response = await axios.post(
                    contentBase,
                    payload
                );
            }
            
            // Handle successful creation/update
            toast.success(response.data.message || t('content.form.save_success'));
            
            // Handle different actions after save
            if (action === 'close' || (!isEditMode && !can.update_content)) {
                // Redirect to collection page
                router.visit(route('projects.collections.show', {
                    project: project.id,
                    collection: collection.id
                }));
            } else if (action === 'new') {
                // Reset form for a new entry
                setFormData(buildInitialFormData(collection.fields));
                window.scrollTo(0, 0);
            } else if (action === 'stay' && !isEditMode && can.update_content && response.data.data?.id) {
                router.visit(route('projects.collections.content.edit', {
                    project: project.id,
                    collection: collection.id,
                    contentEntry: response.data.data.id,
                }));
            } else if (action === 'stay' && isEditMode) {
                // B6 : mettre à jour le statut localement au lieu de recharger la page
                if (response.data.data?.status) {
                    setLocalStatus(response.data.data.status);
                }
            }
        } catch (error: any) {
            if (error.response?.data?.errors) {
                setErrors(error.response.data.errors);
                toast.error(t('content.form.save_error'));
            } else {
                toast.error(t('content.form.save_error_generic'));
            }
        } finally {
            setProcessing(false);
        }
    };

    const handleUnpublish = async () => {
        setShowUnpublishDialog(false);
        await handleSubmit('stay', 'draft');
    };

    const handleMoveToTrash = async () => {
        setProcessing(true);
        try {
            await axios.delete(
                `/api/projects/${project.uuid}/collections/${collection.slug}/entries/${contentEntry.uuid}`
            );
            toast.success(t('content.form.trash_success'));
            router.visit(route('projects.collections.show', {
                project: project.id,
                collection: collection.id,
            }));
        } catch {
            toast.error(t('content.form.trash_error'));
        } finally {
            setProcessing(false);
            setShowTrashDialog(false);
        }
    };

    const handleDelete = async () => {
        setProcessing(true);
        try {
            await axios.delete(
                `/api/projects/${project.uuid}/collections/${collection.slug}/entries/${contentEntry.uuid}/force-delete`
            );
            toast.success(t('content.form.delete_success'));
            router.visit(route('projects.collections.show', {
                project: project.id,
                collection: collection.id,
            }));
        } catch {
            toast.error(t('content.form.delete_error'));
        } finally {
            setProcessing(false);
            setShowDeleteDialog(false);
        }
    };

    const handleDuplicate = async () => {
        setProcessing(true);
        try {
            const response = await axios.post(
                `/api/projects/${project.uuid}/collections/${collection.slug}/entries/${contentEntry.uuid}/duplicate`
            );

            toast.success(response.data.message || t('content.form.duplicate_success'));

            const newId = response.data.data?.id;
            if (newId) {
                router.visit(route('projects.collections.content.edit', {
                    project: project.id,
                    collection: collection.id,
                    contentEntry: newId,
                }));
            } else {
                router.reload();
            }
        } catch (error) {
            toast.error(t('content.form.duplicate_failed'));
        } finally {
            setProcessing(false);
            setShowDuplicateDialog(false);
        }
    };

    const handleFieldChange = (field: Field, value: any, index?: number) => {
        const newData = { ...formData };

        if (field.type === 'repeater') {
            newData[field.slug] = value;
            setFormData(newData);
            return;
        }

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

        // B4: ne générer le slug que si la valeur est une string
        if (typeof value === 'string') {
            const slugField = collection.fields.find(f =>
                f.type === 'slug' &&
                f.options?.slug?.field === field.slug
            );
            if (slugField && !field.options?.repeatable) {
                newData[slugField.slug] = slugify(value);
            }
        }

        setFormData(newData);
    };

    // Format a date for display
    const formatDate = (dateString: string) => {
        if (!dateString) return t('content.form.na');
        return new Date(dateString).toLocaleString();
    };

    return (
        <div>
            <div className="space-y-6">
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_300px] lg:gap-8 xl:grid-cols-[minmax(0,1fr)_340px]">
                    <div className="min-w-0 space-y-4">
                        {isSingleton && (
                            <div className="mb-3">
                                <h1 className="text-xl font-bold">{collection.name}
                                    <span className="text-sm font-normal text-muted-foreground ml-2">
                                        #
                                        <span className="select-all">{collection.slug}</span>
                                    </span>
                                </h1>
                            </div>
                        )}
                        {collection.fields.map(field => (
                            <div className="border border-gray-200 dark:border-gray-800 border-dashed w-full p-4 rounded-md" key={field.id}>
                                <React.Fragment>
                                    {renderField({
                                        field,
                                        value: formData[field.slug],
                                        onChange: handleFieldChange,
                                        processing,
                                        errors,
                                        project
                                    })}
                                </React.Fragment>
                            </div>
                        ))}
                    </div>

                    <div>
                        <aside className="space-y-4 lg:sticky lg:top-4 lg:self-start">
                            {!isSingleton && (<>{(() => {
                                const wfStatuses = collection.settings?.workflow?.statuses ?? [
                                    { slug: 'draft', label: 'Draft', color: '#6b7280', published: false },
                                    { slug: 'published', label: 'Published', color: '#10b981', published: true },
                                ];
                                const publishedSlug = wfStatuses.find(s => s.published)?.slug ?? 'published';

                                if (isEditMode && contentEntry?.status === 'scheduled' && can.publish_content) {
                                    return (
                                        <div className="space-y-2.5 rounded-xl border border-border bg-card/60 p-3 shadow-sm">
                                            <Button onClick={() => handleSubmit('stay', publishedSlug)} disabled={processing}
                                                className="h-10 w-full rounded-lg bg-emerald-600 font-medium text-white shadow-sm hover:bg-emerald-700">
                                                <Send className="me-2 h-4 w-4" />{t('content.publish_now')}
                                            </Button>
                                            <Button variant="outline" onClick={() => setShowSchedulePicker(true)} disabled={processing}
                                                className="h-10 w-full rounded-lg font-medium">
                                                <Calendar className="w-4 h-4 mr-2" />{t('content.reschedule')}
                                            </Button>
                                        </div>
                                    );
                                }

                                return (
                                    <div className="space-y-2.5 rounded-xl border border-border bg-card/60 p-3 shadow-sm">
                                        {wfStatuses.map(s => {
                                            const isPublished = s.published === true;
                                            const currentStatus = contentEntry?.status;
                                            const isCurrent = currentStatus === s.slug;

                                            if (isEditMode && isCurrent && !isPublished) {
                                                return (
                                                    <Button key={s.slug} onClick={() => handleSubmit('stay', s.slug)} disabled={processing}
                                                        className="h-10 w-full rounded-lg font-medium shadow-sm">
                                                        <Save className="me-2 h-4 w-4" />Save as {s.label}
                                                    </Button>
                                                );
                                            }
                                            if (isEditMode && can.publish_content && isPublished) {
                                                return (
                                                    <Button key={s.slug} onClick={() => handleSubmit('stay', s.slug)} disabled={processing}
                                                        className="h-10 w-full rounded-lg bg-emerald-600 font-medium text-white shadow-sm hover:bg-emerald-700">
                                                        <Send className="me-2 h-4 w-4" />{s.label}
                                                    </Button>
                                                );
                                            }
                                            if (!isEditMode && isPublished && can.publish_content) {
                                                return (
                                                    <div key={s.slug} className="flex gap-1.5">
                                                        <Button onClick={() => handleSubmit('stay', s.slug)} disabled={processing}
                                                            className="h-10 flex-1 rounded-lg bg-emerald-600 font-medium text-white shadow-sm hover:bg-emerald-700">
                                                            <Send className="me-2 h-4 w-4" />{s.label}
                                                        </Button>
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger asChild>
                                                                <Button size="icon" className="h-10 w-10 shrink-0 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
                                                                    <ChevronDown className="h-4 w-4" />
                                                                </Button>
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="end">
                                                                <DropdownMenuItem onClick={() => handleSubmit('close', s.slug)}>
                                                                    {t('content.form.save_publish_close')}
                                                                </DropdownMenuItem>
                                                                <DropdownMenuItem onClick={() => handleSubmit('new', s.slug)}>
                                                                    {t('content.form.save_publish_new')}
                                                                </DropdownMenuItem>
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                    </div>
                                                );
                                            }
                                            if (!isEditMode && !isPublished) {
                                                return (
                                                    <div key={s.slug} className="flex gap-1.5">
                                                        <Button onClick={() => handleSubmit('stay', s.slug)} disabled={processing}
                                                            variant="secondary" className="h-10 flex-1 rounded-lg font-medium">
                                                            <Save className="me-2 h-4 w-4" />Save as {s.label}
                                                        </Button>
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger asChild>
                                                                <Button variant="secondary" size="icon" className="h-10 w-10 shrink-0 rounded-lg">
                                                                    <ChevronDown className="h-4 w-4" />
                                                                </Button>
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="end">
                                                                <DropdownMenuItem onClick={() => handleSubmit('close', s.slug)}>
                                                                    {t('content.form.save_close')}
                                                                </DropdownMenuItem>
                                                                <DropdownMenuItem onClick={() => handleSubmit('new', s.slug)}>
                                                                    {t('content.form.save_new')}
                                                                </DropdownMenuItem>
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                    </div>
                                                );
                                            }
                                            return null;
                                        })}
                                        {!showSchedulePicker && can.publish_content && (
                                            <Button type="button" variant="outline" onClick={() => setShowSchedulePicker(true)} disabled={processing}
                                                className="h-10 w-full rounded-lg font-medium">
                                                <Calendar className="w-4 h-4 mr-2" />{t('content.schedule_btn')}
                                            </Button>
                                        )}
                                    </div>
                                );
                            })()}

                                {showSchedulePicker && (
                                    <div className="flex items-center gap-3 p-3 border rounded-md bg-muted/30">
                                        <div className="flex items-center gap-2">
                                            <Label htmlFor="scheduled-date" className="text-sm">{t('content.schedule_date')}</Label>
                                            <Input
                                                id="scheduled-date"
                                                type="date"
                                                value={scheduledDate}
                                                min={new Date().toISOString().split('T')[0]}
                                                onChange={(e) => setScheduledDate(e.target.value)}
                                                className="w-36"
                                            />
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Label htmlFor="scheduled-time" className="text-sm">{t('content.schedule_time')}</Label>
                                            <Input
                                                id="scheduled-time"
                                                type="time"
                                                value={scheduledTime}
                                                onChange={(e) => setScheduledTime(e.target.value)}
                                                className="w-28"
                                            />
                                        </div>
                                        <Button
                                            type="button"
                                            variant="default"
                                            onClick={async () => {
                                                if (!scheduledDate) return;
                                                const scheduledAt = `${scheduledDate}T${scheduledTime}:00`;
                                                await handleSubmit('stay', 'scheduled', scheduledAt);
                                                setShowSchedulePicker(false);
                                            }}
                                            disabled={processing || !scheduledDate}
                                        >
                                            {t('content.schedule_confirm')}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => setShowSchedulePicker(false)}
                                        >
                                            <X className="w-4 h-4" />
                                        </Button>
                                    </div>
                                )}

                                {isEditMode && (
                                    <div className="rounded-xl border border-border bg-card/60 p-3 shadow-sm">
                                        <Label className="text-xs font-medium mb-2 block">Assigne a</Label>
                                        <Select
                                            value={formData._assigned_to_id ?? ''}
                                            onValueChange={(val: string) => setFormData(prev => ({ ...prev, _assigned_to_id: val }))}
                                            disabled={processing}
                                        >
                                            <SelectTrigger className="h-9 text-sm">
                                                <SelectValue placeholder="Non assigne" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="">Non assigne</SelectItem>
                                                {(project as any).members?.map((m: any) => (
                                                    m.user && <SelectItem key={m.user.id} value={String(m.user.id)}>{m.user.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}

                                {isEditMode && contentEntry && (
                                    <div className={`relative flex items-center justify-between gap-2 overflow-hidden rounded-xl border p-3.5 ${
                                        contentEntry.status === 'published'
                                            ? 'border-emerald-200/70 bg-emerald-50/60 dark:border-emerald-900/50 dark:bg-emerald-950/30'
                                            : 'border-amber-200/70 bg-amber-50/60 dark:border-amber-900/50 dark:bg-amber-950/30'
                                    }`}>
                                        <div className={`pointer-events-none absolute -end-5 -top-5 h-20 w-20 rounded-full blur-2xl ${
                                            contentEntry.status === 'published' ? 'bg-emerald-400/20' : 'bg-amber-400/20'
                                        }`} aria-hidden="true" />
                                        <div className="relative flex items-center gap-2.5">
                                            <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${
                                                contentEntry.status === 'published'
                                                    ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                                    : 'bg-amber-500/15 text-amber-600 dark:text-amber-400'
                                            }`}>
                                                {contentEntry.status === 'published' ? (
                                                    <CheckCircle2 className="h-[18px] w-[18px]" />
                                                ) : (
                                                    <AlertCircle className="h-[18px] w-[18px]" />
                                                )}
                                            </div>
                                            <div className="min-w-0">
                                                <h3 className={`text-sm font-semibold leading-tight ${
                                                    contentEntry.status === 'published'
                                                        ? 'text-emerald-700 dark:text-emerald-400'
                                                        : 'text-amber-700 dark:text-amber-400'
                                                }`}>
                                                    {contentEntry.status === 'published' ? t('content.form.published_badge') : t('content.form.draft_badge')}
                                                </h3>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {contentEntry.status === 'published'
                                                        ? t('content.form.published_on', { date: new Date(contentEntry.published_at).toLocaleDateString() })
                                                        : t('content.form.not_published')}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {isEditMode && contentEntry && contentEntry.status === 'published' && can.unpublish_content && (
                                    <Button
                                        onClick={() => setShowUnpublishDialog(true)}
                                        disabled={processing}
                                        variant="outline"
                                        className="h-9 w-full rounded-lg border-amber-300 text-amber-700 hover:bg-amber-50 dark:border-amber-700/60 dark:text-amber-400 dark:hover:bg-amber-950/40"
                                    >
                                        <AlertCircle className="me-2 h-4 w-4" />
                                        {t('content.form.unpublish_btn')}
                                    </Button>
                                )}

                                {isEditMode && contentEntry && (can.create_content || can.move_content_to_trash || can.delete_content) && (
                                    <div className="space-y-1.5 rounded-xl border border-border/60 bg-muted/30 p-1.5">
                                        {can.create_content && (
                                            <Button
                                                onClick={() => setShowDuplicateDialog(true)}
                                                variant="ghost"
                                                disabled={processing}
                                                className="h-9 w-full justify-start rounded-lg text-sm font-normal text-muted-foreground hover:text-foreground"
                                            >
                                                <Copy className="me-2 h-4 w-4" />
                                                {t('content.form.duplicate_btn')}
                                            </Button>
                                        )}
                                        {can.move_content_to_trash && (
                                            <Button
                                                onClick={() => setShowTrashDialog(true)}
                                                variant="ghost"
                                                className="h-9 w-full justify-start rounded-lg text-sm font-normal text-red-600 hover:bg-red-50 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-950/40"
                                            >
                                                <Trash2 className="me-2 h-4 w-4" />
                                                {t('content.form.move_to_trash')}
                                            </Button>
                                        )}
                                        {can.delete_content && (
                                            <Button
                                                onClick={() => setShowDeleteDialog(true)}
                                                variant="ghost"
                                                className="h-9 w-full justify-start rounded-lg text-sm font-normal text-red-600 hover:bg-red-50 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-950/40"
                                            >
                                                <X className="me-2 h-4 w-4" />
                                                {t('content.form.delete_btn')}
                                            </Button>
                                        )}
                                    </div>
                                )}
                                </>
                            )}

                            {isSingleton && (
                                <Button
                                    onClick={() => handleSubmit('stay', 'published')}
                                    disabled={processing}
                                    className="h-10 w-full rounded-lg bg-emerald-600 font-medium text-white shadow-sm transition-colors hover:bg-emerald-700"
                                >
                                    <Send className="me-2 h-4 w-4" />
                                    {t('content.form.save_content')}
                                </Button>
                            )}

                            {isSingleton && (
                                <div className="rounded-lg border border-dashed border-border bg-muted/20 px-3 py-2 text-xs leading-relaxed text-muted-foreground">
                                    {t('content.form.singleton_desc')}
                                </div>
                            )}

                            {/* Locale selector */}
                            <Card className="py-2">
                                <CardContent className="py-3">
                                    
                                    <div className="space-y-2">
                                        <h3 className="text-sm font-medium flex items-center space-x-2">
                                            <Globe2 className="w-4 h-4 text-muted-foreground" />
                                            <span>{t('content.form.locale_label')}</span>
                                        </h3>
                                        <ReactSelect
                                            isMulti={false}
                                            value={{ value: locale, label: locale.toUpperCase() }}
                                            onChange={(option: any) => setLocale(option?.value || locale)}
                                            options={availableLocales.map(l => ({ value: l, label: l.toUpperCase() }))}
                                            isDisabled={processing}
                                        />
                                        {localeMismatch && (
                                            <p className="text-xs text-amber-600 dark:text-amber-400 mt-1">
                                                {t('content.form.locale_mismatch_warning')}
                                            </p>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>

                            <AiToolbar
                                projectUuid={project.uuid}
                                collectionSlug={collection.slug}
                                formData={formData}
                                onContentGenerated={(data) => setFormData(prev => ({ ...prev, ...data }))}
                                locales={availableLocales}
                                defaultLocale={locale}
                                fields={collection.fields as Field[]}
                            />

                            {isEditMode && contentEntry && (
                                <VersionHistory
                                    projectUuid={project.uuid}
                                    collectionSlug={collection.slug}
                                    entryUuid={contentEntry.uuid}
                                    onRestored={() => window.location.reload()}
                                />
                            )}

                            {isEditMode && contentEntry && (
                                <Card className="py-2">
                                    <CardContent className="py-3">
                                        <h3 className="text-sm font-medium mb-3">{t('content.form.details_heading')}</h3>
                                        <div className="space-y-3 text-sm">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center space-x-2">
                                                    <FileText className="w-4 h-4 text-muted-foreground" />
                                                    <span className="text-muted-foreground">{t('content.form.id_label')}</span>
                                                </div>
                                                <span className="font-medium">{contentEntry.id}</span>
                                            </div>
                                            
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center space-x-2">
                                                    <Key className="w-4 h-4 text-muted-foreground" />
                                                    <span className="text-muted-foreground">{t('content.form.uuid_label')}</span>
                                                </div>
                                                <div className="flex items-center space-x-1">
                                                    <span className="font-medium">
                                                        {contentEntry.uuid ? `${contentEntry.uuid.substring(0, 8)}...` : t('content.form.na')}
                                                    </span>
                                                    {contentEntry.uuid && (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-6 w-6"
                                                            onClick={async () => {
                                                                let ok = false;
                                                                if (navigator.clipboard && window.isSecureContext) {
                                                                    try { await navigator.clipboard.writeText(contentEntry.uuid); ok = true; } catch {}
                                                                }
                                                                if (!ok) {
                                                                    const el = document.createElement('textarea');
                                                                    el.value = contentEntry.uuid;
                                                                    el.style.cssText = 'position:absolute;left:-9999px';
                                                                    document.body.appendChild(el);
                                                                    el.select();
                                                                    ok = document.execCommand('copy');
                                                                    document.body.removeChild(el);
                                                                }
                                                                ok ? toast.success(t('content.form.uuid_copied')) : toast.error(t('content.form.copy_unsupported'));
                                                            }}
                                                        >
                                                            <Copy className="h-3 w-3" />
                                                        </Button>
                                                    )}
                                                </div>
                                            </div>
                                            
                                            <Separator />
                                            
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center space-x-2">
                                                    <Calendar className="w-4 h-4 text-muted-foreground" />
                                                    <span className="text-muted-foreground">{t('content.form.created_label')}</span>
                                                </div>
                                                <span className="font-medium">{formatDate(contentEntry.created_at)}</span>
                                            </div>

                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center space-x-2">
                                                    <User className="w-4 h-4 text-muted-foreground" />
                                                    <span className="text-muted-foreground">{t('content.form.by_label')}</span>
                                                </div>
                                                <span className="font-medium">{contentEntry.creator?.name || t('content.form.unknown')}</span>
                                            </div>

                                            <Separator />

                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center space-x-2">
                                                    <Clock className="w-4 h-4 text-muted-foreground" />
                                                    <span className="text-muted-foreground">{t('content.form.updated_label')}</span>
                                                </div>
                                                <span className="font-medium">{formatDate(contentEntry.updated_at)}</span>
                                            </div>

                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center space-x-2">
                                                    <User className="w-4 h-4 text-muted-foreground" />
                                                    <span className="text-muted-foreground">{t('content.form.by_label')}</span>
                                                </div>
                                                <span className="font-medium">{contentEntry.updater?.name || t('content.form.unknown')}</span>
                                            </div>

                                            <Separator />

                                            {contentEntry.status === 'published' && contentEntry.published_at && (
                                                <div className="flex items-center justify-between">
                                                    <div className="flex items-center space-x-2">
                                                        <Calendar className="w-4 h-4 text-muted-foreground" />
                                                        <span className="text-muted-foreground">{t('content.form.published_label')}</span>
                                                    </div>
                                                    <span className="font-medium">{formatDate(contentEntry.published_at)}</span>
                                                </div>
                                            )}

                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center space-x-2">
                                                    <Globe2 className="w-4 h-4 text-muted-foreground" />
                                                    <span className="text-muted-foreground">{t('content.form.locale_badge')}</span>
                                                </div>
                                                <Badge variant="outline" className="uppercase">
                                                    {contentEntry.locale || 'en'}
                                                </Badge>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            )}
                        </aside>
                    </div>
                </div>
            </div>

            {/* Unpublish Confirmation Dialog */}
            <Dialog open={showUnpublishDialog} onOpenChange={setShowUnpublishDialog}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('content.form.unpublish_title')}</DialogTitle>
                        <DialogDescription>
                            {t('content.form.unpublish_desc')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowUnpublishDialog(false)} disabled={processing}>
                            {t('common.cancel')}
                        </Button>
                        <Button
                            variant="default"
                            onClick={handleUnpublish}
                            disabled={processing}
                            className="bg-amber-600 hover:bg-amber-700 text-white"
                        >
                            <AlertCircle className="mr-2 h-4 w-4" />
                            {t('content.form.unpublish_btn')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Move to Trash Confirmation Dialog */}
            <Dialog open={showTrashDialog} onOpenChange={setShowTrashDialog}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('content.form.trash_title')}</DialogTitle>
                        <DialogDescription>
                            {t('content.form.trash_desc')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowTrashDialog(false)} disabled={processing}>
                            {t('common.cancel')}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleMoveToTrash}
                            disabled={processing}
                        >
                            <Trash2 className="mr-2 h-4 w-4" />
                            {t('content.form.move_to_trash')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Delete Confirmation Dialog */}
            <Dialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('content.form.delete_title')}</DialogTitle>
                        <DialogDescription>
                            {t('content.form.delete_desc')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowDeleteDialog(false)} disabled={processing}>
                            {t('common.cancel')}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleDelete}
                            disabled={processing}
                        >
                            <X className="mr-2 h-4 w-4" />
                            {t('content.form.delete_perm_btn')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Duplicate Confirmation Dialog */}
            <Dialog open={showDuplicateDialog} onOpenChange={setShowDuplicateDialog}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('content.form.duplicate_title')}</DialogTitle>
                        <DialogDescription>
                            {t('content.form.duplicate_desc')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowDuplicateDialog(false)} disabled={processing}>
                            {t('common.cancel')}
                        </Button>
                        <Button onClick={handleDuplicate} disabled={processing}>
                            <Copy className="mr-2 h-4 w-4" />
                            {t('content.form.duplicate_btn')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}