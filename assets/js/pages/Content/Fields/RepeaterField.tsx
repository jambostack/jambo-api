import React, { useState } from 'react';
import { Field } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import InputError from '@/components/input-error';
import { Plus, Trash2, GripVertical, Image } from 'lucide-react';
import { DragDropContext, Droppable, Draggable, DropResult } from '@hello-pangea/dnd';
import { LexicalEditor } from '@/components/editors/lexical/LexicalEditor';
import { MediaLibraryModal } from '@/pages/Assets/MediaFieldSelectModal';
import type { Asset } from '@/types';
import FieldBase, { FieldProps } from './FieldBase';

interface SubFieldDef {
    slug: string;
    label: string;
    type: string;
    required: boolean;
    options?: Record<string, any>;
}

function subFieldErrorKey(parentSlug: string, index: number, subSlug: string): string {
    return `fields.${parentSlug}.${index}.${subSlug}`;
}

/** Input renderer for a single sub-field — lightweight, no FieldBase wrapper. */
function SubFieldInput({
    sf,
    value,
    onChange,
    processing,
    error,
    project,
}: {
    sf: SubFieldDef;
    value: any;
    onChange: (val: any) => void;
    processing: boolean;
    error?: string;
    project?: any;
}) {
    const [mediaModalOpen, setMediaModalOpen] = useState(false);
    const s = sf;
    switch (s.type) {
        case 'text':
            return (
                <div>
                    <Input type="text" value={value ?? ''} onChange={e => onChange(e.target.value)} disabled={processing} />
                    {error && <InputError message={error} />}
                </div>
            );
        case 'longtext':
            return (
                <div>
                    <Textarea value={value ?? ''} onChange={e => onChange(e.target.value)} disabled={processing} rows={3} />
                    {error && <InputError message={error} />}
                </div>
            );
        case 'richtext':
            return (
                <div className="min-h-[120px] border rounded-md">
                    <LexicalEditor
                        value={value || ''}
                        onChange={(content) => onChange(content)}
                    />
                    {error && <InputError message={error} />}
                </div>
            );
        case 'number':
            return (
                <div>
                    <Input type="number" value={value ?? ''} onChange={e => onChange(e.target.value === '' ? null : Number(e.target.value))} disabled={processing} />
                    {error && <InputError message={error} />}
                </div>
            );
        case 'email':
            return (
                <div>
                    <Input type="email" value={value ?? ''} onChange={e => onChange(e.target.value)} disabled={processing} />
                    {error && <InputError message={error} />}
                </div>
            );
        case 'url':
            return (
                <div>
                    <Input type="url" value={value ?? ''} onChange={e => onChange(e.target.value)} disabled={processing} />
                    {error && <InputError message={error} />}
                </div>
            );
        case 'color':
            return (
                <div className="flex gap-2 items-center">
                    <Input type="color" value={value ?? '#000000'} onChange={e => onChange(e.target.value)} disabled={processing} className="w-10 h-9 p-1" />
                    <Input type="text" value={value ?? ''} onChange={e => onChange(e.target.value)} disabled={processing} className="flex-1 font-mono text-xs" placeholder="#000000" />
                    {error && <InputError message={error} />}
                </div>
            );
        case 'date':
            return (
                <div>
                    <Input type="date" value={value ?? ''} onChange={e => onChange(e.target.value)} disabled={processing} />
                    {error && <InputError message={error} />}
                </div>
            );
        case 'datetime':
            return (
                <div>
                    <Input type="datetime-local" value={value ?? ''} onChange={e => onChange(e.target.value)} disabled={processing} />
                    {error && <InputError message={error} />}
                </div>
            );
        case 'time':
            return (
                <div>
                    <Input type="time" value={value ?? ''} onChange={e => onChange(e.target.value)} disabled={processing} />
                    {error && <InputError message={error} />}
                </div>
            );
        case 'boolean':
            return (
                <div className="flex items-center gap-2">
                    <Switch checked={value ?? false} onCheckedChange={onChange} disabled={processing} />
                    {error && <InputError message={error} />}
                </div>
            );
        case 'enumeration': {
            const values: string[] = s.options?.values ?? [];
            return (
                <div>
                    <Select value={value ?? ''} onValueChange={onChange} disabled={processing}>
                        <SelectTrigger className="h-9 text-sm">
                            <SelectValue placeholder="Select..." />
                        </SelectTrigger>
                        <SelectContent>
                            {values.map((v: string) => (
                                <SelectItem key={v} value={v}>{v}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {error && <InputError message={error} />}
                </div>
            );
        }
        case 'media': {
            const assetId = value ?? '';
            const isMultiple = s.options?.multiple ?? false;
            return (
                <div>
                    <div className="flex gap-2 items-center">
                        <Input
                            type="text"
                            value={assetId}
                            onChange={e => onChange(e.target.value)}
                            disabled={processing}
                            placeholder="UUID du média..."
                            className="flex-1 font-mono text-xs"
                        />
                        {project && (
                            <>
                                <Button type="button" variant="outline" size="sm"
                                    onClick={() => setMediaModalOpen(true)} disabled={processing}
                                    className="h-9 shrink-0">
                                    <Image className="h-3.5 w-3.5 mr-1" />Browse
                                </Button>
                                <MediaLibraryModal
                                    isOpen={mediaModalOpen}
                                    onClose={() => setMediaModalOpen(false)}
                                    project={project}
                                    onSelect={(assets: Asset[]) => {
                                        if (assets.length > 0) {
                                            onChange(isMultiple ? assets.map(a => a.uuid) : assets[0].uuid);
                                        }
                                    }}
                                    allowMultiple={isMultiple}
                                />
                            </>
                        )}
                    </div>
                    {error && <InputError message={error} />}
                </div>
            );
        }
        default:
            return (
                <div>
                    <Input type="text" value={value ?? ''} onChange={e => onChange(e.target.value)} disabled={processing} />
                    {error && <InputError message={error} />}
                </div>
            );
    }
}

export default function RepeaterField({ field, value, onChange, processing, errors, project }: FieldProps) {
    const subFields: SubFieldDef[] = (field.options?.subFields as SubFieldDef[]) ?? [];
    const items: Record<string, any>[] = Array.isArray(value) && value.length > 0 ? value : [{}];

    if (subFields.length === 0) {
        return (
            <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
                <p className="text-xs text-muted-foreground py-4 text-center">
                    This repeater has no sub-fields configured. Please configure sub-fields in the Schema Builder.
                </p>
            </FieldBase>
        );
    }

    const addItem = () => {
        const newItem: Record<string, any> = {};
        subFields.forEach(sf => { newItem[sf.slug] = sf.type === 'boolean' ? false : null; });
        onChange(field, [...items, newItem]);
    };

    const removeItem = (idx: number) => {
        if (items.length <= 1) return;
        const next = [...items];
        next.splice(idx, 1);
        onChange(field, next);
    };

    const updateSubField = (idx: number, subSlug: string, subValue: any) => {
        const next = items.map((item, i) =>
            i === idx ? { ...item, [subSlug]: subValue } : item
        );
        onChange(field, next);
    };

    const onDragEnd = (result: DropResult) => {
        if (!result.destination) return;
        const sourceIdx = result.source.index;
        const destIdx = result.destination.index;
        if (sourceIdx === destIdx) return;
        const next = [...items];
        const [moved] = next.splice(sourceIdx, 1);
        next.splice(destIdx, 0, moved);
        onChange(field, next);
    };

    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <DragDropContext onDragEnd={onDragEnd}>
                <Droppable droppableId={`repeater-${field.slug}`}>
                    {(provided) => (
                        <div ref={provided.innerRef} {...provided.droppableProps} className="flex flex-col gap-3">
                            {items.map((item, idx) => (
                                <Draggable key={`item-${idx}`} draggableId={`${field.slug}-item-${idx}`} index={idx} isDragDisabled={processing}>
                                    {(dragProvided, snapshot) => (
                                        <div
                                            ref={dragProvided.innerRef}
                                            {...dragProvided.draggableProps}
                                            className={`border rounded-lg p-4 bg-muted/10 transition-shadow ${snapshot.isDragging ? 'shadow-lg ring-2 ring-primary/20' : ''}`}
                                        >
                                            {/* Item header */}
                                            <div className="flex items-center justify-between mb-3">
                                                <div className="flex items-center gap-2">
                                                    <div {...dragProvided.dragHandleProps} className="cursor-grab text-muted-foreground hover:text-foreground transition-colors p-0.5 -ml-1">
                                                        <GripVertical className="h-4 w-4" />
                                                    </div>
                                                    <span className="text-xs font-semibold text-muted-foreground">
                                                        Item {idx + 1}
                                                    </span>
                                                </div>
                                                <Button type="button" variant="ghost" size="icon"
                                                    disabled={items.length <= 1 || processing}
                                                    onClick={() => removeItem(idx)}
                                                    className="h-7 w-7 text-destructive hover:text-destructive">
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                </Button>
                                            </div>

                                            {/* Sub-fields */}
                                            <div className="flex flex-col gap-3">
                                                {subFields.map((sf) => {
                                                    const subValue = item[sf.slug];
                                                    const errorKey = subFieldErrorKey(field.slug, idx, sf.slug);
                                                    return (
                                                        <div key={sf.slug}>
                                                            <Label className="text-xs mb-1.5 block">
                                                                {sf.label}
                                                                {sf.required && <span className="text-red-500 ml-0.5">*</span>}
                                                            </Label>
                                                            <SubFieldInput
                                                                sf={sf}
                                                                value={subValue}
                                                                onChange={(val) => updateSubField(idx, sf.slug, val)}
                                                                processing={processing}
                                                                error={errors[errorKey]}
                                                                project={project}
                                                            />
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    )}
                                </Draggable>
                            ))}
                            {provided.placeholder}
                        </div>
                    )}
                </Droppable>
            </DragDropContext>

            {/* Add item button */}
            <div className="mt-3">
                <Button type="button" variant="outline" size="sm"
                    onClick={addItem} disabled={processing}
                    className="w-full">
                    <Plus className="h-4 w-4 mr-1" />Add item
                </Button>
            </div>

            {/* Top-level repeater error */}
            <InputError message={errors[`fields.${field.slug}`]} />
        </FieldBase>
    );
}
