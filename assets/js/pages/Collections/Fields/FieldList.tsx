import React from 'react';
import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import axios from 'axios';
import { useTranslation } from '@/lib/i18n';

import type { Field } from '@/types/index.d';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { GripVertical, Pencil, Plus, Trash2 } from 'lucide-react';
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { DragDropContext, Droppable, Draggable, DropResult } from '@hello-pangea/dnd';
import { TextCursor, AlignLeft, Link, AtSign, Lock, Hash, ListOrdered, CheckSquare, Droplet, Calendar, Clock, Image, GitBranch, Code } from 'lucide-react';

import fields from '@/lib/fields.json';

import type { FieldFormModalProps, Validations, FieldFormData } from './FieldFormModal';

import FieldFormModal from './FieldFormModal';

interface FieldListProps {
    projectId: number;
    projectUuid: string;
    collectionId: number;
    collectionSlug: string;
    initialFields: Field[];
    onAddFieldClick: () => void;
    collections: Array<{
        id: number;
        name: string;
    }>;
    can: {
        create_field?: boolean;
        update_field?: boolean;
        delete_field?: boolean;
    };
}

export default function FieldList({ projectId, projectUuid, collectionId, collectionSlug, initialFields, onAddFieldClick, collections, can }: FieldListProps) {
    const t = useTranslation();
    const [fieldsList, setFieldsList] = useState([...initialFields].sort((a, b) => (a.order ?? 0) - (b.order ?? 0)));
    const [isEditModalOpen, setIsEditModalOpen] = useState(false);
    const [fieldToEdit, setFieldToEdit] = useState<Field | null>(null);
    const [fieldToDelete, setFieldToDelete] = useState<Field | null>(null);
    const [deleting, setDeleting] = useState(false);

    // Update fieldsList when initialFields changes
    useEffect(() => {
        setFieldsList([...initialFields].sort((a, b) => (a.order ?? 0) - (b.order ?? 0)));
    }, [initialFields]);

    const handleDragEnd = async (result: DropResult) => {
        if (!result.destination) return;

        const items = Array.from(fieldsList);
        const [reorderedItem] = items.splice(result.source.index, 1);
        items.splice(result.destination.index, 0, reorderedItem);

        // Update local state immediately for smooth UI
        setFieldsList(items);

        // Update the order in the backend
        try {
            await axios.post(
                `/api/projects/${projectUuid}/collections/${collectionSlug}/fields/reorder`,
                { fields: items.map((item, index) => ({ id: item.id, order: index })) }
            );
        } catch (error) {
            console.error('Failed to update field order:', error);
            // Revert to original order if the API call fails
            setFieldsList(initialFields);
        }
    };

    const handleEditClick = (field: Field) => {
        setFieldToEdit(field);
        setIsEditModalOpen(true);
    };

    const handleEditModalClose = () => {
        setIsEditModalOpen(false);
        setFieldToEdit(null);
    };

    const handleFieldSaved = () => {
        handleEditModalClose();
        router.reload({ only: ['collection'] });
    };

    const handleDeleteConfirm = async () => {
        if (!fieldToDelete) return;
        setDeleting(true);
        try {
            await axios.delete(`/api/projects/${projectUuid}/collections/${collectionSlug}/fields/${fieldToDelete.slug}`);
            setFieldToDelete(null);
            router.reload({ only: ['collection'] });
        } catch {
            setDeleting(false);
        }
    };

    return (
        <Card className="flex-1">
            <CardHeader>
                <CardTitle>Fields</CardTitle>
                <CardDescription>
                    Add, edit, and reorder fields to structure your collection's data
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="space-y-4">
                    {can.create_field && (
                    <Button
                        className="w-full"
                        onClick={onAddFieldClick}
                    >
                        <Plus className="mr-1" />
                        {t('fields.add_btn')}
                    </Button>
                    )}

                    <DragDropContext onDragEnd={handleDragEnd}>
                        <Droppable droppableId="fields">
                            {(provided) => (
                                <div
                                    {...provided.droppableProps}
                                    ref={provided.innerRef}
                                    className="space-y-4"
                                >
                                    {fieldsList.map((field, index) => {
                                        const fieldInfo = fields[field.type as keyof typeof fields];
                                        return (
                                            <Draggable
                                                key={field.id}
                                                draggableId={field.id.toString()}
                                                index={index}
                                            >
                                                {(provided) => (
                                                    <div
                                                        ref={provided.innerRef}
                                                        {...provided.draggableProps}
                                                        className="flex items-center justify-between p-4 border rounded-lg"
                                                    >
                                                        <div className="flex items-center space-x-4">
                                                            {can.update_field && (
                                                                <div
                                                                    {...provided.dragHandleProps}
                                                                    className="cursor-grab"
                                                                >
                                                                    <GripVertical className="h-4 w-4 text-muted-foreground" />
                                                                </div>
                                                            )}
                                                            <div className={cn('rounded-md p-2', fieldInfo.bg)}>
                                                                {React.createElement(
                                                                    {
                                                                        TextCursor,
                                                                        TextAlignLeft: AlignLeft,
                                                                        Link,
                                                                        AtSign,
                                                                        Lock,
                                                                        SortNumericUp: Hash,
                                                                        ListOrdered,
                                                                        CheckSquare,
                                                                        Tint: Droplet,
                                                                        Calendar,
                                                                        CalendarCheck: Clock,
                                                                        PhotoVideo: Image,
                                                                        ExchangeAlt: GitBranch,
                                                                        Code
                                                                    }[fieldInfo.icon] || TextCursor,
                                                                    { className: 'text-white' }
                                                                )}
                                                            </div>
                                                            <div>
                                                                <div className="font-medium">{field.label}</div>
                                                                <div className="text-sm text-muted-foreground select-all">
                                                                    {field.name}
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div className="flex items-center space-x-2">
                                                            {can.update_field && (
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    onClick={() => handleEditClick(field)}
                                                                >
                                                                    <Pencil className="h-4 w-4" />
                                                                </Button>
                                                            )}
                                                            {can.delete_field && (
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    onClick={() => setFieldToDelete(field)}
                                                                >
                                                                    <Trash2 className="h-4 w-4 text-destructive" />
                                                                </Button>
                                                            )}
                                                        </div>
                                                    </div>
                                                )}
                                            </Draggable>
                                        );
                                    })}
                                    {provided.placeholder}
                                </div>
                            )}
                        </Droppable>
                    </DragDropContext>
                </div>
            </CardContent>

            {fieldToEdit && (
                <FieldFormModal
                    isOpen={isEditModalOpen}
                    onClose={handleEditModalClose}
                    fieldType={fieldToEdit.type}
                    collectionId={collectionId}
                    projectId={projectId}
                    projectUuid={projectUuid}
                    collectionSlug={collectionSlug}
                    onFieldSaved={handleFieldSaved}
                    collections={collections}
                    collectionFields={fieldsList}
                    can={can}
                    editField={{
                        ...fieldToEdit,
                        slug: fieldToEdit.slug ?? fieldToEdit.name,
                        description: fieldToEdit.description ?? '',
                        placeholder: fieldToEdit.placeholder ?? '',
                        validations: (fieldToEdit.validations ?? {
                            required: { status: false, message: '' },
                            unique: { status: false, message: '' },
                            charcount: { status: false, message: '', type: 'Between', min: null, max: null }
                        }) as Validations,
                        options: (fieldToEdit.options ?? {
                            repeatable: false,
                            hideInContentList: false,
                            hiddenInAPI: false,
                            editor: { type: 1 },
                            enumeration: { list: [] },
                            multiple: false,
                            relation: { collection: null, type: 1 },
                            slug: { field: null, readonly: false },
                            timepicker: false,
                            range: false,
                            date_format: 'DD-MM-YYYY',
                            hour_format: 'HH:mm',
                            multi_calendars: false,
                            media: { type: 1 }
                        }) as FieldFormData['options']
                    } as NonNullable<FieldFormModalProps['editField']>}
                />
            )}

            <AlertDialog open={!!fieldToDelete} onOpenChange={(open) => { if (!open) setFieldToDelete(null); }}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('fields.delete_title')} "{fieldToDelete?.label}"?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('fields.delete_desc')}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={deleting}>{t('fields.cancel')}</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleDeleteConfirm}
                            disabled={deleting}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        >
                            {deleting ? `${t('fields.delete_btn')}…` : t('fields.delete_btn')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </Card>
    );
} 