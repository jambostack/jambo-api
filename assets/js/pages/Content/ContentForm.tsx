import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { router, usePage } from '@inertiajs/react';
import { slugify } from '@/lib/utils';
import { useTranslation } from '@/lib/i18n';

import type { Project, Collection, Field, UserCan } from "@/types";

import { Button } from "@/components/ui/button";
import {  DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import { ChevronDown, Clock, FileText, Calendar, User, Globe2, Copy, Key, AlertCircle, Trash2, X, CheckCircle2 } from "lucide-react";
import { renderField } from './Fields';
import { Card, CardContent } from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";
import { Badge } from "@/components/ui/badge";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import Select from "@/components/ui/select/Select";

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
type SaveStatus = 'draft' | 'published';

function buildInitialFormData(fields: Field[]): Record<string, any> {
    const data: Record<string, any> = {};
    fields.forEach(field => {
        if (field.options?.repeatable) {
            data[field.slug] = [{ value: null }];
        } else if (field.type === 'enumeration' && field.options?.multiple) {
            data[field.slug] = [];
        } else if (field.type === 'boolean') {
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
    const is_singleton = collection.is_singleton;

    // Locale state
    const projLocales = (project as any).locales as string[] | undefined;
    const availableLocales = Array.isArray(projLocales) && projLocales.length ? projLocales : [project.default_locale || 'en'];
    const [locale, setLocale] = useState<string>(contentEntry?.locale || project.default_locale || availableLocales[0]);

    useEffect(() => {
        // If we have initial form data (for editing), normalise it first (e.g. media fields should be arrays of UUIDs)
        if (initialFormData && Object.keys(initialFormData).length > 0) {
            const normalisedData: Record<string, any> = { ...initialFormData };

            // Iterate over collection fields to apply any type-specific normalisation rules
            collection.fields.forEach(field => {
                if (field.type === 'media') {
                    const rawValue = initialFormData[field.slug];

                    // Helper to pull the UUID off either an object or primitive
                    const extractUuid = (val: any) => (val && typeof val === 'object' ? val.uuid ?? null : val ?? null);

                    // Determine if this media field actually supports multiple files
                    const allowsMultiple =
                        Boolean(field.options?.multiple) ||
                        (field.options?.media?.type === 2) ||
                        Array.isArray(rawValue);

                    if (allowsMultiple) {
                        // Ensure we end up with an array of UUIDs (empty when none)
                        normalisedData[field.slug] = Array.isArray(rawValue)
                            ? rawValue.map(extractUuid).filter((id: any) => id !== null)
                            : [];
                    } else {
                        // Single media: reduce to a single UUID (or null) stored as array for consistency
                        const id = Array.isArray(rawValue) ? extractUuid(rawValue[0]) : extractUuid(rawValue);
                        normalisedData[field.slug] = id !== null ? [id] : [];
                    }
                }
            });

            setFormData(normalisedData);
            return;
        }

        // Otherwise initialize form data for each field
        setFormData(buildInitialFormData(collection.fields));
    // Depend on stable primitives, not the collection object reference,
    // to avoid resetting user-edited form data on parent re-renders.
    }, [collection.id, initialFormData]);

    const handleSubmit = async (action: SaveAction, status: SaveStatus) => {
        setProcessing(true);
        setErrors({});

        try {
            let response;
            
            const contentBase = `/api/projects/${project.uuid}/collections/${collection.slug}/entries`;

            if (isEditMode && contentEntry) {
                // Update existing content
                response = await axios.put(
                    `${contentBase}/${contentEntry.uuid}`,
                    { fields: formData, status, locale }
                );
            } else {
                // Create new content
                response = await axios.post(
                    contentBase,
                    { fields: formData, status, locale }
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
                // Refresh the page to reflect the updated status
                router.reload();
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

        // If this field is referenced by a slug field, update the slug
        const slugField = collection.fields.find(f =>
            f.type === 'slug' &&
            f.options?.slug?.field === field.slug
        );
        if (slugField && !field.options?.repeatable) {
            newData[slugField.slug] = slugify(value);
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
                <div className="flex justify-between space-x-4">
                    <div className="space-y-4 w-3/4">
                        {is_singleton && (
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
                                        errors
                                    })}
                                </React.Fragment>
                            </div>
                        ))}
                    </div>
                    
                    <div className="flex-1 w-1/4">
                        <aside className="space-y-4 sticky top-4">
                            {!is_singleton && (
                                <>
                                <div className="flex flex-col space-y-3">
                                    {isEditMode && contentEntry?.status === 'draft' && (
                                        <div className="flex space-x-2">
                                            <Button
                                                onClick={() => handleSubmit('stay', 'draft')}
                                                disabled={processing}
                                                className="flex-grow"
                                            >
                                                {t('content.form.save_draft')}
                                            </Button>
                                        </div>
                                    )}
                                    {!isEditMode && (
                                        <div className="flex space-x-2">
                                            <Button
                                                onClick={() => handleSubmit('stay', 'draft')}
                                                disabled={processing}
                                                className="flex-grow"
                                            >
                                                {t('content.form.save_draft')}
                                            </Button>
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button variant="outline" size="icon" className="px-2">
                                                        <ChevronDown className="h-4 w-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem onClick={() => handleSubmit('close', 'draft')}>
                                                        {t('content.form.save_close')}
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem onClick={() => handleSubmit('new', 'draft')}>
                                                        {t('content.form.save_new')}
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                    )}

                                    {isEditMode && can.publish_content && (
                                        <div className="flex space-x-2">
                                            <Button
                                                onClick={() => handleSubmit('stay', 'published')}
                                                disabled={processing}
                                                className="flex-grow bg-green-600 hover:bg-green-700 text-white"
                                            >
                                                {t('content.form.save_publish')}
                                            </Button>
                                        </div>
                                    )}

                                    {!isEditMode && can.publish_content && (
                                        <div className="flex space-x-2">
                                            <Button
                                                onClick={() => handleSubmit('stay', 'published')}
                                                disabled={processing}
                                                className="flex-grow bg-green-600 hover:bg-green-700 text-white"
                                            >
                                                {t('content.form.save_publish')}
                                            </Button>
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button variant="outline" size="icon" className="px-2">
                                                        <ChevronDown className="h-4 w-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem onClick={() => handleSubmit('close', 'published')}>
                                                        {t('content.form.save_publish_close')}
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem onClick={() => handleSubmit('new', 'published')}>
                                                        {t('content.form.save_publish_new')}
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                    )}
                                </div>

                                {isEditMode && contentEntry && (
                                    <div className={`p-3 rounded-md flex items-center justify-between ${
                                        contentEntry.status === 'published' 
                                            ? 'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800' 
                                            : 'bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800'
                                    }`}>
                                        <div className="flex items-center space-x-2">
                                            {contentEntry.status === 'published' ? (
                                                <CheckCircle2 className="h-5 w-5 text-green-600 dark:text-green-400" />
                                            ) : (
                                                <AlertCircle className="h-5 w-5 text-amber-600 dark:text-amber-400" />
                                            )}
                                            <div>
                                                <h3 className={`font-medium ${
                                                    contentEntry.status === 'published'
                                                        ? 'text-green-700 dark:text-green-400'
                                                        : 'text-amber-700 dark:text-amber-400'
                                                }`}>
                                                    {contentEntry.status === 'published' ? t('content.form.published_badge') : t('content.form.draft_badge')}
                                                </h3>
                                                <p className="text-xs text-muted-foreground">
                                                    {contentEntry.status === 'published'
                                                        ? t('content.form.published_on', { date: new Date(contentEntry.published_at).toLocaleDateString() })
                                                        : t('content.form.not_published')}
                                                </p>
                                            </div>
                                        </div>
                                        <Badge variant={contentEntry.status === 'published' ? 'default' : 'outline'} className={
                                            contentEntry.status === 'published' 
                                                ? 'bg-green-600 hover:bg-green-700' 
                                                : 'text-amber-600 border-amber-300 dark:border-amber-600'
                                        }>
                                            {contentEntry.status === 'published' ? t('content.form.live') : t('content.form.draft_badge')}
                                        </Badge>
                                    </div>
                                )}

                                {isEditMode && contentEntry && contentEntry.status === 'published' && can.unpublish_content && (
                                    <Button 
                                        onClick={() => setShowUnpublishDialog(true)}
                                        disabled={processing}
                                        variant="outline"
                                        className="w-full border-amber-300 text-amber-700 hover:bg-amber-50 dark:border-amber-600 dark:text-amber-400 dark:hover:bg-amber-900/20"
                                    >
                                        <AlertCircle className="mr-2 h-4 w-4" />
                                        {t('content.form.unpublish_btn')}
                                    </Button>
                                )}
                                
                                {isEditMode && contentEntry && (can.create_content || can.move_content_to_trash || can.delete_content) && (
                                    <>
                                        <div className="flex space-x-2">
                                            
                                            {can.move_content_to_trash && (
                                                <Button
                                                    onClick={() => setShowTrashDialog(true)}
                                                    variant="outline"
                                                    className="flex-1 border-red-200 text-red-600 hover:bg-red-50 hover:text-red-700"
                                                >
                                                    <Trash2 className="mr-2 h-4 w-4" />
                                                    {t('content.form.move_to_trash')}
                                                </Button>
                                            )}
                                            {can.delete_content && (
                                                <Button
                                                    onClick={() => setShowDeleteDialog(true)}
                                                    variant="outline"
                                                    className="flex-1 border-red-200 text-red-600 hover:bg-red-50 hover:text-red-700"
                                                >
                                                    <X className="mr-2 h-4 w-4" />
                                                    {t('content.form.delete_btn')}
                                                </Button>
                                            )}
                                        </div>
                                        <div className="flex space-x-2">
                                            {can.create_content && (
                                                <Button
                                                    onClick={() => setShowDuplicateDialog(true)}
                                                    variant="outline"
                                                    className="flex-1"
                                                    disabled={processing}
                                                >
                                                    <Copy className="mr-2 h-4 w-4" />
                                                    {t('content.form.duplicate_btn')}
                                                </Button>
                                            )}
                                        </div>
                                    </>
                                )}
                                
                                
                                </>
                            )} 

                            {is_singleton && (
                                <div className="flex space-x-2">
                                    <Button
                                        onClick={() => handleSubmit('stay', 'published')}
                                        disabled={processing}
                                        className="flex-grow bg-green-600 hover:bg-green-700 text-white"
                                    >
                                        {t('content.form.save_content')}
                                    </Button>
                                </div>
                            )}

                            {is_singleton && (
                                <div className="text-sm text-muted-foreground">
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
                                        <Select
                                            isMulti={false}
                                            value={{ value: locale, label: locale.toUpperCase() }}
                                            onChange={(option: any) => setLocale(option?.value || project.default_locale)}
                                            options={availableLocales.map(l => ({ value: l, label: l.toUpperCase() }))}
                                            isDisabled={processing}
                                        />
                                    </div>
                                </CardContent>
                            </Card>

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